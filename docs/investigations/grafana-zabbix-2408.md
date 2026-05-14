# grafana-zabbix #2408: Problems panel response size blow-up — reli investigation

Upstream issue: https://github.com/grafana/grafana-zabbix/issues/2408

The reporter saw Zabbix API responses for grafana-zabbix's Problems panel
grow from manageable sizes (6.3.0) to **113–362 MB** (6.3.1/6.3.2) in a
~7,360-host environment, exceeding Grafana's 104.8 MB gRPC message
limit and exhausting PHP memory on the Zabbix side. The release notes
attribute the change to "resolving operational data and item values at
problem creation timestamps rather than using current values."

This note pins what 6.3.1/6.3.2 actually added by diffing the plugin
source between v6.3.0 and v6.3.2, and then takes a reli `memory:dump`
of the Zabbix PHP frontend serving each version's API call mix. The
two dumps are compared with `inspector:memory:compare`, which shows
the regression's memory cost directly.

## What changed in grafana-zabbix between 6.3.0 and 6.3.2

`git diff v6.3.0..v6.3.2 -- src/datasource/zabbix/zabbix.ts` adds a
new pipeline stage `enrichProblemsWithItemHistory()` to both
`getProblems()` and `getProblemsHistory()`. The interesting body:

```ts
private async enrichProblemsWithItemHistory(problems: ProblemDTO[]) {
  const allItems = _.uniqBy(problems.flatMap((p) => p.items || []), 'itemid');
  const itemsWithType = allItems.filter((item) => item.value_type !== undefined);

  const timestamps = problems.map((p) => p.timestamp);
  const timeFrom = Math.min(...timestamps) - 3600;        // 1h lookback
  const timeTill = Math.max(...timestamps) + 60;
  const history = await this.zabbixAPI.getHistory(itemsWithType, timeFrom, timeTill);
  // ...substitutes {ITEM.VALUE}/{ITEM.LASTVALUE} from `history`...
}
```

* **One `history.get` call** is issued for the union of items across
  all currently-active problems.
* **The time window** is `min(timestamps) - 1h` to
  `max(timestamps) + 1m`. With long-running unresolved problems
  this trivially spans hours, days, or longer.
* `history.get` returns **every recorded data point** for every item
  inside that window. There is no per-problem narrowing, no LIMIT,
  no aggregation — it's the raw history table.

So at 7,360 hosts × N items each × an hours-long-to-days-long window,
this single API call materialises **millions** of `(itemid, clock,
value, ns)` rows on the Zabbix PHP side. The 113–362 MB response is
this history.get payload, not the `problem.get` one.

Note: the `selectItems` change on `trigger.get` (adding
`'value_type'`) in the same release is what feeds the
`itemsWithType` filter above — both diffs are part of the same
feature.

## Reproduction (scaled down)

Zabbix 6.0.46 stack via docker compose (`mysql:8.0`,
`zabbix-server-mysql:alpine-6.0-latest`,
`zabbix-web-apache-mysql:alpine-6.0-latest`, PHP-FPM 8.3 inside the
web container). 80 hosts, 1 trapper item + 1 trigger per host.
5,000 synthetic problem rows are seeded into `events` / `problem` /
`problem_tag`. **160,000 synthetic `history_uint` rows** are seeded
across the last 24 h (`2000 points × 80 items`), so that a
`history.get` over the wide window the plugin builds returns a
meaningful payload.

* `problem.get` (with `output:"extend"`, `selectTags`,
  `selectAcknowledges`, `selectSuppressionData`): **20 MB** JSON,
  ~2.5 s — this is the 6.3.0 baseline shape.
* `history.get(itemids=[…80 items], time_from=now-25h,
  time_till=now+1m, output:"extend")`: **11.5 MB** JSON,
  160,000 rows, ~0.5 s — the new call 6.3.2 added.

Two orders of magnitude smaller than the upstream report, but the
same shape.

## Comparing the two dumps

Memory captures of the same PHP-FPM worker pool while each path
runs:

```text
# 6.3.0 shape — problem.get only
php ./reli inspector:watch -P 'php-fpm: pool zabbix' \
    --cpu-usage=50 --action=memory-dump \
    --action-output-dir=/tmp/zbx/cmp \
    --max-triggers=1 \
    --php-version=v83 --php-regex='php-fpm83$'
# … fire problem.get a few times …

# 6.3.2 shape — same, but with history.get firing
# … same watch invocation, then fire history.get in a loop …

# Convert raw dumps to .rmem for compare
php ./reli inspector:memory:analyze v630_problem.rdump -f rmem -o v630_problem.rmem
php ./reli inspector:memory:analyze v632_history.rdump -f rmem -o v632_history.rmem

# Diff
php ./reli inspector:memory:compare v630_problem.rmem v632_history.rmem
```

