# Memory-analysis coverage survey — workload-driven targets (2026-05)

Companion to [`memory-coverage-survey-2026-05.md`](memory-coverage-survey-2026-05.md).
The earlier survey targeted apps at their **idle** boot point (e.g.
`artisan list`, freshly booted dev kernel). Real users don't profile
idle frameworks — and `inspector:memory:report` against an idle
target degenerates to "the class table is large", which is rarely
actionable.

This pass keeps the same method but picks 10 GitHub-hosted libraries
each driven into a meaningful **userland working set** before
attaching: ORMs with hydrated entities, spreadsheets with cells,
loggers with retained records, GraphQL with executed queries, etc.
The point is to surface gaps reli only hits when the heap is dominated
by domain objects, plus to record per-library waste shapes that
generalise to "what real apps do with these libraries in production".

## Method

Same as the idle survey. Each driver script bootstraps its target,
prints `ready pid=<pid> mem=…` once it has produced the working set,
then `sleep(120)`. Reli attaches non-destructively via
`inspector:memory -p <pid> -f rmem -o snap.rmem`, then
`inspector:memory:report snap.rmem` produces both `report` (text) and
`report-json`.

PHP target: 8.4.19 NTS (host PHP). Capture timeout 900 s (the
03_phpparser working set has ~600 k AST node objects and takes ~8
min wall to capture; everything else fits in 60 s). Driver scripts
and reports live at `/tmp/reli-memscan/` on the survey host.

## Targets and headline numbers

| #   | Driver / library                                  | mgu(true) | Heap     | % analyzed | Capture |
| --- | ------------------------------------------------- | --------- | -------- | ---------- | ------- |
| 01  | doctrine/orm — 20 k Product entities hydrated     | 42.00 MB  | 34.20 MB | 84.3 %     | OK      |
| 02  | phpoffice/phpspreadsheet — 80 k Cells in memory   | 48.00 MB  | 44.72 MB | 95.4 %     | OK      |
| 03  | nikic/php-parser — 1.6 k file ASTs                | 434.00 MB | 403.55 MB| 94.4 %     | OK (8 min wall, ~600 k AST nodes) |
| 04  | twig/twig — 200 templates compiled + rendered     | 28.00 MB  | 25.19 MB | 93.0 %     | OK      |
| 05  | fakerphp/faker (ja_JP) — 30 k stdClass records    | 66.00 MB  | 55.77 MB | 85.5 %     | OK      |
| 06  | monolog/monolog — 30 k LogRecord with closures    | 102.00 MB | 106.06 MB| **105.5 %** (W2) | OK |
| 07  | webonyx/graphql-php — 5× 5 k-user query result    | 318.00 MB | 310.55 MB| 98.7 %     | OK      |
| 08  | symfony/dependency-injection — 3 k services       | 12.00 MB  | 7.65 MB  | 86.8 %     | OK      |
| 09  | league/csv + ramsey/uuid — 50 k row CSV round-trip| 36.00 MB  | 32.92 MB | 95.4 %     | OK      |
| 10  | PHP-internal mix — WeakMap, Fiber, generator, …   | 16.00 MB  | 12.68 MB | 82.6 %     | OK      |

Every snapshot reported. Headline gaps below are reproducible
across runs and across drivers — i.e. they are gaps in the
analyser, not artefacts of one heap shape.

## Reli gaps surfaced by this survey

Numbering continues from the idle survey. Gaps confirmed against
this batch of targets are linked back to that doc; new gaps get
fresh `Wxx` IDs (`W` for "workload pass").

### W1. Call-stack frame text is corrupted on the substrate path for some captures

Driver: `apps/05_faker.php`. Output:

```
  Call Stack at capture:
    #0 sleep:-1
    #<main>n>:25
```

Expected: `#1 <main>:25`. The same .rmem reproduces it across re-runs
of `inspector:memory:report`. The JSON report shows the correct
`hypothesis: "#0 sleep:-1\n#1 <main>:25"` — so the structured finding
is right; the text formatter (or the substrate-backed
`CallStackPass`) is corrupting frame 1's label after the JSON branch
has already serialised it.

The same .rmem produces a clean stack for every other capture in the
batch. Faker is the largest top-of-frame symbol-table driver here
(30 000 stdClass with 11 dynamic properties each); the corruption is
data-dependent, not a transient FFI read.

The on-disk data is fine. Exporting the .rmem to SQLite and reading
the raw `context_node_attributes` rows for the `<main>` frame shows:

```
node_id=1149989  function_name = <main>
node_id=1149989  lineno        = 25
```

i.e. the capture stored the right values; the substrate-backed
`CallStackPass::analyzeWithSubstrate()` (or `NodeLabeler` ahead of
it) is splicing extra characters in only when going through the
text formatter — the JSON-formatter goes through a different code
path that reads the same fields and emits `"#1 <main>:25"`
correctly.

