# Memory-analysis coverage survey across real PHP apps (2026-05)

Goal: drive the practical coverage of `inspector:memory:dump` /
`inspector:memory:report` toward 100% by running it against a
spread of real-world PHP apps from GitHub, recording the gaps reli
exposes, and noting wasteful-memory shapes worth feeding back to
upstream projects.

This is a survey, not a roadmap. Findings are worth re-checking before
committing to a fix — the report numbers ("% analyzed", "Heap …")
shift between releases.

## Method

Targets bootstrapped to a stable peak, then `sleep(120)`; reli attaches
non-destructively. Each target was run twice through the pipeline:

- **path 1 — live**: `inspector:memory -p PID -f rmem -o T.live.rmem`
- **path 2 — offline**: `inspector:memory:dump -p PID -o T.rdump`
  followed by `inspector:memory:analyze -f rmem -o T.analyzed.rmem T.rdump`

Both `*.rmem` snapshots were then fed to
`inspector:memory:report --output=...` and the two reports
diffed. Capture scripts: `/tmp/reli-memprobe/` (probe.sh + hold-*.php)
on the survey host.

PHP target: 8.4.19 NTS, NTS-only sandbox. dockerd was not used —
all targets ran natively against the host PHP so cold-attach paths
exercised libphp-baked-into-the-php8.4 binary, not an external
libphp.so.

## Targets and headline numbers

`memory_get_usage(true)` is what the target itself reports;
`Heap` / `% analyzed` are reli's. Each row shows the live path first,
then the offline path.

| Target              | mgu(true) | Heap (live)  | % analyzed (live)  | Heap (analyzed) | % analyzed (analyzed) |
| ------------------- | --------- | ------------ | ------------------ | --------------- | --------------------- |
| laravel (artisan)   | 24.00 MB  | 24.68 MB     | **105.8 %** (!)    | 22.64 MB        | 97.0 %                |
| symfony-demo (boot) | 40.50 MB  | 30.67 MB     | 94.7 %             | 27.63 MB        | 85.3 %                |
| composer (bootstrap)| 12.00 MB  |  9.95 MB     | 97.0 %             |  8.94 MB        | 87.2 %                |
| phpstan (container) | 44.00 MB  | 29.98 MB     | 88.6 %             | 27.09 MB        | 80.1 %                |
| doctrine-entities*  | 12.00 MB  | 10.21 MB     | 88.6 %             |  5.65 MB        | **49.0 %** (!)        |
| json-config*        | 40.00 MB  | 29.26 MB     | 100.0 %            | 27.40 MB        | 93.6 %                |
| wordpress (early)   |  6.00 MB  |  3.65 MB     | 81.9 %             |  3.27 MB        | 73.4 %                |

\* synthetic stress-cases — see `hold-doctrine-entities.php` and
   `hold-json-config.php`. Everything else is a real GitHub project
   bootstrapped to its first idle moment.

## Reli gaps surfaced by this survey

The two paths *should* converge on the same numbers — the dump format
is supposed to be a faithful snapshot of what the live path sees.
They don't. The deltas are big enough that current reports are not
trustworthy as ground truth for "how much of the heap is reli
covering"; they're trustworthy as "how much reli covered on this
particular path." Until parity is restored, treat the live number
as the optimistic bound and the offline number as the pessimistic
bound; the truth is somewhere between.

### G1. Overview "Heap" figure disagrees with the rest of the report

> **Status: fixed on `claude/fix-report-feature-Guu6o` (commit `7d193c3`,
> `fix(memory-report): make Overview Heap/% analyzed match the rest of
> the report`).** The fix sources Heap from
> `SUM(memory_usage) over location_types_summary` (the per-type table
> that was already byte-identical between live and offline) and uses
> `memory_get_usage(false)` as the denominator. Re-running the report
> command from that branch on every captured `.rmem` in this survey
> reproduces the convergence target-for-target — see "Verification
> after fix" at the end of this section. The investigation below is
> kept as a record of how the gap was characterised.

Headline numbers across the surveyed targets:

| Target            | Δ Heap        | Δ % analyzed |
| ----------------- | ------------- | ------------ |
| laravel           | -2.04 MB      | -8.8 pp      |
| symfony-demo      | -3.04 MB      | -9.4 pp      |
| composer          | -1.01 MB      | -9.8 pp      |
| phpstan           | -2.89 MB      | -8.5 pp      |
| doctrine-entities | **-4.56 MB**  | **-39.6 pp** |
| json-config       | -1.86 MB      | -6.4 pp      |
| wordpress         | -0.38 MB      | -8.5 pp      |

Naive read: the offline pipeline is dropping data. **That's wrong.**
A focused replay on `hold-doctrine-entities.php` (target parked in
`sleep(120)`, no userland allocations possible) makes the actual
shape clear:

| capture                            | Captured at | Heap   | % analyzed |
| ---------------------------------- | ----------- | ------ | ---------- |
| `inspector:memory -f rmem` #1      | T+0 s       | 10.21  | 88.6 %     |
| `inspector:memory -f rmem` #2      | T+9 s       | 10.21  | 88.6 %     |
| `inspector:memory:dump` + analyze  | T+14 s      |  5.65  | 49.0 %     |

The two live snapshots 9 s apart are *byte-identical*, so timing
isn't a factor. But the live and offline reports for the same target
look like this when diffed:

```
$ diff de.live.report.txt de.analyzed.report.txt | wc -l
12   # three lines change: Captured-at, the Overview Heap line, and one node id
```

Every other section is **byte-identical** between the two reports:

- Type Breakdown — identical down to the row (`ZendObject 25,100 / 2.57 MB / 48.1 %`, …)
- Top Classes by Memory — identical
- ZendMM Bin Histogram including the leader line
  `ZendMM live: 10.77 MB in 70,736 small slots across 21 bin classes
  + 788.00 KB in 13 large runs (6 chunks walked)` — identical, on
  *both* sides
- Root Blame Allocation — identical including the per-root retained sums
- All findings (bottleneck_path, choke_points, cycles, dedup,
  ownership_pattern, …) — identical

So both pipelines walk the same chunks, find the same slots, build
the same node graph, and compute the same per-class / per-root
totals. The bin walker even reports `10.77 MB + 788 KB = 11.55 MB`,
which matches the target's own `memory_get_usage(true) = 12.00 MB`
to within ZendMM rounding.