Comparison output, abridged:

```text
=== Summary Delta ===
  memory_get_usage()             18.40 MB → 111.23 MB    +92.84 MB (+504.6%)
  memory_get_usage(true)         20.00 MB → 129.93 MB   +109.93 MB (+549.7%)
  Heap usage                     15.84 MB →  86.86 MB    +71.02 MB (+448.3%)
  RSS                            27.50 MB → 138.67 MB   +111.17 MB (+404.3%)

=== Location Type Delta ===
  ZendArrayTable             +146,482  → +24.99 MB
  ZendArrayTableOverhead     +146,506  → +19.79 MB
  ZendString                 +583,534  → +16.07 MB
  ZendArray                  +146,497  →  +7.82 MB

=== Findings Diff ===
  + [HIGH]   81.77 MB  choke_point: $jsonRpc->_response[0]['result']  (160,000 children)
  + [MEDIUM] 83.33 MB  large_array: 160,000 elements — $jsonRpc->_response[0]['result']
  + [WARN]    4.00 MB  zendmm_chunks_pinned_by_fragmentation: 2 in-use chunks ≥90% empty
  - [HIGH]    5.36 MB  choke_point: CProblem::addRelatedObjects()::$result (5,081 children)
  - [MEDIUM]  3.43 MB  large_array: 5,081 elements — CProblem::addRelatedObjects()::$problems
```

Read top-down:

* The PHP frontend's live emalloc jumps from 18 MB to 111 MB
  (`memory_get_usage`). The OS-level RSS jumps from 27 MB to 139 MB.
  The `memory_limit` budget consumed grows from 20 MB to 130 MB.
* The structural cost is overwhelmingly **160,000 small arrays**:
  one ZendArray + 4 hash buckets per history row, each row holding
  4 zend_strings (`itemid`, `clock`, `value`, `ns`). Bin-shape
  detection puts +144,659 `zend_string(len=10)` (the `clock`
  timestamps), +149,932 `zend_string(len=5)` (the values),
  +130,740 `zend_string(len=4)` (short keys / itemids), all in the
  small bins.
* The chokepoint diff names the responsible object explicitly:
  `$jsonRpc->_response[0]['result']` — the `CHistory::get` return
  array. The `CProblem::addRelatedObjects()` chokepoint visible in
  the 6.3.0 baseline disappears because the dump was taken inside
  `CHistory::get` rather than `CProblem::get`.

**Amplification factor for the new call:** the JSON response is
11.5 MB, the PHP-side cost to build it is ~83 MB of array
structures + ~16 MB of zend_strings ≈ **7× the wire size**, plus
fragmentation cost (the `zendmm_chunks_pinned_by_fragmentation`
warning).

## Implication at the reporter's scale

A 113 MB `history.get` response from the reporter's environment
(7,360 hosts, multi-hour problem ages) would, on the same
amplification ratio, hit ~800 MB of live PHP-side emalloc; a 362 MB
response would land in multi-GB territory. Zabbix's default
`memory_limit` of 384M is well below either. The reporter's "PHP
encountered memory allocation failures" message is this
amplification multiplied by their dataset size — not the JSON
serialization itself.

## What the prior reli profile of `problem.get` showed (and why
## it isn't the regression)

The first dump in this investigation was taken while a `problem.get`
with `output:"extend"` was running. That dump showed
`CMacrosResolverHelper::resolveTriggerOpdata` and
`resolveTriggerDescriptions` consuming 48% inclusive CPU time each,
and 5,081 children retained at `CProblem::addRelatedObjects()`.
**That hot path also exists in 6.3.0** — both versions call
`problem.get` with similar parameters, and `selectTags=extend`
doesn't change between releases. It is an independent, pre-existing
cost of the Problems panel, **not the 6.3.1/6.3.2 regression**. The
`memory:compare` output above puts that conclusion on solid
footing: the baseline-only findings are the macro-resolver
chokepoint, the target-only findings are the new `history.get`
result, and the +93 MB delta is entirely on the target side.

## Mitigations

### grafana-zabbix side (the actual fix lever)

