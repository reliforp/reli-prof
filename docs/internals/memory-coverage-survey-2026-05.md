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
and (b) the only thing the user sees first. The bin walker has
already established the truth (~11.55 MB) — the Overview line
should be derived from there or from the same node-set the rest
of the report is built on, instead of whatever it's doing today.

This also reframes G2 below: laravel's `105.8 % analyzed` isn't
"reli double-counts allocations on the live path", it's the same
broken Overview accounting tipping into the >100 % regime on
particular heap shapes.

#### G1 follow-up: `allocation_overhead` is the direct mechanism

After the headline-side fix landed (`fix(memory-report): make
Overview Heap/% analyzed match the rest of the report`,
0.13.x e1ae8c9..0238a04) the Overview line now derives from
`SUM(memory_usage)` over the per-type breakdown — the same
node-set everything below it uses — so the live-vs-offline
disagreement and the >100% regime are gone from the user-visible
output. But the underlying `zend_mm_heap_usage` /
`heap_memory_analyzed_percentage` summary keys are still computed
the old way and still disagree across paths; the fix only
stopped the headline from advertising the disagreement.

The mechanism, narrowed down to one term:

- **live** (`MemoryCommand::buildSummary`) takes
  `allocation_overhead = $sink->computeRegionSumsAndOverhead()['overhead']`
  — a raw row-by-row sum over the binary location temp file with
  no overlap suppression (the comment on
  `BinaryContextTreeSink::computeRegionSumsAndOverhead` is
  explicit about this).
- **offline** (`MemoryDumpReader::buildSummary` →
  `RegionsSummary::correctedToArray`) takes
  `possible_allocation_overhead_total` from
  `RegionAnalyzer::analyze()`, which feeds
  `filterOverlappingLocations()` first and only calls
  `$chunk->getOverhead()` for the survivors (with
  `ZendArrayTableMemoryLocation` excluded outright at
  `RegionAnalyzer.php:101`).

Every other input to `zend_mm_heap_usage` (`chunk_usage`,
`huge_usage`, `vm_stack_total`, `compiler_arena_total`) is
identical across the two paths because both come from the same
sink scan. The 4.56 MB doctrine-entities gap is entirely the
overhead term.

#### G1 follow-up: `zend_string` over-allocation breaks the
`getOverhead = bin_size - location_size` model

Independently of the live/offline overhead disagreement above, a
subsequent investigation surfaced a `zend_string` shape that the
overhead computation in `ZendMmChunkMemoryLocation::getOverhead`
mis-attributes:

- `ZendStringMemoryLocation::size = len + ZEND_STRING_HEADER_SIZE`
  (`getSize()` at `ZendString.php:150-153`).
- PHP, however, can allocate a `zend_string` with a buffer
  capacity larger than `len + 1`. `zend_string_safe_alloc` and
  rope/concatenation helpers reserve room for in-place growth
  before the final length is known, and the surplus capacity
  stays attached to the live string after the writer settles
  on a shorter `len` than it asked for.
- `getOverhead()` then computes `bin_size - location_size` and
  classifies the **whole** `bin_size − (len + 24)` gap as
  ZendMM slot-rounding overhead. In reality some of that gap is
  a `zend_string` buffer that PHP intentionally reserved —
  conceptually attributable to the string, not to ZendMM.

Two consequences:

1. The Type Breakdown's `ZendString` row understates the type's
   real footprint by exactly the over-allocated capacity, and the
   "Top Strings by Memory" ranking is biased against strings that
   PHP grew through realloc-friendly call paths.
2. `allocation_overhead` absorbs the over-allocated bytes and
   ends up larger than the slot-rounding-only definition implies.
   For the live path the bin walker exposes the slot size
   directly so the discrepancy is bounded; for the offline path
   `RegionAnalyzer` re-derives overhead through `getOverhead()`
   and inherits the same misattribution. Both paths agree they
   are wrong; they don't agree on *how* wrong because of the
   filter-overlap difference above.

Refinement direction (deferred to a separate PR):

- Decide on the canonical "allocated bytes" for a `zend_string`.
  Candidates:
  - `len + ZEND_STRING_HEADER_SIZE` (current — capacity-blind).
  - The bin slot the string occupies, minus a constant ZendMM
    slot-rounding term computed from the bin class. Anything
    beyond that is buffer reserved for the string.
  - A direct read of the `val` buffer's allocated capacity if
    the SAPI / runtime exposes it (e.g. via
    `zend_string_capacity()` style probes; not currently
    persisted in `zend_string` itself).
- Split `ZendMmOverheadMemoryLocation` into two sub-types so the
  report can distinguish "slot-rounding waste" from
  "type-reserved capacity that didn't end up used", or extend
  `ZendStringMemoryLocation` with a `reserved_capacity` field
  and only return slot-rounding overhead from `getOverhead()`.
- Reconcile `BinaryContextTreeSink::computeRegionSumsAndOverhead`
  and `RegionAnalyzer::analyze` so live and offline produce the
  same `allocation_overhead`; the natural fix is to make the
  binary streaming path also dedup overlapping address ranges
  rather than pull `RegionAnalyzer` toward the raw scan.
- Confirm with a reproducer whether the same shape applies to
  `ZendArrayTable` (its `getOverhead` skip at
  `RegionAnalyzer.php:101` predates this work) or whether that
  was an unrelated workaround.

### G2. "% analyzed" can exceed 100 %

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

### G4. Node IDs are not stable across snapshots of the same target

Every report ends each finding with
`Explore: rmem:explore --node=N`. For composer:

```
live     : rmem:explore --node=33144   (cycle on $composer->locker->lockDataCache['packages'])
analyzed : rmem:explore --node=42335   (same logical cycle)
```

Same logical object, different node IDs. That makes diff workflows
("did this allocation grow between snapshots?") harder than they
need to be — the user has to re-resolve the path each time. Not a
correctness bug, but a usability one if reli ever wants stable
diffing on top of `inspector:memory:compare`.

A keyed identifier (e.g. content-hash of the path-from-root, or
the target-process address mod chunk-base) would let
`rmem:explore --node=` mean the same thing across runs against
unchanged static data.

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