Likely cause: a stale-buffer bug in the binary-path `frame_labels`
loader where the function_name and lineno of consecutive frames are
read into overlapping buffers. Worth reproducing by running the
existing report-tests (`tests/Inspector/Output/MemoryOutput/Report/Pass/CallStackPassTest.php`)
under valgrind, or by capturing this exact 05_faker .rmem into a
fixture.

This is the only **wrong-data** finding in the batch — every other
gap is missing data, mis-rendered framing, or denominator confusion.

### W2. "% analyzed" exceeds 100 % when many objects share one op_array

Driver: `apps/06_monolog.php`. Report header:

```
memory_get_usage(): 100.55 MB | memory_get_usage(true): 102.00 MB
| Heap: 106.06 MB (105.5% analyzed)
```

This is a different mechanism from G2 in the idle survey (which
attributed it to numerator/denominator unit mismatch — slot-rounded
numerator vs `memory_get_usage()` denominator). After the
`location_types_summary`-based numerator landed, the denominator
matches the numerator's units; the leftover 5.51 MB excess on this
driver is **double-counting in the numerator itself**.

Confirmed by exporting `snap.rmem` to SQLite and inspecting
`context_node_locations`. The `INSERT INTO location_types_summary
… SUM(size)` aggregate in `PdoMemoryOutput::insertLocationTypesSummaryFromDb`
is taken over rows that include the same physical address multiple
times:

```
rows-per-addr=1      groups=905,533
rows-per-addr=2      groups=31
rows-per-addr=3      groups=2
rows-per-addr=30001  groups=4   ← 120,004 rows on 4 addresses
```

The 4 hot addresses are exactly the four sub-allocations of one
`zend_op_array`:

| address           | location_type           | size   | rows   | total bytes |
| ----------------- | ----------------------- | ------ | ------ | ----------- |
| 0x7f497c689300    | ZendOpArrayBody         | 256 B  | 30,001 | 7.32 MB     |
| 0x7f497c65f090    | LocalVariableNameTable  |  16 B  | 30,001 | 469 KB      |
| 0x7f497c657580    | ZendArgInfos            |  32 B  | 30,001 | 938 KB      |
| 0x7f497c6e55d0    | RuntimeCache            |  24 B  | 30,001 | 703 KB      |
|                   |                         |        |        | **9.39 MB** |

The driver's processor

```php
$logger->pushProcessor(static function (\Monolog\LogRecord $r) {
    $extra = $r->extra;
    $extra['cb'] = static function ($x) use ($r) {  // <- 30 000 closures
        return [$r->message, $x];
    };
    return $r->with(extra: $extra);
});
```

instantiates 30 000 inner Closure objects, **all sharing one
`zend_op_array`** (Zend compiles the inner `static function` once
and binds new Closure objects to that single function struct). Reli
walks each Closure's `func->op_array` chain and emits a separate
`ZendOpArrayBody / LocalVariableNameTable / ZendArgInfos /
RuntimeCache` location row for each Closure — so the same 328 B of
op_array storage is counted 30 001 times.

Dedup-by-`(address)` reduces the type-summary sum from 106.06 MB to
96.68 MB (the 9.39 MB delta matches the four hot rows almost
exactly). With the dedup applied, `% analyzed = 96.68 / 100.55 =
96.2 %`, comfortably below 100 % and consistent with `% analyzed`
on the other workload-driven targets.

**Layer the duplicates land at.** The .rmem file itself carries the
duplicates — they are not a SQL-export artefact. `rmem:query
--sections` on `06_monolog/snap.rmem` reports `locations
1,041,450 elements`, exactly matching `SELECT COUNT(*) FROM
context_node_locations` after `inspector:memory:export-sqlite`.
Distinct addresses are 920 009 — i.e. 121 441 redundant rows on
the same physical addresses (the four hot 30 001-row groups account
for 120 004 of those, the rest are small noise). So every consumer
of the .rmem (live capture's report, dump→analyze→report,
rmem:explore, rmem:mcp) sees the same inflated numerator.

The bypassed dedup. `MemoryLocations::add()`
(`src/Lib/PhpProcessReader/PhpMemoryReader/MemoryLocation/MemoryLocations.php`)
already deduplicates on `address` when adding to the in-memory
collection — the second add for the same address is dropped (or
the larger one wins). But that map is only consulted to **guard
the in-memory enumeration set**; the actual writers
(`PdoContextTreeSink::emitNode()` for SQL,
`BinaryContextTreeSink::emitNode()` for `.rmem`) iterate each
context's `getLocations()` and emit every returned location
without re-checking for an existing entry at that address. The
binary sink even acknowledges this in a comment:

```
// No dedup: ContextAnalyzer guarantees each context is emitted
// once via getMemoNodeId/setMemoNodeId. Substrate loader handles
// any edge cases at read time.
```

The invariant that comment relies on (one context → one node row)
is true; the *implicit* invariant the rest of the report depends
on (one location → one row) is not.

Walking the path on the closure case:

1. `collectZendFunctionPointer` short-circuits when the **`ZendFunction`
   struct address** has been seen before. For closures the function
   struct is per-instance (Zend copies it during
   `zend_create_closure`), so this check never fires.
