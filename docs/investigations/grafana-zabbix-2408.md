# grafana-zabbix #2408: Problems panel response size blow-up — reli investigation

Upstream issue: https://github.com/grafana/grafana-zabbix/issues/2408

The reporter saw Zabbix API responses for grafana-zabbix's Problems panel
grow from manageable sizes (6.3.0) to **113–362 MB** (6.3.1/6.3.2) in a
~7,360-host environment, exceeding Grafana's 104.8 MB gRPC message
limit and exhausting PHP memory on the Zabbix side. The release notes
attribute the change to "resolving operational data and item values at
problem creation timestamps rather than using current values."

The Zabbix-side cost is the part we could observe with reli. This note
records the reproduction recipe and the profile.

## Reproduction (scaled down)

Zabbix 6.0.46 stack via docker compose (`mysql:8.0`,
`zabbix-server-mysql:alpine-6.0-latest`,
`zabbix-web-apache-mysql:alpine-6.0-latest`, PHP-FPM 8.3 inside the web
container). 80 hosts, 1 trapper item + 1 trigger per host, each trigger
carries 12 tags and a non-trivial `opdata` field. 5,000 synthetic
problem rows are inserted directly into `events` / `problem` /
`problem_tag`, each referencing one of the 80 triggers and tagged with
15 entries. A `problem.get` against this dataset returns ~20 MB of JSON
— two orders of magnitude smaller than the issue's reported figure but
large enough to show the same hotspot pattern.

## The single knob

Two variants of `problem.get`, otherwise identical:

| `output` parameter | Response size | Wall time |
| --- | ---: | ---: |
| `["eventid", "name", "clock", "severity", "objectid", "acknowledged"]` | 18.7 MB | **0.36 s** |
| `"extend"` (i.e. includes `opdata`) | 20.5 MB | **2.50 s** |

The response grows by 10 %, the request gets **7× slower**. The cost
isn't in serialization — it's in macro resolution.

## reli: CPU profile (`inspector:daemon` + `rbt:analyze`)

```text
php ./reli inspector:daemon -P 'php-fpm: pool zabbix' -T 8 \
    -f rbt -o /tmp/zbx/profiles -s 1000000 \
    --php-version=v83 --php-regex='php-fpm83$'

# ... fire several problem.get calls with output:"extend" ...

cat /tmp/zbx/profiles/*.rbt | php ./reli rbt:analyze --top=20 --no-line --crop=140
```

Self-time:

```
| count | pct    | frame                                       |
|-------|--------|---------------------------------------------|
| 1554  |  22.5% | CMacrosResolverGeneral::extractMacros       |
|  786  |  11.4% | CMacrosResolver::resolveMediaTypeUrls       |
|  669  |   9.7% | CMacroParser::parse                         |
|  586  |   8.5% | mysqli_query                                |
|  446  |   6.4% | json_encode                                 |
|  439  |   6.3% | CUserMacroParser::parse                     |
|  372  |   5.4% | mysqli_fetch_assoc                          |
|  359  |   5.2% | CMacroFunctionParser::parse                 |
|  177  |   2.6% | CProblem::addRelatedObjects                 |
```

Total-time (inclusive):

```
| 6790  |  98.1% | <main>                                            |
| 6330  |  91.5% | CJsonRpc::execute                                 |
| 5883  |  85.0% | CProblem::get                                     |
| 5823  |  84.1% | CProblem::addRelatedObjects                       |
| 3345  |  48.3% | CMacrosResolverHelper::resolveTriggerOpdata       |
| 3343  |  48.3% | CMacrosResolverHelper::resolveTriggerDescriptions |
| 3337  |  48.2% | CMacrosResolver::resolveTriggerDescriptions       |
| 3146  |  45.5% | CMacrosResolverGeneral::extractMacros             |
```

`CProblem::addRelatedObjects` calls `resolveTriggerOpdata` and
`resolveTriggerDescriptions`, each of which scans every problem's
trigger expression looking for `{ITEM.LASTVALUE}` / `{$MACRO}` /
`{HOST.*}` style references in the `opdata` and `event_name` fields,
fetches the referenced entities, and substitutes. The macro parser
(`CMacroParser::parse`, `CUserMacroParser::parse`,
`CMacroFunctionParser::parse`) is invoked per character per field
per problem — together they dominate the request.

## reli: memory snapshot (`inspector:watch --action=memory-dump`)