The Overview line is the **only place** that disagrees:

```
live    : Heap: 10.21 MB (88.6% analyzed)   ← 1.16 MB unaccounted
analyzed: Heap:  5.65 MB (49.0% analyzed)   ← 2.88 MB unaccounted
```

The Overview `Heap` figure is computed from a separate accounting
path than the rest of the report, and that separate path is
(a) buggy enough to disagree with itself between live and offline,
and (b) the only thing the user sees first.

**The actual code path.** Both pipelines run the same formula
(`heap_memory_analyzed_percentage = zend_mm_heap_usage /
memory_get_usage_size * 100` —
`MemoryCommand.php:278`, `MemoryDumpReader.php:221`). And the
shape of `zend_mm_heap_usage` is the same five-term sum on both
sides:

```
live    (MemoryCommand.php:237):
  chunk_usage    + huge_usage    + vm_stack_total + compiler_arena_total + allocation_overhead
offline (RegionsSummary.php correctedToArray):
  db_chunk_usage + db_huge_usage + vm_stack_total + compiler_arena_total + possible_allocation_overhead_total
```

What differs is the *source* of two of those five terms:

- live's `chunk_usage` comes straight from the in-memory analyzer
  tally; offline's `db_chunk_usage` comes from `SUM(size)` over
  the `context_node_locations` table after the analyzer's results
  have been persisted to SQLite
- live's `allocation_overhead` is a single in-memory accumulator;
  offline's `possible_allocation_overhead_total` is recomputed by
  `correctedToArray` from the persisted region summary

So the live↔offline disagreement is **not** "the dump pipeline
loses heap content" (the bin-walker output proves they see the
same chunks) and **not** a structural-categorisation bug. It's the
much narrower "same five-term formula; one of `chunk_usage` /
`allocation_overhead` (or both) round-trips through the
in-memory path and the DB-SUM path with a different total." Worth
diffing the two persistence layers on a small target to identify
which term drifts and by how much.

This also constrains G2 below: laravel's `105.8 % analyzed` is the
*live* path overshooting the denominator (which is just
`memory_get_usage(true)` = 24 MB on that run). On the live side,
`chunk_usage + huge_usage + vm_stack + compiler_arena + alloc_overhead`
is summing to more than `memory_get_usage(true)`. Either the
denominator is the wrong choice (it should probably be
"total bytes ZendMM has handed out incl. internal overhead"
rather than the user-visible counter, since the numerator
includes overhead), or one of the five terms is double-counting
on the live side. Pure speculation until somebody instruments it.

**Verification after fix (`claude/fix-report-feature-Guu6o` /
`7d193c3`).** Re-rendering the report on the same `.rmem` files
captured for this survey, with the fix applied:

| Target            | live (before)    | offline (before) | live (fixed) | offline (fixed) | match? |
| ----------------- | ---------------- | ---------------- | ------------ | --------------- | ------ |
| laravel           | 24.68 / **105.8 %** | 22.64 / 97.0 % | 22.56 / 96.7 % | 22.56 / 96.7 % | ✓ |
| symfony-demo      | 30.67 /  94.7 %  | 27.63 / 85.3 %  | 27.72 / 85.6 % | 27.72 / 85.6 % | ✓ |
| composer          |  9.95 /  97.0 %  |  8.94 / 87.2 %  |  8.71 / 85.0 % |  8.71 / 85.0 % | ✓ |
| phpstan           | 29.98 /  88.6 %  | 27.09 / 80.1 %  | 26.87 / 79.4 % | 26.87 / 79.4 % | ✓ |
| doctrine-entities | 10.21 /  88.6 %  | **5.65 / 49.0 %** |  5.34 / 46.4 % |  5.34 / 46.4 % | ✓ |
| json-config       | 29.26 / 100.0 %  | 27.40 / 93.6 %  | 27.08 / 92.6 % | 27.08 / 92.6 % | ✓ |
| wordpress         |  3.65 /  81.9 %  |  3.27 / 73.4 %  |  2.98 / 66.9 % |  2.98 / 66.9 % | ✓ |

7/7 targets converge to one number per target across both pipelines,
laravel's >100 % is gone, doctrine-entities's 39.6 pp gap is closed.
The fixed numbers are mostly *lower* than the previous "live" ones
because the new accounting drops the alloc-overhead-as-numerator
component — it reports only bytes whose owner reli's pointer
tracer actually placed. This is the conservative honest reading,
and matches the framing in the fix commit
("bin walker total deliberately not used here").

**What the new "% analyzed" actually means.** With the fix, "% analyzed"
is `attributed user-visible bytes / memory_get_usage(false)`. Both
sides of that fraction are at user-byte granularity — `(false)`
returns the per-allocation user-byte sum, *not* the ZendMM slot-byte
sum (`(true)` is the OS-chunk-rounded view, which differs from
`(false)` only by chunk-end slack: 12.00 MB vs 11.52 MB on
doctrine-entities). So the gap between numerator and denominator
is literally user-byte allocations that ZendMM handed out and that
reli's typed-node tally **either missed entirely or undercounted in
size**.

For doctrine-entities that gap is 6.18 MB out of 11.52 MB —
~54 % of the live user-byte heap. Slot-count corroboration: the
bin walker enumerates 70,736 small slots, while reli's typed entries
(ZendObject 25,100 + ZendString 20,364 + ZendArray 10,111
\+ ZendArrayTable 5,111 + ZendArrayTableOverhead 5,106 = 65,792)
cover ~93 % of the slots — so the gap is mostly *size* drift, not
*count* drift.

**Verifying with the bin-walk + peek workflow.** Reli already ships
the tools to investigate this. `inspector:memory:bin-query` lists
sample addresses per bin together with the bin walker's inferred
shape, and `inspector:memory:peek --rdump` reads raw bytes back from
the dump file at any address. Running both on doctrine-entities's
bin 16 (320 B × 15,006 slots, the largest single contributor):

```
$ reli inspector:memory:bin-query -b 16 --shape='zend_string(len=9)' …
  zend_string(len=9)  [MEDIUM]  count=14822  orphan=0  reachable=14822
    0x00007f1c8686b780 …

$ reli inspector:memory:peek --rdump=de.dump.rdump -a 0x7f1c8686b780 -l 96
  00007f1c8686b780  01 00 00 00 16 00 00 00  00 00 00 00 00 00 00 00
  00007f1c8686b790  09 00 00 00 00 00 00 00  53 4b 55 2d 30 30 30 30   ........SKU-0000
  00007f1c8686b7a0  30 00 00 00 00 00 00 00  00 00 00 00 00 00 00 00   0...............
  00007f1c8686b7b0  …                                                    [256 B of zeros]
```

