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

PHP target: 8.4.19 NTS (host PHP). Capture timeout 900 s (some
working sets require it — see G12). Driver scripts and reports live
at `/tmp/reli-memscan/` on the survey host.

## Targets and headline numbers

| #   | Driver / library                                  | mgu(true) | Heap     | % analyzed | Capture |
| --- | ------------------------------------------------- | --------- | -------- | ---------- | ------- |
| 01  | doctrine/orm — 20 k Product entities hydrated     | 42.00 MB  | 34.20 MB | 84.3 %     | OK      |
| 02  | phpoffice/phpspreadsheet — 80 k Cells in memory   | 48.00 MB  | 44.72 MB | 95.4 %     | OK      |
| 03  | nikic/php-parser — 1.6 k file ASTs                | 434.00 MB | 403.55 MB| 94.4 %     | OK (8 min, W4) |
| 04  | twig/twig — 200 templates compiled + rendered     | 28.00 MB  | 25.19 MB | 93.0 %     | OK      |
| 05  | fakerphp/faker (ja_JP) — 30 k stdClass records    | 66.00 MB  | 55.77 MB | 85.5 %     | OK      |
| 06  | monolog/monolog — 30 k LogRecord with closures    | 102.00 MB | 106.06 MB| **105.5 %** (G2) | OK |
| 07  | webonyx/graphql-php — 5× 5 k-user query result    | 318.00 MB | 310.55 MB| 98.7 %     | OK      |
| 08  | symfony/dependency-injection — 3 k services       | 12.00 MB  | 7.65 MB  | 86.8 %     | OK      |
| 09  | league/csv + ramsey/uuid — 50 k row CSV round-trip| 36.00 MB  | 32.92 MB | 95.4 %     | OK      |
| 10  | PHP-internal mix — WeakMap, Fiber, generator, …   | 16.00 MB  | 12.68 MB | 82.6 %     | OK      |

Apart from #03 (still being investigated under a longer timeout),
every snapshot reported. Headline gaps below are reproducible across
runs and across drivers — i.e. they are gaps in the analyser, not
artefacts of one heap shape.

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

### W2. Confirms G2 — "% analyzed" exceeds 100 % on Monolog driver

Driver: `apps/06_monolog.php`. Report header:

```
memory_get_usage(): 100.55 MB | memory_get_usage(true): 102.00 MB
| Heap: 106.06 MB (105.5% analyzed)
```

The denominator (`memory_get_usage()` ≈ 100.55 MB) is smaller than
the numerator (sum-of-allocations ≈ 106.06 MB). G2 in the idle
survey called this out for `laravel.live` (105.8 %). The Monolog
case is the same defect, exposed by a heap dominated by 30 000
`Closure`-with-bound-`$r` plus 30 000 `LogRecord` allocations: each
op-array body / runtime cache slot is counted in the bin walker but
not reflected in `memory_get_usage()`.

Suggested fix unchanged from G2: pick a denominator that includes
ZendMM internal overhead (e.g. `chunks_total_bytes -
chunks_total_free_bytes`), or clamp the displayed percentage with a
note when the analysed sum exceeds the user-visible heap.

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

### W4. `inspector:memory` capture wall-time scales badly with class diversity

Driver: `apps/03_phpparser.php` parses 1.6 k .php files with
`nikic/php-parser` and retains ~1.6 k AST roots. The peak heap is
~434 MB. The capture timed out at 240 s in the first pass; under a
900 s budget it completed in **~8 min** wall (snap.rmem 1.5 GB).

The other 318 MB heap (07_graphql) captured in ~70 s, so absolute
heap size is not the predictor. The distinguishing feature of the
parser working set is **class-table breadth**: nikic/php-parser
ships ~150 distinct AST node classes, each with multiple subtypes,
and most instances cluster around a handful of those classes — but
the ELF-symbol enumeration on the class entries multiplies. Worth
instrumenting `BinaryAnalysisCache` cold-attach and the `class_table`
walker to confirm whether the bottleneck is

1. enumerating `module_registry` once per attach (one-time, but
   slow on very large class tables), or
2. per-instance class-entry resolution in the bin walker (scales
   with object count × class breadth).

If (2), an attribute-side cache keyed by `class_entry` address
would shrink it to (1).

### W5. `=== Top Strings ===` and `=== Observations ===` sections are silently omitted

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

### W6. `dedup_candidate` `value: N copies x M B` rows expose stub identifiers

Almost every report ends with rows like:

```
[LOW] 1.16 MB impacted
  dedup_candidate: value: 30,339 copies x 40 B = 1.16 MB
  11/30339 copies have identical content (0%). Example: ""
[shared_fanin] value -> ? (211,215 refs -> 61,150 targets, 3.5 each)
```

`value` here is the literal `link_name` of the bucket value's tree
edge — not a property name, not a class. A user who hasn't read
`memory-report-architecture.md` will read these as "some property
called `value` is duplicated everywhere", which is wrong. Either:

- Suppress `dedup_candidate` rows where the only available
  identifier is the generic edge name (`value`, `key`); or
- Prefix them with the parent path (`$h->records[*]['extra']`-style)
  so the user can localise them.

This is G7 / G9 from the idle survey, surfaced again on every
target. Worth promoting to a real fix rather than a known limitation.

### W7. `Heap` line on workload-driven targets needs a "RSS / mgu(true)" gloss

Heaps in the table above range from 7 MB to 310 MB. On the larger
ones the user wants to know:

- how much of `memory_get_usage(true)` reli accounts for, and
- how that compares to RSS (which catches the ZendMM fragmentation
  pinned-by-long-lived-allocation case from `zendmm_*`).

Today the Overview line only pairs `memory_get_usage()` /
`memory_get_usage(true)` / `Heap`. Adding RSS (already captured into
the dump path per G3) would make the workload-driven survey cases
self-explanatory instead of requiring `ps`-cross-checking.

### W8. `companion_cluster` text duplicates information visible elsewhere

Driver: `apps/06_monolog.php`. The report carries:

```
[MEDIUM] 11.45 MB impacted
  companion_cluster: Closure (30,003, 9.84 MB) always paired with
  Monolog\JsonSerializableDateTimeImmutable (30,000, 1.60 MB) — 11.45 MB
```

… and then re-states the same Closure / DateTime numbers in
`Top Classes by Memory` and `Type Breakdown`. The companion-cluster
finding is the most actionable of the three (it explains *why* the
two classes' counts move together) but is the lowest in the report
because severity is MEDIUM. On a long report the user keeps
scrolling past it.

Consider:

- Promoting `companion_cluster` above `Top Classes by Memory`
  whenever it explains ≥ 50 % of the dominant class' instance
  count, *or*
- Adding the companion class as a column in `Top Classes by Memory`
  ("paired with: …, 1:1 ratio") so the relationship is visible
  inline.

### W9. Suspended Fiber stacks aren't accounted for

Driver: `apps/10_special_features.php` constructs 200 Fibers, calls
`->start()` on each (so they suspend at `Fiber::suspend(...)`), and
holds them in `$fibers`. The report shows:

```
$fibers     68.13 KB retained, 200 elements
Fiber       200 instances × 328 B = 64.06 KB
```

i.e. only the Fiber wrapper objects are accounted for. Each Fiber
allocates its own VM stack (default 16 KB) and that stack is alive
between `start()` and `resume()` — but those bytes are **not** in
the bin walker's totals. The driver's call_stack-shape capture
shows that reli reaches into the suspended frame for the
**generator** case (see W11), so the Fiber gap is specifically
about **Fiber-private allocations** (its stack region + private
context) rather than about reaching the suspended `execute_data`.

The 2.66 MB unaccounted on this driver (82.6 % analyzed) is
suspiciously close to `200 fibers × ~16 KB = 3.2 MB` of stack
storage. A Fiber-stack walker that enumerates each Fiber's stack
region as a `FiberStack` location type would close this gap and
give per-Fiber retained sizes.

### W10. WeakMap entry table reports `Table: 0 B`

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

### W11. Suspended generator/fiber frames bleed into the call_stack finding

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

This is worth fixing before W7 because users will trust the call
stack to localise the work being done at capture; "main is in
sleep at line 92" is the truth here, not "main is yielding inside
a generator".

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

- **Suspended Fiber stacks** (W9): 200 Fibers, only ~64 KB
  accounted for the wrappers; ~3 MB of stack storage is in the
  unaccounted bucket.
- **Suspended generator frames** show up as `Generator` objects
  (1 000 × 280 B = 273 KB) but their per-frame symbol tables (the
  driver's `$local = ['x' => $i, 'big' => 'gggg…']`) aren't
  itemised — `$gens` retains only 906 KB total, much less than the
  ~16 KB-per-generator that PHP allocates for the suspended
  symbol table + execute_data. Worth a dedicated `GeneratorFrame`
  location type, parallel to W9's `FiberStack`.
- **Call-stack wrong shape** (W11): a generator yield-site bleeds
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
heaps (W4).