1. Narrow `history.get`'s scope:
   * Per-problem queries with `time_from = problem.timestamp` and
     a small surrounding window (~1 polling interval, not 1 h
     lookback over the union).
   * `limit` per item.
   * `lastvalue` from the trigger's own items (already in the
     `trigger.get` response — no second round trip needed for
     `{ITEM.LASTVALUE}` resolution).
2. Cap the eager fetch and fall back to lazy / on-hover resolution
   for the rest. The Problems panel renders a table; macro
   substitution per visible row is what users see, not the
   thousands of off-screen ones.
3. Page `problem.get` results (the reporter's suggestion).
4. If `enrichProblemsWithItemHistory` must remain global, batch by
   `value_type` and parallelize against the API.

### Zabbix side (defense in depth)

1. `CHistory::get`'s output assembly is a one-pass DB scan that
   materialises every row as a PHP array of small zend_strings.
   The amplification factor (7×) is intrinsic to how PHP holds
   integer-like values as zend_string by default; converting
   numeric history values to PHP int / float at result-assembly
   time (where the column metadata makes the type known) would
   cut the per-row footprint substantially.
2. Streaming output. `api_jsonrpc.php` builds the full result tree
   in memory before encoding; for large `history.get` payloads, a
   streaming JSON encoder would let the row arrays be released
   incrementally rather than retained until response send.
3. `gc_mem_caches()` at the end of expensive endpoints to release
   cached ZendMM chunks proactively.

## Memory limit nuance

`memory_limit` is enforced inside `zend_mm_alloc_pages`
(`Zend/zend_alloc.c`), only when ZendMM needs a fresh OS chunk —
i.e. only on cache miss. Pulls from `heap->cached_chunks` short-
circuit before the limit comparison, so emalloc'ing into pre-cached
chunks never raises a memory_limit error, no matter how high
`real_size` already is. When the cache is empty, the comparison is
`ZEND_MM_CHUNK_SIZE > heap->limit - heap->real_size`; on failure,
`zend_mm_gc(heap)` walks live chunks and rescues those whose page
maps are entirely free. The "Allowed memory size of %zu bytes
exhausted" fatal only fires when (a) the cache is empty AND (b) gc
finds no fully-empty live chunk to recycle. This is why the macro
resolver path — which churns short-lived zend_strings that empty
their pages on a predictable rhythm — does not trip the limit in
practice, while the history.get path — which builds one giant
array structure and holds it until `json_encode` runs — is the
genuine risk.

## Reli usage notes uncovered on the way

* On Alpine `php-fpm83` (no separate `libphp.so` — PHP is statically
  linked into the FPM binary), `--php-version=v83
  --php-regex='php-fpm83$'` is needed; auto-detection times out
  silently on a busy idle worker. The first cold attach against a
  fresh binary spends visible CPU parsing the embedded symbol table
  before any sample appears.
* `inspector:trace` against an idle PHP-FPM worker returns no output
  at all (no `current_execute_data`); fire the load first, then
  attach (or use `inspector:daemon` against the pool regex so the
  attach lands on whichever worker FPM picks).
* `inspector:memory:dump` against an idle FPM worker fails with
  "failed to find ZendMM main chunk" — same root cause as the
  per-request SAPI note in `CLAUDE.md`. Run the dump under
  `inspector:watch` with `--cpu-usage=N` so it fires only mid-request.
* `inspector:memory:compare` wants `.rmem` or SQLite, not raw
  `.rdump`. Run `inspector:memory:analyze -f rmem -o foo.rmem`
  first on each side.
* `inspector:memory:analyze -f report` crashed on the first dump in
  this investigation with `TypeError: strlen(): Argument #1
  ($string) must be of type string, int given` in
  `BinaryReportDataProvider::buildDedupExamples` at
  `src/Inspector/Output/MemoryOutput/Report/BinaryReportDataProvider.php:1362`.
  Tag values in real `problem.get` dumps are frequently short
  numeric strings (event IDs, item IDs, integer metrics), and PHP
  silently coerces those to int when used as array keys. Fixed in
  this branch.

## Artifacts

The repro driver scripts are checked in alongside this note under
`docs/investigations/grafana-zabbix-2408/`:

* `docker-compose.yml` — the Zabbix stack.
* `seed_via_api.py` — host / item / trigger creation via the Zabbix API.
* `scale_problems.py` — bulk insertion of synthetic problem rows.
* `seed_history.py` — bulk insertion of synthetic `history_uint` rows
  so `history.get` over the wide window returns a meaty payload.
* `run.sh` — end-to-end repro: bring the stack up, seed, fire the
  request under reli, dump, and run `memory:compare`.