That's a real `zend_string` (refcount=1, type_info=0x16 = IS_STRING,
len=9, content `SKU-00000\0`) sitting in a 320 B bin slot with
~287 B of slot tail unused. These are doctrine-entities's
`sprintf('SKU-%05d', $j)` × 15 000 results: PHP's `spprintf` allocates
through `smart_str`, which ends up with a 320 B-class capacity even
when the final string is 9 chars, and that capacity becomes the
backing allocation of the resulting `zend_string`. The `reachable`
column from bin-query confirms reli's pointer tracer *did* find
all 14 822 of them (`orphan=0`); they're listed in the typed-node
tally. The accounting bug is that **reli's `ZendString` size is
computed as `header (24) + len + 1`** (so 34 B per SKU, 477 KB
total) **instead of the actual ZendMM allocation size** (320 B per
SKU, 4.52 MB total). Per-string undercount: ~287 B; total undercount
on this single bin: **~4.2 MB**, roughly two-thirds of the entire
6.18 MB gap.

Sampling bin 11 the same way confirms the OrderLine ZendObjects sit
in 128 B slots holding 120 B of object — 8 B slot rounding × 15 000
≈ 117 KB, negligible. So the doctrine-entities gap factors
approximately as:

| component | est. bytes | basis |
| --------- | ---------- | ----- |
| `ZendString` size = `header + len + 1` ignores actual slot for sprintf-allocated strings | ~4.2 MB | bin 16, peek-confirmed |
| `DateTimeImmutable` `php_date_obj`/`timelib_time` internal struct beyond bare ZendObject | ~500 KB | unverified, plausible from bin 15 |
| ZendArray buckets + hash-bucket capacity headroom + misc | ~1.5 MB | residue |

So the canonical "is the gap reli failing to model internal classes?"
hypothesis explains roughly the second row. The first row is the
loud one — but blanket-replacing `ZendString` size with the slot
size would just shift the distortion: a normal
`zend_string_init(s, 9)` lands the string in bin 4 (40 B) with a
6 B harmless slot tail, and lumping that 6 B into "ZendString"
double-counts allocator structure as the type's own footprint.

A cleaner shape: keep the type-line at the conceptual cost, but
add a column for the *abnormal* slot tail. Concretely a per-type
three-column tally derived by joining typed-node addresses with
the bin walker's slot sizes:

| column | definition | source |
| ------ | ---------- | ------ |
| Conceptual | `header + len + 1` (today's number) | typed-node tally |
| SlotRounding | tail to the *naturally-fitting* bin (`next_bin_for(conceptual)`) | join: bin walker × node |
| OversizedTail | slot tail beyond the naturally-fitting bin | same join |

For doctrine-entities's SKU strings the table reads
`Conceptual ≈ 477 KB / SlotRounding ≈ 60 KB / OversizedTail ≈ 4.2 MB`,
making the abnormal slot-tail loud and attributable. Sum of the
three columns matches `memory_get_usage(false)` (which is itself a
slot-byte sum, not a user-byte sum, because ZendMM increments
`heap->size` by `bin_data_size[bin_num]` on every allocation), so
"% analyzed" can in principle reach 100 %.

The actionable category is OversizedTail: it surfaces
`spprintf`/`smart_str` capacity remnants, dynamic-property bags
with empty bucket headroom, and arena slack — all things the
user can reasonably investigate. SlotRounding is structural to
ZendMM and only worth aggregating for context. A minimal-effort
implementation could ship just `OversizedTail` first (no
threshold tuning needed for the line "natural bin"), bin-walker
join cost permitting.

**Refinement: ZendString slack is *certain* overhead, not
"possible".** The three-column shape above was reasoned out
from a ZendObject-shaped intuition (where we genuinely can't
tell whether a slot's tail bytes are unused rounding or
internal-class struct content we don't know how to read).
ZendString is a different case — its layout is completely
closed (`header (24) + len + 1`), so any byte beyond that in
the slot **cannot** be content. Today's `RegionAnalyzer`
accumulates a `possible_allocation_overhead_total` whose name
reflects ZendObject-style uncertainty; for ZendString that
"possible" qualifier is unwarranted, the slack is definitively
overhead.

So the right shape splits the per-type residue by whether the
type has a closed layout:

| type                          | residue is | how to count it |
| ----------------------------- | ---------- | --------------- |
| `ZendString`                  | certain overhead | promote to its own type label (e.g. `ZendStringSlackOverhead`) and **add to `analyzed_percentage` with full confidence** |
| `ZendObject` (user class)     | certain overhead (`zend_object` header + properties_table is fully known) | ditto |
| `ZendArray` + `ZendArrayTable`| certain overhead (header + bucket array sizes both computable) | ditto |
| `ZendObject` (internal class) | could be unmodeled struct extension *or* overhead | keep under a `possible_*` line, *not* added to confident `analyzed_percentage` |

For doctrine-entities with the ZendString refinement applied:

```
ZendString                   1.06 MB   (conceptual)
ZendStringSlackOverhead      4.2 MB    (certain — sum to analyzed)
ZendObject + ZendArray …     5.34 MB - 1.06 MB = 4.28 MB  (today's certain content)
possible-overhead (residue)  ~700 KB   (DateTimeImmutable etc., approximate)
                            -------
                            ~10.3 MB   ≈ 11.52 MB memory_get_usage minus a small allocator-rounding gap
```

So `% analyzed` rises from 46.4 % to ~89 % once the
`ZendString` slack is correctly accounted as the
known-content-class overhead it is. The residual ~11 % is the
honest "we don't know the struct layout for these instances"
gap — much smaller and much more accurately framed.

Implementation: same bin-walker × typed-node join as the
three-column proposal, but for closed-layout types the join
result feeds straight into `analyzed_percentage` instead of
`possible_allocation_overhead_total`.

**Caveat: "1 slot = 1 logical allocation" is convention, not
invariant.** ZendMM's API doesn't forbid an allocator user
from packing multiple logical regions into one slot — it just
returns N contiguous bytes and the caller can lay out
whatever they want inside. The "certain overhead" framing
above relies on the current PHP runtime + main-extensions
convention of one principal allocation per slot, *plus*
alignment friction. For ZendString in particular, packing
something after the null terminator would need 1–7 B of
alignment padding, after which the resulting layout buys
nothing over a separate `_emalloc` for the trailing struct,
so nobody does it; but that's a usage observation, not a
structural guarantee. The known exception is internal-class
ZendObject (`php_date_obj` and friends), where additional
struct fields *are* packed inline after the bare zend_object
header — exactly why those fall in the lower-confidence tier.

A practical confidence-tier model for "promote slack to
analyzed":

| tier | applies to | analyzed-percentage handling |
| ---- | ---------- | ---------------------------- |
| A (extremely high) | ZendString slack — alignment friction makes inline packing implausible, no known extension does it in current PHP | promote to `ZendStringSlackOverhead`, count |
| B (high) | user-class ZendObject slack, ZendArray/ZendArrayTable slack — closed layout, no observed inline extension by extensions | promote to per-type `*Overhead`, count |
| C (low / unmodeled) | internal-class ZendObject slack — inline struct extension known to occur (`php_date_obj`, …) | leave under `possible_*`, don't add to confident analyzed |

The pragmatic operating rule for tiers A and B is "treat as
clean unless evidence surfaces" — there's no exhaustive proof
that no PHP/extension code ever inline-packs after a
ZendString or after a user-class ZendObject, but no example
has been spotted, so until one does the slack is taken at
face value as overhead. The safety net is an **invariant
check**: assert `sum_of_conceptual_within_slot <= slot_size`
for every joined slot. An overshoot means a typed node's
conceptual size is wrong, *or* a tier-A/B layout has gained
inline extension that reli isn't yet modelling — either way
reli should warn (and the offending type can be demoted to
tier C until reli grows a layout for it) instead of silently
emitting a negative overhead.

The `--bin-detail` flag on `inspector:memory:report` already prints
shape-detector results in `=== Per-bin Shape Detection ===` /
`=== ZendMM Periodic Groups ===`, with an `orphan/reachable` split
per shape. It is the right plumbing for a future "unaccounted
breakdown" — the column to grow is "for each `reachable` shape
group, expected typed-node bytes vs actual slot bytes", which makes
the SKU-style undercount visible directly in the report.

### G2. "% analyzed" can exceed 100 %

> **Status: fixed by the same commit as G1
> (`claude/fix-report-feature-Guu6o` / `7d193c3`).** The new accounting
> divides attributed user-visible bytes by `memory_get_usage(false)`
> (also a user-visible-byte counter), so the ratio cannot exceed 100 %
> as a side effect of slot-rounding overhead being added to the
> numerator. Verified in the post-fix table above: laravel goes from
> 105.8 % to 96.7 %.

`laravel.live` reports `Heap: 24.68 MB (105.8% analyzed)`. The
denominator (presumably `memory_get_usage(true)` = 24.00 MB on
this run) is smaller than the numerator. Either reli is double-
counting some allocation class on the live path, or the wrong
denominator is being used (probably `memory_get_usage(true)` —
which excludes ZendMM internal overhead — instead of "total bytes
ZendMM has handed out").

Until this is fixed, the "% analyzed" line is misleading whenever
it appears near 100 %.

### G3. The two snapshot formats aren't symmetrical

The offline-path overview line includes fields the live-path line
omits:

```
live    : memory_get_usage() … | Heap … (X% analyzed), VM stack …
analyzed: memory_get_usage() … | peak: P | RSS: R | Heap … (X% analyzed), VM stack …
```

`peak` and `RSS` are present in the analyzed report but stripped in
the live report. There's no obvious reason for that — both come
from `/proc/<pid>/status` (or the dump header). Two possibilities:

1. The live path doesn't capture them (because it reads them but
   doesn't persist them into the rmem snapshot it just wrote).
2. The live path captures them but the rmem→report pipeline
   doesn't read them back. The dump→analyze→rmem→report pipeline
   does.

Either way, `inspector:memory -f rmem` should produce a snapshot
that round-trips through `inspector:memory:report` with the same
fields as `inspector:memory:dump` → `…:analyze -f rmem` → `…:report`.

### G4. Node IDs drift between live and offline snapshots of an idle target

Every report ends each finding with
`Explore: rmem:explore --node=N`. For composer (target parked in
`sleep(120)`, so the graph is provably unchanged between captures):

```
live     : rmem:explore --node=33144   (cycle on $composer->locker->lockDataCache['packages'])
analyzed : rmem:explore --node=42335   (same logical cycle)
```

Same logical object — verified by both reports printing identical
type-breakdown / bin-histogram / root-blame numbers, see G1 — yet
different node IDs.

**Scope of the gap.** Across two snapshots of a target whose graph
genuinely *changed* (allocs and frees in between), node-id drift is
unavoidable: a node that didn't exist in snapshot A can't share an
id with anything; a node that was freed and whose slot was reused
is genuinely a different node. So this gap is *not* "node ids
should be stable across arbitrary snapshots." The narrow gap is:
when the same pid is captured twice with no graph mutation in
between, the two reports should still agree on which `--node=N`
points where. Today they don't, on either pipeline pair (live↔live
node-ids also drift; live↔offline drift more).

**Cheapest fix shape: surface the source address.** Within a single
process's lifetime, ZendMM does not relocate live allocations — a
node at virtual address `0x7f1a4b3e0a40` in snapshot A is the same
allocation at the same address in snapshot B, *if it's still alive*.
Reli already reads from those addresses to build the snapshot in the
first place, so it has the data; it just doesn't surface it in the
report. The minimum change is:

- print the source address alongside the node id in each finding —
  `Explore: rmem:explore --node=33144  (@0x7f1a4b3e0a40)`
- accept `rmem:explore --addr=0x…` as an alternative lookup key

Then "is this the same allocation as last time?" is a simple grep
across two reports. No content-hashing, no graph-keyed identifier,
no schema migration. Newly-allocated or freed nodes are obvious
(address present in only one of the two reports), as they should be.

The footgun is cross-process misuse — addresses from snapshot of
pid A mean nothing in snapshot of pid B. The snapshot header
already carries the pid and capture time; `rmem:explore --addr=…`
can refuse to resolve when the user mixes snapshots from different
pids (or, more realistically, from the same pid but across an
exec/fork boundary detected by start-time).

This is a usability gap, not a correctness one; worth doing only
if reli wants stable diffing on top of `inspector:memory:compare`.

**Out of scope: cross-execution comparison.** Source addresses
are stable within one process's lifetime but not between separate
runs (ASLR randomises the base, allocation order shifts with tiny
non-determinisms). For comparing yesterday's run to today's, or
two builds of the same app, the only realistic options are:

- **graph-structural matching** — most semantically faithful.
  Pure subgraph isomorphism *is* NP-hard, but heap-graph matching
  isn't pure: every node carries a type label (class name,
  ZendString, ZendArray-of-N), every edge carries a label
  (property / array key), and the graph has natural roots
  (`class_table`, `global_variables`, …). With rich labels,
  matching reduces to "walk both rooted trees in parallel,
  match by (parent-edge label, type label, signature), recurse"
  — essentially O(n) for the parts of the heap where the labels
  are informative, which is most of it. The two real pain points
  are (a) structural duplicates (e.g., 15 000 OrderLine instances
  — give up on per-instance identity, fall back to aggregate
  matching for the cluster) and (b) hash-keyed arrays where
  insertion order shifted between runs. memlab (V8) does
  rooted-retainer-path matching, Java MAT diffs at the
  dominator-tree level, and both work in practice. Heavy to
  implement, but it isn't the complexity-theoretic monster the
  "NP-hard" label suggests.
- **aggregate comparison** — what `inspector:memory:compare` does
  today: per-class counts, per-type byte totals, per-root retained
  sums. Lossy by construction (can say "OrderLine grew by 5 000
  instances" but not "*these specific* instances did") but robust
  and cheap.
- **path-from-root comparison** as a middle ground. Works for
  ordered access paths (`$customers[42]->orders[3]->lines[1]`)
  but breaks for hash-keyed containers whose order depends on
  insertion sequence and rehash thresholds, and for `ObjectsStore`
  slot numbering.

Reli's existing aggregate compare is the right shape for
cross-execution; the address-based identity above is the right
shape for same-process. They solve different problems and
shouldn't be conflated.

### G5. "Only X% of heap analyzed — Y MB unaccounted" has no breakdown

When reli warns that some of the heap is unaccounted-for, it gives
the byte count and stops. The report has every other ingredient to
say *which* bytes:

- the ZendMM bin walker has already enumerated every live slot
- `inspector:memory:dump:inspect` knows the memory map
- the unaccounted bytes must be in some union of (chunk-internal
  free space, large-allocation runs reli skipped, anonymous mmap
  regions reli didn't reach into, glibc heap, FFI/extension
  allocators)

A short "Unaccounted regions" section listing
`(start, end, source, byte count)` for every unattributed range
would turn the current "you're missing 1.16 MB somewhere" warning
into something the user can action. Worth a paragraph in
`docs/internals/memory-report-architecture.md`.

**Caveat — only works on stopped, single-snapshot capture.** A
breakdown is only definable when the bin walker and the graph
walker see the same graph. That holds for the default
`--stop-process` case (this whole survey was taken that way). It
does **not** hold for:

- `--no-stop-process` snapshots, where the target keeps allocating
  during the walk and an "unaccounted" slot may simply be one that
  was alive during one phase and freed during the next. There is
  no `(start, end)` to point at.
- Multi-snapshot diff workflows (`inspector:memory:compare`,
  future drift-over-time views), where the question
  "where did the X MB drift go?" only has an answer for nodes
  that *survived* between the two snapshots; for nodes that were
  born or freed in the gap, the byte movement is real and
  unlocalisable, not a reli bug.

So the breakdown idea only fully fixes the stopped, single-snapshot
case. For the live-running and diffing cases the right behaviour is
probably to caveat the warning ("Y MB unaccounted, of which up to
W MB may be due to allocator activity during the walk") rather than
promise a breakdown that can't exist.

### G6. "Heap" denominator vs. `memory_get_usage()` is undocumented

For doctrine-entities the report says
`memory_get_usage(): 11.52 MB | memory_get_usage(true): 12.00 MB | Heap: 10.21 MB`.
The user is left to guess why "Heap" is *smaller* than
`memory_get_usage()` — naive intuition would have it larger
(internal overhead included). It's smaller because the bin walker
counts only allocated user bytes, not bin slot rounding; that's
fine, but it should be one line of report text, not Bayesian
reasoning by the user.

### G7. `shared_fanin` rows show `?` for unresolved class names

```
[shared_fanin] Symfony\Component\Console\Command\HelpCommand::$name -> ? (11,812 refs -> 2,844 targets, 4.2 each)
[shared_fanin] filename -> ? (2,596 refs -> 210 targets, 12.4 each)
[shared_fanin] doc_comment -> ? (626 refs -> 155 targets, 4.0 each)
[shared_fanin] name -> ? (5,303 refs -> 1,033 targets, 5.1 each)
```

Every `?` here is a missed resolution. The high-fanin string-target
case (e.g., `name -> ?` with thousands of pointing references) is
almost certainly `ZendString` — which is a known, intentionally
unresolved type, but rendering it as `?` is just a typo in the
formatter. Pick a stable rendering ("&lt;ZendString&gt;",
"@string", whatever) so users can tell "reli knew but suppressed"
from "reli didn't know."

### G8. `Top Arrays` row #0 (`interned_strings`) and similar pseudo-roots have no gloss

Every report's `Top Arrays` lists pseudo-roots like
`global_variables`, `interned_strings`, `class_table`,
`function_table`, `objects_store` without explanation. For users
who haven't read the ZendMM internals, "interned_strings 43.58 KB"
is a footnote without a footnote. A one-line per-pseudo-root
description (or a markdown anchor at the top of the report
pointing to `docs/memory/memory-report.md`) would cover this.

The same pseudo-root also surfaces in the Findings block (e.g.,
`choke_point: ArrayHeaderContext (56 B shallow) holds 2.30 MB via 2 children — interned_strings`)
where the "small object retaining huge subtree" framing is
factually correct but misleading: `interned_strings` isn't
collectable. The advice ("Releasing this object would free
2.3 MB; Check if this is a container that can be bounded or
streamed") is wrong for this case. The pseudo-root list should
suppress the choke_point finding for known unbounded-by-design
roots, or at least caveat it.

### G9. `cycle_cluster` `Per cycle:` (no class list) for hash-table cycles

Several reports (laravel, composer, json-config) emit:

```
[LOW] 858.45 KB impacted
  cycle_cluster: 3 identical cycles (0 classes, 1.95 KB shallow, 858.45 KB retained)
  Per cycle: 
  Example: $composer->locker->lockDataCache['packages']
```

`Per cycle:` is empty (zero classes) because the cycle is composed
of nested arrays, not objects. The per-cycle line should at least
say something like `(arrays-only cycle)` or print the back-edge
shape (e.g., `$root[k]['parent']` ↔ `$root[k]`) so the user
can act on it.

### G10. Cold attach starts the rmem analyzer overhead clock late

`inspector:memory -f rmem` (live) on json-config took 33 s wall;
`inspector:memory:dump` + `inspector:memory:analyze -f rmem` on the
same target took 0 s + 19 s = 19 s. The live path is paying
~14 s extra for the same analysis. Probably the analyzer is
doing work between samples that the offline path skips, or the
live path holds the target stopped longer than needed. Either way,
for big heaps the offline path is a factor of ~1.7 faster end-to-end.

### G11. WordPress dies before the heap gets interesting

WordPress can't be bootstrapped to a representative idle state
without a working DB; with no DB, `wp_check_php_mysql_versions()`
calls `wp_die()` at line 354 of wp-load.php. Our hold script
intercepted via `register_shutdown_function`, so reli captured
*post-die* state — heap is 3.65 MB, 18.1 % of it unaccounted.
That coverage gap is plausibly WP's `$GLOBALS` superglobal
machinery that reli's symbol-table walker doesn't enter (the
WP-specific cases are `$GLOBALS['wp_locale']`, `$GLOBALS['wpdb']`).

For a fairer WP profile in future surveys: spin up
`mariadb` in dockerd (already started in this sandbox per
`CLAUDE.md`), `wp-cli core install`, then attach. Out of scope
for this pass.

## Wasteful memory observed in the surveyed apps

These are observations about the *targets*, not reli. Each is the
shape reli's report flagged most prominently for that target.

### Laravel (idle artisan list, ~24 MB)

- **`ComposerStaticInit*::classMap` is 1.56 MB of class-name strings**,
  including PHPUnit's entire `XmlConfiguration\Remove*Attribute`
  family. A composer-optimized autoloader baked at deploy time
  with `--no-dev` would drop this. The `app->classMap` finding
  ranks first in `bottleneck_path`.
- **`Carbon\Carbon` class definition retains 1.01 MB on its own.**
  Macroable methods + the gigantic translations array on
  `Carbon\CarbonInterval` together make Carbon one of the heaviest
  *single classes* in any Laravel process. Moving locale loading
  to lazy `__call`-time would chop most of this.
- **Symfony Console helper-set cycle** (`HelperSet ↔ helpers[*]`)
  — small in bytes (688 B per cycle) but present in *every*
  Symfony Console-using process surveyed (laravel, symfony-demo,
  composer). Switching `helperSet` to `WeakReference` would
  eliminate it cluster-wide.
- **VarDumper closure cycle** (`Illuminate\Foundation\Console\CliDumper`):
  1 cycle, 12.28 KB retained, lives forever because the Closure's
  static `dumper` references the dumper instance which references
  the registering closure. Same WeakReference fix applies.

### Symfony demo (booted dev kernel, ~30 MB)

- **`Symfony\Component\Mime\MimeTypes::REVERSE_MAP` retains 602 KB**
  inside a single class constant. The whole IANA mime map is
  baked into the class file. For a Mime-feature-using process
  this is fine; for a CLI that never sees an upload, it's pure
  dead weight in opcache + class table. A `MimeTypeGuesserInterface`-
  shaped indirection that lazy-loads the map only on first
  `guess()` would save it.
- **`ComposerStaticInit*::prefixLengthsPsr4` cycle**: not a real
  cycle in the GC sense (back-edge is via the static property),
  but reli flags it. Minor.

### Composer (bootstrap with own composer.json, ~10 MB)

- **`$composer->locker->lockDataCache` keeps 670 KB of decoded
  composer.lock alive forever** (`packages` 286 KB +
  `packages-dev` 47 KB + the cache wrapper 336 KB). Once
  `install`/`update` is finished the cache is no longer queried
  but stays attached to the long-lived `$composer` graph. A
  post-install `unset($this->lockDataCache)` would free it.
- **Composer's local `Constraint` and `MultiConstraint` objects**:
  240 identical-shape `Constraint` (28 KB) + 133 identical
  `MultiConstraint` (17 KB). reli's `structural_duplicate`
  finding correctly flags these — they're constraint expressions
  with identical operator+version content but distinct instances
  per package edge. A constraint-intern table at parse time would
  save the lot for free.
- **`Composer\Console\Application::doRun` op_array is 47 KB on its
  own**, the heaviest single function-body in the dump. Its inner
  closure chain is the leaf of the bottleneck path.

### PHPStan (container built but no analysis run, ~30 MB)

- **`PhpStormStubsSourceStubber::$constantMap` is 1018 KB inside one
  static property**, mirrored by `JetBrains\PHPStorm\PhpStormStubsMap::constants`
  at 1.13 MB. PHPStan ships these as compile-time constants, so they
  live in the class table for the whole process even when the
  analysis target doesn't touch most of those constants. Lazy
  loading per-vendor would cut peak memory before any user code
  has been parsed.
- **971 Closure instances retain 326 KB** — flagged as
  `dominant_class`. Most are Nette DI service factories (the DI
  container's compiled output). Aggregating identical factory
  shapes is theoretically possible but probably not worth the
  Nette-specific work.
- **14-class container cycle through `compiler` references**:
  `Nette\DI\Autowiring ↔ Nette\DI\ContainerBuilder` plus 14
  extension classes. 1.41 MB retained. This is the build-time
  graph that should be torn down once the container is compiled,
  but PHPStan keeps the original `Compiler` around for runtime
  reflection.

### json-config (synthetic 20 k JSON-decoded rows, ~29 MB)

- **`json_decode($json, true)` does not intern repeated keys or values.**
  60 k arrays each have a fresh `id`, `name`, `enabled`, `tags`,
  `created_at`, `extra` ZendString. With 20 k rows, that's at minimum
  120 k duplicate key strings for what should be 6 unique keys.
  The bin histogram shows 241 k slots at 32 B (`ZendString small`)
  driving 7.36 MB of the 27 MB heap. Streaming the JSON via
  `simdjson` or `json_decode` chunked into a generator avoids
  this entirely.
- **`tags: ['php','reli','memory']`** — those three string literals
  appear 20 k times because every `$row['tags']` is a freshly
  constructed array. PHP's compile-time interning never sees
  these because they came from JSON, not source. Out-of-band
  intern-pool keyed by content hash would save 20 k × 3 strings.
- **ZendMM fragmentation already pinning a chunk after just one
  decode**: `1 in-use chunk is ≥90% empty but cannot be returned
  to the OS`. Reli's `zendmm_chunks_pinned_by_fragmentation`
  finding correctly identifies the long-tail problem
  (long-lived strings scattered, blocking chunk return).

### doctrine-entities (synthetic 5 k orders × 3 lines, ~10 MB)

- **Bidirectional refs are 100 cycles totalling 4.91 MB retained**
  (every Customer.orders[*].customer points back; every
  Order.lines[*].order points back). PHP's GC will collect
  these on a `gc_collect_cycles()` call — but only if the
  user breaks every back-edge first, which is exactly what
  request-scoped frameworks try to avoid. WeakReference on the
  `customer`/`order` back-edges is the right call.
- **`Order::$createdAt` makes 5 000 `DateTimeImmutable`s** that
  reli's `companion_cluster` finding correctly pairs with
  Order. ZendMM bin 16 (320 B) carries 15 k `OrderLine` allocations.
- **`OrderLine::$order` 1:N fan-in (15 000 → 5 000 = 3 each)** —
  reli's `shared_fanin` correctly identifies that lines→order is
  shared, so collapsing OrderLine to a struct-of-fields keyed on
  the parent order would save the per-line ZendObject overhead
  (~120 B × 15 000 = 1.72 MB).
- **`sprintf` always allocates a 320 B slot regardless of result
  length** — `OrderLine::$sku = sprintf('SKU-%05d', $j)` produces
  a 9-char string but lands the resulting `zend_string` in a
  320 B bin slot (header + content occupies ~34 B, the remaining
  ~287 B is unused tail). The `sprintf` line is in **this survey's
  hand-written fixture, not in Doctrine ORM**: `doctrine-entities`
  is just a Doctrine-shaped synthetic, and the SKU formatting is
  the surveyor's choice. The PHP-runtime behaviour underneath
  *is* general, however — empirically reproduced on PHP 8.4.19
  with 7 allocation patterns × 200 strings each:

  | pattern                                | len  | actual bin     |
  | -------------------------------------- | ---- | -------------- |
  | `sprintf('SKU-%05d', $i)`              | 9    | **16 (320 B)** |
  | `sprintf('%05d', $i)`                  | 5    | **16 (320 B)** |
  | `sprintf('%032d', $i)`                 | 32   | **16 (320 B)** |
  | `sprintf('%s-%d', 'X', $i)`            | 3-5  | **16 (320 B)** |
  | `'SKU-' . str_pad((string)$i, 5, …)`   | 9    | 4 (40 B)       |
  | `str_pad((string)$i, 9, …)`            | 9    | 4 (40 B)       |
  | `number_format($i, 2)`                 | 4-6  | 3 (32 B)       |

  Only `sprintf`-derived strings end up oversized; direct
  concatenation, `str_pad`, `number_format`, and `(string)` cast
  all land in the natural bin. Cause is almost certainly that
  `spprintf` allocates through `smart_str` with a non-trivial
  initial buffer that becomes the backing capacity of the
  resulting `zend_string`. Cost on this synthetic:
  `15 000 × ~287 B ≈ 4.2 MB` of effectively unused slot tail
  for SKU strings alone; replacing the `sprintf` with
  `'SKU-' . str_pad(...)` drops that to zero on the same
  fixture.

  **Version dependence (PHP 8.2 / 8.3 / 8.4 / 8.5RC).** The same
  200-string test, measured by `memory_get_usage()` delta per
  string (including the `$keep` array bucket overhead
  ~41 B/element), across four versions on docker images
  (php:8.2-cli, 8.3-cli, 8.4-cli, 8.5-rc-cli):

  | pattern                               | 8.2.30 | 8.3.30 | 8.4.19   | 8.5.6RC3 |
  | ------------------------------------- | ------ | ------ | -------- | -------- |
  | `sprintf('SKU-%05d', $i)` (numeric)   | 361 B  | 361 B  | 361 B    | 361 B    |
  | `sprintf('%05d', $i)`     (numeric)   | 361 B  | 361 B  | 361 B    | 361 B    |
  | `sprintf('%032d', $i)`    (numeric)   | 361 B  | 361 B  | 361 B    | 361 B    |
  | `sprintf('%s-%d', 'X', $i)` (uses %s) | 361 B  | 361 B  | **73 B** | **73 B** |
  | `'SKU-' . str_pad(...)`               | 81 B   | 81 B   | 81 B     | 81 B     |
  | `number_format($i, 2)`                | 73 B   | 73 B   | 73 B     | 73 B     |
  | `'SKU-' . (string)$i`                 | 73 B   | 73 B   | 73 B     | 73 B     |

  Cross-checked against two separate 3v4l.org hand-tests:
  `sprintf('%s%d%s', 'a', 123, 'b')` is +320 B on 8.3 / +32 B
  on 8.4-8.5; **`sprintf('%d', 123)` is already +32 B on 8.4-8.5
  too**. That ruled out the simpler "8.4 only fixed the `%s`
  branch" story and forced a finer-grained drill on which format
  specifiers actually still oversize. Per-element results on
  8.4.20 / 8.5.6RC3:

  | format                              | result_len | per-element | path     |
  | ----------------------------------- | ---------- | ----------- | -------- |
  | `sprintf('%d', $i)` (no width)      | 1          |  72 B       | natural  |
  | `sprintf('SKU-%d', $i)` (no width)  | 5          |  73 B       | natural  |
  | `sprintf('%s', 'X')`                | 1          |  41 B       | natural  |
  | `sprintf('%05d', $i)` (width)       | 5          | **361 B**   | oversize |
  | `sprintf('%032d', $i)` (width)      | 32         | **361 B**   | oversize |
  | `sprintf('SKU-%05d', $i)` (width)   | 9          | **361 B**   | oversize |
  | `sprintf('%x', $i)` (hex)           | 1-2        | **361 B**   | oversize |
  | `sprintf('%f', $i)` (float)         | 8          | **361 B**   | oversize |
  | `sprintf('%.2f', $i)` (precision)   | 4-5        | **361 B**   | oversize |

  So the 8.4 fast path is narrower than "%s was fixed": **only
  `%s` and bare `%d` got fast paths**. Everything else stays on
  the `smart_str`-with-256B-initial-buffer route and oversizes:

  - any **width specifier** (`%05d`, `%5d`, `%032d`, …)
  - any **precision specifier** (`%.2f`, `%.5g`, …)
  - any formatter besides `%s` and bare `%d` (`%x`, `%o`, `%b`,
    `%f`, `%e`, `%c`, …)

  The `OrderLine::$sku` line in our fixture is `sprintf('SKU-%05d', $i)`,
  width-bearing, so it falls in the oversize bucket on every
  modern PHP through 8.5RC.

  So the precise advice is narrower still than "avoid sprintf
  for hot loops":

  - `sprintf` with only bare `%s` and/or bare `%d` formatters →
    fine on 8.4+ (oversized on 8.3 and earlier)
  - `sprintf` with width / precision / non-`%s%d` formatters →
    still oversized on 8.5RC; replace with concat + `str_pad` /
    `number_format` / `(string)` cast / `dechex` etc. for hot
    loops with long-lived results, or file an upstream patch to
    extend the 8.4 fast paths to the rest of the formatter set.

  **Cheap one-liner workaround: append (or prepend) `. ''`.** A
  3v4l hand-test surfaced that `sprintf('%05d', 123) . ''` only
  costs +32 B in `memory_get_usage` even on 8.3, where the bare
  `sprintf('%05d', 123)` costs +320 B. Confirmed across 8.3 /
  8.4 / 8.5RC for every oversize-triggering pattern in the table
  above:

  | operation                            | 8.3 / 8.4 / 8.5RC |
  | ------------------------------------ | ----------------- |
  | `sprintf('%05d', $i)`                | 361 B             |
  | `sprintf('%05d', $i) . ''`           | **73 B**          |
  | `'' . sprintf('%05d', $i)`           | **73 B**          |
  | `(string) sprintf('%05d', $i)`       | 361 B             |
  | `substr(sprintf('%05d', $i), 0)`     | 361 B             |
  | `sprintf('SKU-%05d', $i) . ''`       | **81 B**          |

  The trick is that `ZEND_CONCAT` unconditionally calls
  `zend_string_alloc(len_a + len_b)`, so concat-with-`''` forces
  a fresh, naturally-sized `zend_string` to be allocated, and
  the oversized intermediate is freed by refcount when the
  expression result is assigned. `(string)` cast and
  `substr($s, 0)` are no-ops on string arguments (refcount-bump
  the same `zend_string`) so they don't trigger the
  reallocation.

  Cost is one extra `emalloc` + `memcpy` per call, which is
  negligible compared to the ~287 B of slot tail it reclaims on
  every long-lived string. For codebases that can't easily
  rewrite their `sprintf` formats but want to fix the memory
  cost, dropping ` . ''` after every long-lived `sprintf(...)`
  is a localised and reviewable change.

  **How often this matters in real code is an open question.**
  This survey did not catch a sprintf-driven OversizedTail in
  the four real GitHub apps (laravel, symfony-demo, composer,
  phpstan), only in the surveyor's fixture. The pattern only
  manifests when the result is *long-lived* (retained for the
  process lifetime); short-lived sprintf in log lines, error
  messages, and per-request display strings is freed back to
  the bin and never shows up in a steady-state snapshot.
  The places to look for real instances are: ID-dictionary or
  cache-key pre-generation, permanent in-process lookup tables,
  formatted-name caches in long-lived workers. A future reli
  with the OversizedTail column from G1 would surface them
  without prior knowledge of where the sprintf calls live;
  pending that, this is the loudest *concrete* example of the
  OversizedTail category the survey produced.

### WordPress (bootstrapped to wp_die, ~3.6 MB)

- **`function_table → remove_accents → op_array` is the heaviest
  single function body in the dump** (36 KB op_array, 31 KB
  doc_comment). The function carries the entire UTF-8
  transliteration table inline. Splitting that map into a
  `wp_get_accents_map()` lazy-loader would shrink WP's compiled
  function table by ~30 KB at near-zero runtime cost.
- **`wpdb` instance is 1 KB** — small in absolute terms but
  represents 45.7 % of object memory because we caught WP before
  it loaded any other userland classes; it's the canonical "first
  object every WP request creates" and stays for the lifetime of
  the request.

## Loose ends

- The composer-src target's "`$composer->locker->lockDataCache`
  cycle" finding is flagged 3 times (`3 identical cycles`) for what
  is logically one structure — looks like reli is finding three
  back-edges into the same conceptual graph. Worth confirming the
  detector doesn't over-count when one root has multiple
  re-entries.
- Reli's report does not surface **opcache-occupied bytes** that
  would be in shared memory in a real SAPI deployment. Every CLI
  process surveyed is paying for op_array bodies (`ZendOpArrayBody`
  is the dominant type in 5 of 7 targets) that, in fpm/franken,
  would be in opcache and shared. A "if opcache were enabled, this
  would shrink to" estimate would be a useful new finding for
  CLI-vs-SAPI sizing decisions.
- WordPress's representative state was not captured. Re-run with
  a real database next pass.

## Reproducing this survey

The probe scripts and report outputs are not committed. They live
at `/tmp/reli-memprobe/` on the survey host:

```
apps/                        # cloned PHP apps (laravel, symfony-demo, …)
hold-*.php                   # per-target bootstrap-and-sleep harnesses
probe.sh                     # generic dump+analyze+report driver
dumps/   logs/   reports/    # output directories
```

`probe.sh <name> hold-<name>.php` runs the full pipeline. Each run
takes 10–30 s of wall after the target has bootstrapped. Cold
attach to the host PHP binary is a one-time ~3 s tax per survey
session; subsequent attaches hit the binary-analysis cache.