```text
php ./reli inspector:watch -p <fpm-worker> \
    --cpu-usage=50 --action=memory-dump \
    --action-output-dir=/tmp/zbx/dumps \
    --poll-interval=100 --cooldown=0 --max-triggers=2 \
    --php-version=v83 --php-regex='php-fpm83$'
```

ZendMM summary (`inspector:memory:analyze -f sqlite3`, table `summary`)
taken mid-request:

```
zend_mm_heap_total           = 20.0 MB
zend_mm_heap_usage           = 14.9 MB
memory_get_usage             = 17.4 MB
memory_get_peak_usage        = 17.4 MB
memory_get_real_usage        = 214 MB    <-- chunks reserved from OS
cached_chunks_size           = 194 MB
cached_chunks_count          = 97
peak_chunks_count            = 10
```

The response weighs 20 MB. The "currently allocated" PHP figure
(`memory_get_usage`) is 17 MB — the peak the engine has had to
juggle in-flight is **214 MB of chunks reserved from the OS**, of
which 194 MB is sitting in ZendMM's chunk cache. The juggled set is
intermediate strings produced during macro substitution. `bin_walk`
confirms it: the largest periodic-allocation classes in the 256-byte
bin are zend_strings whose fingerprints decode to the literal
`"operational data y..."` content from our trigger `opdata` field —
**1456 + 1411 + 815 + 765 + ... copies of essentially the same
string** still live on the heap at the dump moment. Per-problem
macro resolution allocates a fresh zend_string for every
expansion step and discards them as it goes; with thousands of
problems × tens of macro tokens per `opdata`, the chunk cache
inflates to ~10× the response size before the request returns.

Extrapolated to the issue reporter's 7,360-host environment, a
113–362 MB JSON response would correspond to a multi-GB peak chunk
cache on the Zabbix frontend — exactly the "PHP encountered memory
allocation failures" symptom in the upstream report.

## Where the cost actually lives in Zabbix

`CProblem::addRelatedObjects()` in `frontends/php/include/classes/api/services/CProblem.php`
unconditionally calls both `resolveTriggerOpdata` and
`resolveTriggerDescriptions` for every problem the API returns when
the caller's `output` includes the corresponding fields
(`opdata`, `name` when generated from `event_name`). `"extend"`
implicitly includes `opdata`. There is no "skip resolution" flag
exposed on `problem.get`.

This is why grafana-zabbix flipped the cost ratio when it started
asking for richer problem data: the new flow asks for `output:
extend` (or explicitly for `opdata`) per problem, which forces
Zabbix to run the macro resolver across the entire result set.

## Mitigations (Zabbix side, for a fix upstream)

1. `CProblem::addRelatedObjects` could memoize macro resolution per
   `(triggerid, opdata-template)` — within a single response,
   thousands of problems share the same trigger and same opdata
   template; the resolver is recomputing identical work.
2. Avoid allocating an intermediate zend_string for every macro
   expansion step. `CMacrosResolverGeneral::extractMacros` builds
   its output by repeated `str_replace`/concatenation; preallocating
   the buffer would keep the chunk cache from inflating.
3. Expose an `expandOpdata` / `expandDescription` flag (default on
   for back-compat, off for `problem.get`) so clients that don't
   need expanded operational data can opt out without dropping the
   field entirely.

## Mitigations (grafana-zabbix side)

1. Restrict `output` to exactly the columns the panel renders. The
   Problems panel doesn't visualize `opdata` directly — it can be
   omitted from the request entirely.
2. If `opdata` is needed, batch problems by `triggerid` and
   resolve once per trigger on the plugin side.
3. Page `problem.get` results (issue reporter's suggestion).

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
* `inspector:memory:analyze -f report` crashed on this dump with
  `TypeError: strlen(): Argument #1 ($string) must be of type string,
  int given` in `BinaryReportDataProvider::buildDedupExamples` at
  `src/Inspector/Output/MemoryOutput/Report/BinaryReportDataProvider.php:1362`.
  `-f sqlite3` works fine. Worth filing as a follow-up reli bug.

## Artifacts

The repro driver scripts are checked in alongside this note under
`docs/investigations/grafana-zabbix-2408/`:

* `docker-compose.yml` — the Zabbix stack.
* `seed_via_api.py` — host / item / trigger creation via the Zabbix API.
* `scale_problems.py` — bulk insertion of synthetic problem rows.
* `run.sh` — end-to-end repro: bring the stack up, seed, fire the
  request under reli.