2. `user_function_definition_context_pool->getContextForLocation(...)`
   is keyed by `op_array_header_memory_location`'s address (also
   per-closure), so each closure gets a **fresh
   `FunctionDefinitionContext`** — and a fresh `OpArrayContext`,
   `RuntimeCacheContext`, `ArgInfosContext`, and
   `LocalVariableNameTableContext` even though every one of those
   `MemoryLocation` objects points at the **shared body / arg_info
   / variable-name-table / runtime-cache addresses**.
3. At emit time, each fresh context's `getLocations()` returns the
   shared-address `MemoryLocation`. The sink writes 30 001 rows for
   each of the four shared sub-allocations.

The `MemoryLocations::add()` dedup never sees these because they
are never added to a single shared `MemoryLocations` instance —
each closure's collection is treated as its own batch.

Three viable fixes, ordered cheapest-to-cleanest:

- **Sink-side `(address, location_type)` dedup, both sinks.** Add a
  `seen_emit_keys` set in `PdoContextTreeSink::emitNode()` and
  `BinaryContextTreeSink::emitNode()` to suppress duplicate location
  rows. Trims rmem and SQL output symmetrically, but the dedup is
  paid in the writer hot path on every snapshot and the `// No
  dedup` comment in the binary sink would need to flip.
- **Aggregate-side dedup, SQL only.** `insertLocationTypesSummaryFromDb`
  becomes `SELECT … SUM(size) FROM (SELECT DISTINCT address, size,
  location_type FROM context_node_locations …)`. Cheapest patch but
  asymmetric: it fixes the Overview / Type Breakdown numbers in
  reports generated from the SQL substrate, leaves the .rmem
  inflated, and leaves the wrong row count in `Type Breakdown`
  (`ZendOpArrayBody count=30 279` reads as "30 279 distinct
  op_arrays" when 30 000 of those are the same op_array).
- **Don't emit shared op_array sub-locations per closure.** Recognise
  in `collectUserFunctionDefinition` that body / arg_info /
  variable-name-table / runtime-cache addresses are shared with the
  prototype function, and use `MemoryLocations::addAlias()` instead
  of `add()` for the per-closure references. The first closure
  emits the actual node + locations under the prototype function;
  every subsequent closure attaches its `Closure` node via an alias
  edge into that prototype function's op_array context. The
  `addAlias()` mechanism is already in the codebase for exactly
  this shape, just not wired up here. Fixes the rmem at source so
  every downstream consumer (SQL export, rmem:explore, rmem:mcp)
  gets the smaller, correctly-deduped file for free.

Cross-check on the other targets in this batch is pending; the G2
laravel.live case from the idle survey may have the same root cause
if its idle bootstrap retains many closures sharing one op_array.

### W3. `% analyzed` regularly trails the 95 % bar even on small heaps

Without invoking pathological data, four of nine successful captures
reported sub-95 % analyzed:

| Driver        | % analyzed | Unaccounted |
| ------------- | ---------- | ----------- |
| 01_doctrine   | 84.3 %     | 6.36 MB     |
| 04_twig       | 93.0 %     | 1.89 MB     |
| 05_faker      | 85.5 %     | 9.42 MB     |
| 08_symfony_di | 86.8 %     | 1.16 MB     |

This is the same shape as G5 in the idle survey ("no breakdown").
The report says only how many bytes are missing, not which ZendMM
bins / chunks they live in. For the Faker driver in particular,
9.42 MB unaccounted is most of one ZendMM 2 MB chunk worth — the
walker should be able to point at the chunk(s) where its visited
slots don't add up to the chunk's allocated-size accounting.

`G5` already proposed an "Unaccounted regions" section. Promote it.

### W4. `=== Top Strings ===` and `=== Observations ===` sections are silently omitted

Driver: `apps/02_phpspreadsheet.php`, `apps/01_doctrine_orm.php`.

```
$ grep -c "^=== " 02_phpspreadsheet/report.txt   # 8 sections
$ grep -c "^=== " 04_twig/report.txt              # 10 sections (Top Strings + Observations)
```

This is probably intentional ("don't show empty section") but it
makes report diffs noisy and makes downstream tooling guess at
section presence. Two fixes are reasonable:

- Always emit the section header followed by `(no findings)` so
  every report has a stable shape.
- Emit a single-line manifest near the top: `Sections: overview,
  findings, type_breakdown, top_classes, …`.

### W5. `dedup_candidate` rows that fall back to bare `value` underspecify the bucket

Almost every report carries rows like:

```
[LOW] 1.16 MB impacted
  dedup_candidate: value: 30,339 copies x 40 B = 1.16 MB
  11/30339 copies have identical content (0%). Example: ""
```

Read superficially, `value` looks like the only key the bucket is
grouped on, which would make the row near-meaningless. **It isn't.**
The SQL aggregation in `DedupCandidatePass::loadDedupRowsFromSql`
buckets by `(link_name, child shallow_size, child class_name, child
location_type)` and gates with `cnt > 50 AND cnt × size > 10 240`.
`ZendArrayMemoryLocation` targets are rejected outright. The B6
note in `docs/internals/memory-report-ux-improvements.md` was
specifically there to keep heterogeneous classes that happen to
share a shallow size out of the same bucket.

So binning is fine. The complaint is that the **rendered label**
strips the bucket key down to `link_name` only, in two stacked ways:

- **Shallow parent resolver.** `resolveDedupOwnerInfoFromSubstrate`
  recognises only two parent shapes: parent is `object_properties`
  directly, or `bucket → array_elements → array_header →
  object_properties` (one level of array nesting under a property).
  Anything deeper or differently-rooted (`$obj->prop[L1][L2] → x`,
  arrays attached to a global symbol, generators' static_variables,
  closure use-bindings, etc.) returns `source_class=null`, and
  `buildDedupLabel` then degrades to bare `link_name`. The Monolog
  case `[value] LogRecord-cnt=30000` (4.56 MB) goes through
  `recordsByLevel[Level::Info][i] → LogRecord` which is exactly the
  two-level-nested shape that misses the resolver's pattern.
- **No `target_location_type` fallback in the suffix.**
  `buildDedupLabel` appends `({$target_class})` when target_class
  is non-null. Internal types — `ZendString`, `ZendReference`, etc.
  — leave `class_name = NULL`, so 30 339 same-shape strings render
  with no target hint at all even though the SQL bucket key knew
  they were all `ZendStringMemoryLocation`.

There's also a separate hazard in the **impact figure itself**.
`total_waste = cnt × shallow_size` is the upper bound assuming all
N members are dedupable. The hypothesis line carries
`identical_count / cnt` (e.g. `11/30339 (0%)`) — that's the actually
measurable dedup potential, ~440 B in this case, not 1.16 MB. Sorting
by `cnt × size` therefore hoists buckets where almost nothing is
identical above the buckets where dedup actually saves something.

Reasonable fixes, none mutually exclusive:

- Walk the tree-parent chain N hops in
  `resolveDedupOwnerInfoFromSubstrate` until either an
  `object_properties` ancestor is found or a sentinel (global
  symbol table, function table) is hit. The discovered chain
  becomes the rendered prefix (`$obj->prop[L1][L2][value]` etc.).
- When `target_class` is null, use `target_location_type` for the
  suffix (`(ZendString)`, `(ZendReference)`).
- Rank dedup_candidate findings by `(identical_count - 1) ×
  shallow_size` (genuine dedup floor) rather than `cnt × shallow_size`,
  with the latter shown as "potential" alongside.

**Cost note.** The hardcoded two-shape pattern in the resolver
looks like a SQL-era leftover — when each hop was a prepared
`SELECT parent_node_id, link_name FROM context_edges WHERE …`,
walking deeper visibly added milliseconds per finding. The
substrate-backed path uses O(1) array lookups
(`tree_parents[id]`, `tree_link_names[id]`, `node_classes[id]`,
`node_types[id]` — see `GraphSubstrate.php:725-732`), and
`dedup_candidate` emits at most ten findings per report (the SQL
bucket query is `LIMIT 10`). On the in-memory pipeline, walking
the tree-parent chain to its nearest `object_properties` ancestor
is bounded by tree depth (≈10-30 hops in real heaps) × 10 findings
× a handful of array reads each — well below the noise floor of
any other pass. Even the SQL fallback only pays one prepared
`loadTreeParentInfo` per hop per finding (≤ 80 round-trips total
at depth 8); index-backed that's a few ms.

**Which path actually runs the resolver.** The owner-resolution
branch in `DedupCandidatePass::analyze` is `if ($this->substrate
!== null)` — substrate first, SQL fallback only when no substrate
is loaded. The substrate is built unconditionally in the rmem path
(`generateFromBinary`) and on the SQLite path with `--full-analysis`
(the CLI default). The all-SQL fallback (`resolveDedupOwnerInfoFromSql`)
is reachable only on `.db`/`.sqlite` input with `--no-full-analysis`
*and* `edge_count >= 500000` so Phase 3 is skipped. That combination
is rare in practice; widening the resolver therefore matters most
for the substrate path, which is what every default invocation
takes. Mirroring the depth fix into the SQL fallback is still good
hygiene but isn't where the bulk of the user-visible improvement
lands.

### W6. `Heap` line on workload-driven targets needs a "RSS / mgu(true)" gloss

Heaps in the table above range from 7 MB to 310 MB. On the larger
ones the user wants to know:

- how much of `memory_get_usage(true)` reli accounts for, and
- how that compares to RSS (which catches the ZendMM fragmentation
  pinned-by-long-lived-allocation case from `zendmm_*`).

Today the Overview line only pairs `memory_get_usage()` /
`memory_get_usage(true)` / `Heap`. Adding RSS (already captured into
the dump path per G3) would make the workload-driven survey cases
self-explanatory instead of requiring `ps`-cross-checking.

### W7. Fiber VM stack bytes are aggregated but not localised per Fiber

Driver: `apps/10_special_features.php` constructs 200 Fibers, calls
`->start()` on each (so they suspend at `Fiber::suspend(...)`), and
holds them in `$fibers`. The Top Arrays section reports:

```
$fibers     68.13 KB retained, 200 elements
Fiber       200 instances × 328 B = 64.06 KB
```

Read superficially that suggests the per-Fiber 16 KB VM stacks
aren't being seen. They are — just not in the place that line is
measuring. Two pieces:

- **Headline `VM stack: 3.38 MB`** in the Overview already includes
  Fiber VM stacks. `EmitFiberJob` (`Collector/Job/EmitFiberJob.php`)
  walks each suspended Fiber's `vm_stack` chain and adds every
  segment to the same `MemoryLocations` collection that holds the
  main thread's stack; `RegionAnalyzer` (`RegionAnalyzer.php:103-113`)
  sums it into `vm_stack_total`, and `OverviewPass` renders that.
  On driver 10 the figure works out to ≈ 256 KB (main) + 200 ×
  ~16 KB (Fibers) ≈ 3.38 MB, so the bytes aren't lost.
- **Per-Fiber localisation is missing.** `EmitFiberJob` only
  records the `VmStackMemoryLocation` instances into the
  bookkeeping `MemoryLocations` for region classification — it
  never calls `$ctx->emitNode(...)` with one. The Fiber object
  itself becomes a node (with a `FiberContext` carrying a
  `call_frames` child for the suspended frame), but the VM stack
  region the Fiber owns is not attached as a child node. As a
  consequence, `Top Arrays` / `bottleneck_path` / `choke_point`
  cannot say "$fibers[42] retains 16 KB of VM stack" — they can
  only point at the Fiber wrapper object.

Closing this gap is structural, not algorithmic: `EmitFiberJob`
needs to emit each VM stack segment (or one aggregate
`FiberVmStack` location summing them) as a child of the
`FiberContext` node, with the addresses already in hand. The
collection step that produces the addresses is already there;
it's the emit + edge wiring that's absent.

A separate hygiene note: the `MemoryLocations` collection is
plumbed into `CollectorContext` via a constructor parameter
named `$fiber_vm_stack_memory_locations`, but the call site in
`MemoryLocationsCollector` (line 322) passes the **combined main
+ Fiber** collection there. The field name suggests a Fiber-only
collection that doesn't exist; either rename the field to match
the variable (`vm_stack_memory_locations`) or actually split the
two collections. Worth touching at the same time as the emit fix
so the field name reflects whatever the new shape is.

Note on the original framing in this entry: an earlier draft
attributed the driver's 2.66 MB unaccounted heap to missing
Fiber-stack accounting. That was wrong — `% analyzed` is computed
against `memory_get_usage()`, which excludes VM stack bytes
entirely (heap-only), so Fiber stacks neither contribute to nor
explain the unaccounted gap. The unaccounted bytes on driver 10
are something else (likely opcache / extension-side allocations
ZendMM didn't pointer-trace), independent of W7.

### W8. WeakMap entry table reports `Table: 0 B`

Same driver. `Top Arrays`:

```
$weak->weak_map->entries     6.38 MB retained, 5,000 elements, Table: 0 B
```

WeakMap is implemented as a HashTable internally; the table
allocation is real (5 000 entries × ~32 B = 160 KB minimum), so
`Table: 0 B` is wrong even if it's a "we don't measure WeakMap
storage like a normal array" decision. Either:

- The WeakMap node should report the actual `arData` size like
  every other HashTable, or
- The column should display a sentinel ("(weak)") so the user
  isn't told 0 bytes for something that isn't 0.

### W9. Suspended generator/fiber frames bleed into the call_stack finding

Same driver. Captured call stack:

```
  Call Stack at capture:
    #0 {closure}(/tmp/reli-memscan/apps/10_special_features.php:44-49):47
    #1 sleep:-1
    #2 <main>:92
```

Frame `#0` is a **suspended generator** body. The generator IIFE
spans lines 44-49 in the driver and yields at line 47 — exactly
matching one of the 1 000 generators primed via `current()`. But
the actual main thread is sitting in `sleep` from line 92 of
`<main>` (no caller above sleep). The generator's frame should
not appear above sleep — they live on different `execute_data`
chains.

Two reasonable interpretations:

1. The substrate enumerates every reachable `execute_data` and
   labels them as one flat `CallFramesContext`. That works for
   walking node sizes but produces wrong call-stack output.
2. The generator frame is correctly modelled but the report
   formatter picks the deepest frame from any chain and joins
   them.

Either way, only one of the 1 000 generators' frames surfaces
(the rest are at the same yield site and presumably collide on
some key). Surfacing them all under a separate "Suspended
contexts" section, and keeping the main-thread call stack clean,
matches user expectation. Worth pairing with a `--show-suspended`
flag when there are too many.

Users trust the call stack to localise the work being done at
capture; "main is in sleep at line 92" is the truth here, not
"main is yielding inside a generator".

## Wasteful memory observed in the surveyed apps

These are observations about each library's working-set shape.
Nothing here is a defect in the library — they are realistic
production patterns whose cost is invisible without a memory
profiler.

### 01 — Doctrine ORM (20 k Product entities)

- **`UnitOfWork::$identityMap['Product']` retains 10.78 MB**
  (= `ArrayElementsContext` with 20 000 children) — the dominant
  retainer outside of the entities themselves. `clear()` between
  batches is the canonical fix; the report's `bottleneck_path` chain
  (`$em → unitOfWork → identityMap`) makes it findable in one read.
- **`UnitOfWork::$originalEntityData` and `$entityIdentifiers` each
  retain another 8.42 MB** — i.e. as much as the identity map by
  itself. These are the snapshot-of-loaded-state arrays UoW uses for
  dirty tracking; they double the working-set cost of a hydrated
  entity once you add them up.
- **Every Product owns a `DateTime` (`$createdAt`, 20 001
  instances, 781 KB) reported as `dynamic_properties_overhead`.**
  PHP's stock `DateTime` lazily allocates a property table the first
  time it's instantiated; on a 20 k-entity hydration that's pure
  per-instance overhead (~40 B/instance). Switching the entity
  property to `\DateTimeImmutable` doesn't dodge it; but a manual
  string-typed column with on-demand parsing does.
- **One large cycle (10 classes, 28.90 MB retained)** — UoW back-
  references via `$filterCollection`. Doctrine has known historical
  cycles here; reli's `cycle_cluster` finding (`Back-reference: value`)
  flags it as one cluster across all hydrated entities.

### 02 — PhpSpreadsheet (80 000 Cells)

- **Every `Cell` allocates an `IgnoredErrors` companion** — 80 000
  instances, 7.93 MB. `IgnoredErrors` exists to suppress per-cell
  Excel error markers; an empty/default instance is not free
  (104 B/instance). The "1:1 ownership pattern, 100 % coverage"
  finding pinpoints it. Lazy allocation (only construct
  `IgnoredErrors` when one of its setters is called) would save
  ~7.9 MB on a large spreadsheet at zero runtime cost. Already a
  reasonable upstream PR.
- **`workSheetCollection[0]->cellCollection->cache` retains
  31.86 MB** — the cell cache is what holds the actual values, so
  this is by-design rather than waste, but it's the report's clearest
  "this is where the data lives" pointer. A user who didn't know about
  `cellCollection->cache` learns it from the bottleneck path in one
  read.
- **`PhpOffice\PhpSpreadsheet\Style\Color` instances carry 5 dynamic
  properties each (16 instances, 4.63 KB avoidable)** — small
  absolute number, but it scales linearly with styled rows. Declared
  properties on `Style\Color` would save it.

### 03 — nikic/php-parser (parsed AST for ~1.6 k source files)

- **`$asts` retains 392 MB across 1 627 ASTs** — average ~240 KB
  per parsed file, but the bottleneck path picks
  `Parser/Php7.php` (19.13 MB) — i.e. PhpParser parsing its own
  parser is the fattest single AST. The "spine" output (drop from
  392 → 19 MB at the heaviest child) tells the user the rest of
  the heap is distributed across the other 1 600 files.
- **`Node\Expr\Variable`: 141 358 instances, 9.71 MB.** Owned 1:1
  by 8 different node types (`PropertyFetch::$var`,
  `Param::$var`, `Foreach_::$expr`, …). The
  ownership_pattern finding lists every owner with its share.
  Most variables in real PHP code reduce to a small alphabet of
  names (`$this`, `$key`, `$value`, `$result`, …); a flyweight
  pattern (intern Variable nodes by `name`) would shave most of
  this. Probably not worth changing in PhpParser given how cheap
  AST allocation already is, but useful as a "what would
  flyweighting nodes save?" upper bound.
- **`ZendArrayTable` is 51.1 % of heap** (206 MB). Each AST node
  carries an `attributes` array (start/end token positions), and
  most carry a `stmts`/`exprs` sub-array, so 500 k AST nodes
  expand into ~900 k HashTables. The dominant_type finding
  catches it.
- **No cycles surfaced.** AST is a strict tree, so retained-size
  is exact and the `bottleneck_path` works without fan-in
  approximation.
- **94.4 % analyzed** — 24 MB unaccounted on a 403 MB heap. Likely
  shared op_array bodies for the parser methods themselves, plus
  the runtime cache slots for them.

### 04 — Twig (200 compiled templates rendered)

- **`$twig->parser->visitors[0]->safeAnalysis->data` retains 5.65 MB
  across 2 400 entries.** SafeAnalysisNodeVisitor accumulates the
  per-node "is this output safe" results during compilation but
  doesn't clear the table after each template. A single cache per
  Environment lifetime is fine for a single render; for a long-lived
  worker that compiles many templates this grows unbounded. Twig has
  fixed similar leaks in the past (Profiler, ExtensionSet); this
  one looks like the same shape.
- **`Twig\Node\NameDeprecation` — 4 800 instances of an empty
  object (412 KB).** The `empty_object` finding catches it. These
  are placeholder no-deprecation markers attached to every name
  reference; they could be a single shared sentinel.
- **`Twig\Util\ReflectionCallable` and `ReflectionMethod` are 1:1
  per `FilterExpression` (600 each, 93 KB).** `ReflectionMethod` is
  cheap individually but it carries dynamic properties and per-class
  property tables; on a large template set it adds up. A single
  `ReflectionCallable` per `(class, method)` pair (memoised per
  Environment) would compress this.

### 05 — Faker (30 000 stdClass records, ja_JP locale)

- **`stdClass` with 11 dynamic properties × 30 k = 14.88 MB
  avoidable structural overhead.** The finding's recommendation
  ("replace stdClass with a dedicated DTO") is exactly right —
  declared-property classes pay 56 B/instance for the property
  table; dynamic-property stdClass pays ~696 B/instance for the
  hash-table-with-spare-capacity. On a hydrated 30 k record set this
  is more bytes than the *data*.
- **30 k `DateTime` objects each lazily inflate a property table
  (1.60 MB structural, ~1.60 MB avoidable)** — same pattern as the
  Doctrine driver's `$createdAt`. Faker emits `\DateTime` from
  `dateTime()` even though it never sets a custom property on it —
  the property table inflation is purely defensive on PHP's part.
- **ZendMM is on the verge of expanding its cached-chunk cap.**
  `zendmm_cache_expansion_imminent` warning fired on this run
  (counter 4/4). Faker's loop is exactly the "long-lived CLI
  cycling through bursty allocations" shape that triggers it.

### 06 — Monolog (30 000 LogRecord via TestHandler with closure processor)

- **Each LogRecord retains its predecessor through a Closure that
  captures `$r` by use** — 30 003 closures pair with 30 000
  immutable records, totalling 11.45 MB. The driver intentionally
  inserts a "synthetic" processor that captures `$r` to demonstrate
  the trap; production Monolog code using
  `pushProcessor(fn($r) => $r->with(...))` does not have this issue,
  but a custom processor that closes over the record will silently
  retain ~344 B per record forever as long as the handler keeps the
  rebuilt record. Worth a `monolog/monolog` doc note.
- **`Monolog\JsonSerializableDateTimeImmutable` has 1 dynamic
  property per instance (10.29 MB avoidable on 29 981 instances)** —
  the `__construct` path sets `$keepUTC` dynamically. Declaring
  `protected bool $keepUTC = false;` on the class would save
  10 MB on this workload.
- **`LogRecord::$formatted` is set per record (12.82 MB total
  across 60 000 records)** — the `formatted` field is populated by
  `LineFormatter` and then retained even after the record has been
  emitted. `TestHandler` keeps records around for assertions, so this
  is by-design for tests; in a streaming handler the same field
  cleared after `handle()` returns would shave 12 MB on this load.

### 07 — GraphQL-php (5 identical query executions of `users { friends { friends { id name } } }`)

- **`$results[N]->data['users']` retains 61.15 MB per execution.**
  The driver kept all 5 `ExecutionResult`s; reli's
  `bottleneck_path` finds the spine in one read (`$results → [0] →
  data → ['users']`). At ~12 KB per resolved User × 5 000 users ×
  5 executions = 305 MB, the working set is pure response payload.
  In a real GraphQL server only the latest response is held; this
  driver demonstrates that retaining responses is the canonical
  GraphQL OOM pattern.
- **The schema itself contains a 4-class cycle**
  (`FieldDefinition / NonNull / ListOfType / ObjectType` via
  `wrappedType`) — 107 KB retained. By design (recursive types like
  `User { friends: [User] }` need it); fully expected. Worth noting
  that reli's `cycle_cluster` correctly identifies the back-edge
  (`wrappedType`).
- **`GraphQL\Language\Token`s form a 21-node `next` cycle** —
  3.45 KB. This is a parser doubly-linked-token-list artefact; by
  design but it does mean each parsed query keeps its tokens alive
  via the cycle until shutdown.

### 08 — Symfony DI (3 k container definitions, 1.5 k instantiated)

- **999 identical `ServiceReferenceGraphNode ↔ Edge` cycles**
  (1.14 MB retained) — Symfony's compiler builds an internal graph
  of service references during `compile()` and stores it on
  `removingPasses[0][6]->analyzingPass->graph`. The graph is no longer
  needed after compilation but the container retains the pass chain.
  In a real Symfony app, dumping the container to PHP code then
  loading the dumped container is what avoids this; users who only
  call `compile()` without dumping pay for it indefinitely. The
  `cycle_cluster` finding (`999 identical cycles — a structural
  pattern`) makes this discoverable.
- **`Symfony\Component\DependencyInjection\Definition`** instances
  (3 001 × 408 B = 1.17 MB) — every service definition is retained
  even for services never instantiated. Compiled-and-dumped
  containers drop this; in-memory `ContainerBuilder.get()` workflows
  pay for it.
- **86.8 % analyzed** — the gap is mostly the function table for
  the auto-wiring reflection cache; surfacing it under W3.

### 09 — league/csv + ramsey/uuid (50 k row CSV → array of UUID-keyed records)

- **`Ramsey\Uuid\Lazy\LazyUuidFromString`: 50 000 instances ×
  72 B = 3.43 MB (99.9 % of object memory).** ramsey/uuid uses a
  lazy proxy that defers parsing until a method is called on the
  UUID object. Net: parsing is free if you never read the UUID, at
  the cost of a 72 B wrapper per instance. The "structural duplicate"
  / "property scaling" findings catch it; in this workload the lazy
  proxy is pure overhead because we never call methods on the UUIDs
  after constructing them.
- **The CSV row arrays themselves are 31.09 MB across 50 000
  associative arrays** — each `['uuid' => …, 'name' => …, …]` 5-key
  hash table occupies ~640 B with table overhead (`ZendArrayTable` +
  `ZendArrayTableOverhead`). For a one-shot CSV import this is fine;
  for a streaming import a generator would be ~2 orders of magnitude
  cheaper.
- **One-time deprecation warnings from `League\Csv` go to stderr
  but the report doesn't surface them** — orthogonal to reli, but
  worth noting because the driver's `target.log` shows two
  `createFromPath() is deprecated` lines that the captured heap
  cannot reflect.

### 10 — PHP-internal mix (WeakMap, Fiber, generator, enum, readonly)

Driver constructs ~5 k Points, 5 k-element WeakMap with closure
values, 5 k-element ArrayObject, 1 k primed generators, 200
suspended Fibers, 2 k closures with bound `$this`, 1 k references
to one shared array.

What the report **gets right**:

- **Enums are de-duplicated.** `Color` is reported as 3 instances
  total (one per case), with 4 997 references through Point::$color.
  The `[interning] Point::$color -> Color (4,997 refs -> 3
  targets, 1665.7 each)` observation captures this exactly.
- **Closure storage is the dominant cost.** 8 202 Closures × 344 B
  = 2.69 MB (75 % of object memory). Each closure carries an
  op_array body (`ZendOpArrayBody` 384 B average) and runtime
  cache, which reli prices in via the bin histogram.
- **WeakMap is reachable from a path.** The bottleneck path
  `$weak->weak_map->entries[0]->closure->op_array` is the right
  shape for "5 000 closures held alive by a WeakMap" — the user
  who didn't know how WeakMap stores values learns the path.
- **Bound `$this` closures.** `$closures` retains 1.92 MB across
  2 000 entries; `Holder` instances appear as a `dedup_candidate`
  via `this_ptr` (the bound-`$this` slot). Each closure's bound
  receiver is named.

What the report **misses or mis-renders**:

- **Per-Fiber VM stack localisation** (W7): the headline `VM stack:
  3.38 MB` already includes the 200 × ~16 KB Fiber stacks; what's
  missing is attaching them as child nodes of each Fiber so
  `Top Arrays` / `bottleneck_path` can pin retained bytes to a
  specific `$fibers[i]`. The collection step is in
  `EmitFiberJob`; the emit step is the gap.
- **Suspended generator frames** show up as `Generator` objects
  (1 000 × 280 B = 273 KB) but their per-frame symbol tables (the
  driver's `$local = ['x' => $i, 'big' => 'gggg…']`) aren't
  itemised — `$gens` retains only 906 KB total, much less than the
  ~16 KB-per-generator that PHP allocates for the suspended
  symbol table + execute_data. Worth a dedicated `GeneratorFrame`
  location type, parallel to W7's per-Fiber emit.
- **Call-stack wrong shape** (W9): a generator yield-site bleeds
  into the main thread's call stack.
- **Reference type collapses to one ZendReference.** 1 000
  `$refs[$i] = &$shared` produces a single ZendReference object
  that all 1 000 entries point to, which is correct PHP semantics
  but worth surfacing as `[shared_fanin] $refs[*] -> ZendReference
  (1000 refs -> 1 target)`. Today the report shows nothing for
  this because the refcount fits in one entry. Not a bug; a
  feature gap if "where did all my references go?" is a
  legitimate user question.

## Reproducing this survey

Driver scripts and per-run outputs live at `/tmp/reli-memscan/`:

```
apps/01_doctrine_orm.php         apps/06_monolog.php
apps/02_phpspreadsheet.php       apps/07_graphql.php
apps/03_phpparser.php            apps/08_symfony_di.php
apps/04_twig.php                 apps/09_csv_uuid.php
apps/05_faker.php                apps/10_special_features.php
composer.json + composer.lock    run.sh
results/<name>/
    target.log     # driver stdout (includes the "ready pid=" line)
    capture.log    # reli capture stderr
    snap.rmem      # captured snapshot
    snap.rmem.derived  # SCC + subtree-size sidecar
    report.txt     # human report
    report.json    # JSON report
    report.err     # report stderr
```

`run.sh <name> apps/<name>.php` runs the full pipeline. Capture
takes 10 s on small heaps to >5 min on the 03_phpparser-shape
heaps (~600 k AST node objects).
