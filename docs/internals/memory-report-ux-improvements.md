# Memory Report — UX Improvements (Working Notes)

Working notes on usability issues found by running
`inspector:memory:report` against real-world PHP workloads (Composer packages
loaded into `php:8.4-cli`: PHPUnit, Symfony Console, Twig, Parsedown,
Monolog + Guzzle + Carbon, Laravel Collections, PSR-7 stack).

These notes distinguish **confirmed implementation bugs** (factual errors or
miscounts the report shouldn't make) from **structural UX issues** (the
numbers are fine, but the presentation misleads a reader).

Labels `B*` = bug. `S*` = structural / presentation.

> **Sample size caveat.** The first pass of snapshots all had analysed heaps
> of 2–12 MB. That's below typical memory-problem territory and artificially
> magnifies the visibility of framework baseline cost. Several of the
> "class_table dominates" observations only look pathological because the
> rest of the heap is small; at 100 MB+ heaps they'd shrink to noise. Some
> of the items below therefore need re-validation against larger snapshots
> before acting on them.

---

## Confirmed implementation bugs

### B1. Class and method names are lower-cased everywhere in paths

Every path that descends into `class_table` renders class and method names
as the case-folded Zend hashtable key instead of the canonical name:

    class_table->symfony\component\dependencyinjection\containerbuilder->methods->createservice->...
    class_table->twig\extension\coreextension->methods->getattribute->...
    class_table->generatedtest0->methods->runbare->op_array
    class_table->composer\autoload\composerstaticinit32e1def09fefbf05b3038ecf2fa0a6e2->...

Origin: `src/Lib/PhpProcessReader/PhpMemoryReader/Collector/Job/EmitClassTableJob.php:101-111`
uses `$bucket->key` (the lookup key, which PHP lower-cases for
case-insensitive dispatch) as the node label:

    $zend_string = $ctx->dereferencer->deref($bucket->key);
    $class_name = $zend_string->toString($ctx->dereferencer);
    ...
    $ctx->emitNode($class_def_context, $parent, $class_name);

The canonical name is already collected as a `name` child node a few lines
below (`$class_entry->name`). The label should use that. Same issue for the
methods table at line 309. This is the single highest-ROI fix: every report
becomes dramatically more readable without changing any logic.

### B2. `dedup_candidate` impact can exceed the entire heap

A `[LOW] 722.27 MB impacted` finding on a heap whose total analysed size is
11.96 MB (see `rw_logger-stack.report.txt`). Caused by
`src/Inspector/Output/MemoryOutput/Report/Pass/DedupCandidatePass.php:92`:

    if ($retained > $shallow_size) {
        $size = $retained;
        $total = $cnt * $retained;   // overcounts shared subtree N times
    }

`retained` includes bytes in the shared subtree; multiplying by the number
of owning copies counts those bytes N times.

This is part of a broader problem — see "The `impact_bytes` semantics
problem" below for the general fix. The minimum patch to kill the
"greater than total heap" embarrassment is:

- Short-term: clamp to `min($cnt * $retained, $heap_total_bytes)` and
  label the impact as "(overcounted via sharing)".
- Medium-term: replace the value with a saving estimate (see section
  below).

### B3. String previews with embedded newlines break the table

Any string whose preview contains `\n`, `\r`, or `\t` wraps to the next row
and shifts the whole table layout. Carbon's doc comments are the worst
offender — every row of "Top Strings" in `rw_logger-stack.report.txt`
splits into two visible rows.

Fix: escape whitespace in `TextReportFormatter::format()` when building the
preview (around `src/Inspector/Output/MemoryOutput/Report/Formatter/TextReportFormatter.php:251-257`):

    $preview = strtr($preview, ["\n" => '\\n', "\r" => '\\r', "\t" => '\\t']);

### B4. Severity threshold is inconsistent across finding kinds

Two symptoms of the same underlying issue: severity is assigned by
each pass in isolation, using a rule tailored to that pass, so the
scale isn't comparable across findings.

#### B4a. `dominant_class` emits HIGH on ratio alone

In tiny heaps the absolute number is meaningless but the finding
still fires HIGH:

    [HIGH] 720 B impacted — laravel-collections run
    [HIGH] 10.94 KB impacted — phpunit run

A finding that accounts for under ~1 MB should never be HIGH
regardless of percentage. Gate HIGH on `impact_bytes >= HIGH_MIN_BYTES`
in addition to ratio.

#### B4b. `cycle_cluster` severity needs a smarter signal than retained alone

An earlier draft of this section claimed that a 53.90 MB retained
cycle on a 59 MB heap must be HIGH. That's too fast. In
`rw2_spreadsheet-xlsx.report.txt`:

    [LOW] 53.90 MB impacted
      cycle_cluster: 1 identical cycle (15 classes, 6.70 KB shallow,
                                        53.90 MB retained)
      Back-reference: cellXfSupervisor

"Cycle exists with large retained" doesn't mean "breaking the cycle
frees that retained". PHP has a cycle GC, and in a live-process
snapshot everything visible is still reachable. The actionable
question is:

    "If I broke the back-reference right now, would the subtree
     actually be freed, or is it kept alive by some other tree-edge
     from a live root?"

For the `cellXfSupervisor` cycle, the Style subtree is *also*
reachable via `$spreadsheet->cellXfSupervisor` forward edges. Breaking
the back-reference alone wouldn't free the 53.90 MB — the supervisor
still owns everything via tree edges. In that light, LOW is not an
obvious mis-assignment; it's plausibly the correct severity, and my
earlier "clearly wrong" call was based on reading `retained` as if it
were `saving`.

The right signal would be something like:

    free_if_cycle_broken =
        retained(subtree)
      − retained(subtree via non-cycle tree edges)

- If > 0 and large relative to the heap → cycle is the last path;
  breaking it actually releases the subtree. High severity.
- If ≈ 0 → subtree is owned by live roots independently of the cycle;
  breaking the back-reference changes nothing. Low / Info severity.
- If partial → medium.

The substrate already distinguishes tree and non-tree edges, so this
is computable, though it likely needs a dedicated traversal per
cycle-cluster finding. The current severity assignment may already be
doing something along these lines — worth verifying before changing
the heuristic.

Separately: the finding's `impact_bytes` currently reports the
subtree's `retained`, which misleads the reader into treating it as a
saving estimate. Re-labelling to `current_retained_bytes` (it's not a
lever, it's a size) plus adding `saving_if_broken_bytes` when the
traversal is cheap enough would fix that side too.

### B5. `cycle_cluster` treats WeakMap as a cycle (false positive)

    Per cycle: 1x WeakMap
    Example: $log->fiberLogDepth
    Example: $twig->parser->parsers->precedenceChanges

`WeakMap` entries use weak references and don't retain their keys, so by
definition they don't form a retain-cycle. Skip WeakMap in cycle detection
(or exclude edges originating from its internal storage).

### B6. `dedup_candidate` mixes members of different classes under one group key

    dedup_candidate: value (Monolog\Handler\TestHandler):
        3,001 copies x 246.45 KB retained = 722.27 MB
    Examples: Monolog\Handler\TestHandler (152B),
              Monolog\LogRecord (152B),
              Monolog\LogRecord (152B)

The heap has one `TestHandler` and 3,000 `LogRecord` instances; both happen
to share a 152-byte shallow size, so they end up in the same dedup bucket
keyed only by slot position and shallow size. Group key should include
target class (or require sample-class homogeneity before reporting).

### B7. `bottleneck_path` leaf can stop on a structural node, not on data

    bottleneck_path: class_table->...->dynamic_function_definitions->0->op_array (5.46 MB)

`op_array` is a container (opcodes + literals + static_variables +
dynamic_function_definitions + doc_comment). Reporting a retained size on
the `op_array` node itself tells the reader nothing about *what* is heavy.
The descent should continue to a data-carrying leaf (string, array, object,
or a specific structural bucket like `static_variables`) and show a
breakdown of what's under the last meaningful container. See also S1.

### B8. `bottleneck_path` shows the leaf path but reports the root's size

Observed in `rw2_json-decode-huge.report.txt`:

    [HIGH] 171.45 MB impacted
      bottleneck_path: $decoded[data][10100][profile] (171.45 MB)

The heap is 171.81 MB. `$decoded` holds 84.33 MB. `$decoded[data]` holds
84.32 MB across 25,000 uniformly-sized children. A single `[10100]`
profile sub-array cannot possibly retain 171 MB.

Root cause is in `src/Inspector/Output/MemoryOutput/Report/Pass/DrillDownPass.php`:

1. `analyze()` descends the heaviest-child spine for up to 12 depths
   (line 59–95), recording each step's subtree size into `$path_sizes`.
2. The descent doesn't check for a *sudden drop in dominance*. Even when
   the heaviest child retains 1/25,000 of the parent, the descent
   continues.
3. Line 110: `$total_size = $path_sizes[0]` — the reported impact is
   always the **root of the spine's** retained size.
4. Line 132–135: the summary prints the full leaf path alongside that
   top-of-spine size.

Result: the reader sees a deep leaf path and a giant number, and connects
them as "this leaf is huge", which is false. The summary's path and its
size refer to opposite ends of the descent.

Fixes (compatible, pick one or both):

- Stop the descent when the heaviest child's size drops below some
  fraction of the parent's (say 0.5). That's the "dominance threshold"
  that makes the spine meaningful.
- Report the leaf's size, not the root's. If we want both, use a range
  like `(171 MB → 3.4 KB along this spine)` or separate fields
  `impact_bytes: 3.4 KB, spine_root: $decoded[data], spine_root_bytes: 84 MB`.

### B9. Uniform siblings pick an arbitrary index as the "leaf"

Closely related to B8. The existing `selectSummaryPath` only checks for
multi-sibling at the **very last** step of the descent (line 182–191).
Intermediate uniform-sibling points (e.g., picking `[10100]` among 25,000
equals-sized children at depth 2) go undetected; the path is presented as
if index 10100 were somehow chosen for a reason.

Desired behaviour: detect the first depth at which the chosen child is
one of N≥K uniformly-sized siblings (coefficient of variation below some
threshold), stop there, and render as

    bottleneck: $decoded[data]  (84.3 MB across 25,000 uniform children
                                 @ ~3.4 KB each — weight is distributed,
                                 no single spine)

rather than descending further.

### B8/B9 open design question: what *is* `bottleneck_path`?

Applying any sensible cutoff to the descent (stop at dominance drop, stop
at uniform siblings) turns `bottleneck_path` into "the chain leading up
to the first `choke_point`". At which point it's fair to ask:

1. Is `bottleneck_path` doing anything that `choke_point` and
   `Root Blame Allocation` (already present) don't?
2. If not, should it be deleted rather than patched?
3. If it should exist, what is its **specific definition** that isn't
   covered by the other two?

Candidate definitions, each with a concrete display:

**(a) Delete it.** `Root Blame Allocation` already tells us which root
holds most, and `Top Arrays` lists userland variables by retained size.
Between those two, `bottleneck_path` adds no information a reader can't
get in two extra seconds.

**(b) "Narrative chain": path from root to first fan-out.**
Define strictly as *the sequence of singly-owned (or heavily-dominated)
containers starting from the heaviest root, ending just before the first
ambiguity.*

    Memory narrative:
      global_variables (171 MB)
        → $decoded  (84 MB, sole heavy child)
          → [data]  (84 MB, $decoded's heavy child; $decoded[meta] is 1 KB)
            [stops here — 25,000 uniform children below]

    (continues in choke_point for the actual actionable target)

That's distinct from `choke_point`: this is the *orientation aid*,
choke_point is the *surgical target*. Two different jobs on the same
underlying graph.

**(c) Unified tree view that replaces both.**
Drop the separation entirely. One section, one shape:

    === Where the memory lives ===
    global_variables (171 MB, 94% of heap)
      ├─ $decoded       84.3 MB   ← choke point (25k uniform children)
      │    └─ [data]    84.3 MB   ← 25,000 × ~3.4 KB (uniform)
      ├─ $items         68.4 MB   ← likely duplicate of $decoded[data]
      └─ $api_response  18.7 MB   ← raw JSON string kept after decode

This is the tree the user actually wants. `bottleneck_path`, `choke_point`,
and at least part of `Top Arrays` collapse into it.

Resolving this is prerequisite to fixing B8/B9 properly: the patches
above are only correct if we've decided what `bottleneck_path` is *for*.
Until then, any cutoff we pick is arbitrary and will just move the
confusion from "why index 10100?" to "why does it stop at `[data]` but
not at `$decoded`?"

A concrete recommendation: pursue **(c)** as the long-term target, with
**(b)** as the near-term refactor that removes the misleading
leaf-path-with-root-size display without tearing up the report layout.

---

## Design axis: Finding-centric vs. Narrative-centric output

Much of the criticism above amounts to "the text output would read better
as one coherent story". That's a presentation preference, not a verdict
on the underlying engine. It's worth separating the two:

**Finding-centric (what the engine already is).**
Many passes each look at the graph from one angle and emit independent
Finding records. Multiple findings converging on the same target is a
*triangulation signal* — each detector is an independent witness, so
convergence increases confidence. This is a valid and useful design when
the consumer is programmatic (JSON, MCP server, rmem:live,
downstream analysis) or when the reader is an expert triangulating across
probes.

**Narrative-centric (what the text formatter arguably should be).**
One coherent story per report: "memory lives here, because of this, and
you can lever that". Findings are the raw material, but the output
sequences them, merges duplicates, and adds connective tissue. This is
what a first-time reader skimming a report actually wants.

The current setup feeds **Finding-centric** data directly into the text
formatter, so the text output inherits the internal structure of the
engine and reads like a detector dump. That's the real tension — not
that the engine is wrong.

### Clean split

- Keep the Finding engine as-is. It produces value for JSON/MCP/other
  programmatic consumers and the triangulation signal it carries is a
  feature.
- Let `JsonReportFormatter` render findings faithfully (current
  behaviour).
- Let `TextReportFormatter` evolve toward a narrative view: cluster
  findings by target node, choose one representative per cluster,
  present as a tree/story. The other clustered findings appear as
  "also detected by" evidence lines.

That split makes almost every S-tier item above (S2 / S4 / S5 / S6 /
S12) a change local to the text formatter rather than to passes.

### Lighter fix: annotate rather than merge

A cheaper option that sidesteps clustering entirely: give each finding
kind a one-line definition at the point of display, so the reader can
correctly interpret the number even when the framing is non-obvious.

For `bottleneck_path`, this collapses B8 from "bug" to "missing label":

    [HIGH] bottleneck_path (heaviest-child drill-down from the root)
      171.45 MB at the top of this spine

      Spine:
        $decoded → [data] → [10100] → [profile] → ...
        (mass drops sharply after [data] — distributed across 25k
         similar-sized siblings below)

    Explore: rmem:explore --node=...

With that explicit framing, the user reads the output correctly (they
went in with the right mental model: "a descent-from-root probe that
ended up somewhere big") even when the shown leaf is an arbitrary
representative of many uniform siblings.

This is a cheaper, earlier-shippable version of (b) that doesn't require
changing the descent logic — just the formatter.

---

## Scope: manage via tool-level docs, not per-report findings

Observed while running `rw2_xml-dom-huge.report.txt`:

    memory_get_usage(): 43.25 MB | peak: 43.25 MB | RSS: 395.40 MB
    Heap: 42.85 MB (99.1% analyzed)

`99.1% analyzed` is accurate within the ZendMM scope. The report
doesn't see the 352 MB held by libxml2's DOM tree because that memory
is allocated via malloc() outside Zend's memory manager.

An earlier draft of this doc proposed a `non_zendmm_memory` Finding.
That's the wrong layer. The scope of reli's memory analyser — "what
reli can see and what it can't" — is a *property of the tool*, not a
per-capture anomaly. Firing a Finding on every DOMDocument-heavy
script would spam users who already understand the scope and would
repeat documentation at a bad time.

### Better place for this: tool-level docs

Add a scope section to `docs/getting-started.md` (and the README's
"What it does" block) that says plainly:

- The memory report analyses PHP's ZendMM heap — allocations made
  via Zend's memory manager (zvals, HashTables, objects, strings,
  op_arrays, class definitions).
- It does **not** analyse memory allocated outside ZendMM. That
  typically includes:
  - C extension working memory: libxml2 (DOMDocument / SimpleXML),
    Imagick, GD, open PDO cursors holding full result sets, PCRE JIT
    on large patterns, etc. Often 5–100× the apparent PHP memory.
  - glibc arena fragmentation (commonly 20–40% of working set).
  - Opcache shared memory and anonymous mmap regions.
- Symptom: `memory_get_usage()` and the report's "Heap" value are
  small (MB range), but the process RSS is large (hundreds of MB or
  more). If you're hitting "Allowed memory size of X bytes exhausted"
  with that profile, the culprit is almost certainly outside this
  report's scope.
- Where to look instead: `inspector:memory:dump/inspect` exposes the
  full memory map (anonymous / mmap regions can be sized there);
  `strace`, `/proc/<pid>/maps`, `ps`, and per-extension debug modes
  (libxml's `xmlMemoryStrdup`, etc.) live outside reli.

### Lightweight in-report signal

A full Finding is too heavy, but a one-line annotation costs nothing
and helps readers who skip docs:

- Always show RSS alongside Heap in the Overview line (already
  happens — keep it there).
- When `RSS / analyzed_heap ≥ 10×` *and* the absolute gap is large
  (say ≥ 100 MB), add one line immediately under the Overview:

        Note: RSS is 9.2× the analysed heap (352 MB outside ZendMM).
              See "Analysis scope" in getting-started for what this
              report does and doesn't cover.

That's a gentle nudge for the pathological case, not a recurring
alarm.

### Data calibration

Scenarios captured so far:

| Scenario         | RSS     | Heap   | Ratio | Gap    | Note     |
|------------------|---------|--------|-------|--------|----------|
| csv-mega         | 533 MB  | 462 MB | 1.15× |  71 MB | no note  |
| json-decode-huge | 242 MB  | 172 MB | 1.41× |  70 MB | no note  |
| xml-dom-huge     | 395 MB  |  43 MB | 9.2×  | 352 MB | **note** |

Thresholds chosen so glibc/mmap overhead (≤ 1.5×) never fires, and
libxml2-scale gaps do.

---

## The `impact_bytes` semantics tradeoff

Findings are sorted by `impact_bytes` descending throughout the report,
which assumes the field means the same thing across findings. Today it
doesn't — but the mixing is best read as a *pragmatic compromise*, not
pure sloppiness. The field has to do two jobs at once:

- give the reader a single "sort by importance" axis, and
- carry whatever size estimate the pass naturally computes.

Those two jobs pull in different directions. Making `impact_bytes` strict
(e.g., always-saving) gives clean semantics but loses the "everything in
one ranked list" affordance — a pass that only knows informational sizes
(dominant_class, property_scaling) would drop off the top of the list
even when its observation is the most important context for the reader.
Making it pragmatic (the current state) keeps the unified sort but
produces the fictional numbers described above.

### Current meanings, finding-by-finding

| Finding kind           | What `impact_bytes` is                        | Kind              |
|------------------------|-----------------------------------------------|-------------------|
| `choke_point`          | retained subtree that would free              | actionable        |
| `structural_duplicate` | total - one_representative                    | actionable        |
| `cycle_cluster`        | retained of the cycle                         | actionable        |
| `bottleneck_path`      | retained at the **root** of the spine         | informational     |
| `dominant_class`       | total class memory                            | informational     |
| `property_scaling`     | sum of per-instance property memory           | informational     |
| `companion_cluster`    | sum of companion class memory                 | informational     |
| `empty_object`         | total object memory                           | informational     |
| `dominant_type`        | total type memory                             | informational     |
| `dedup_candidate`      | **cnt × retained** — double-counts sharing    | **fiction**       |
| `dynamic_properties_overhead` | `avoidable` HashTable overhead         | actionable        |

Three different things share one field name:

1. *Actionable*: "if you fix this, you'd save up to this much."
   Bounded above by the heap total. Good to sort on — sorting actually
   means something.
2. *Informational*: "how much memory fits this pattern."
   Bounded above by the heap total. Sortable, but sorting-by-this mixes
   "this is a lot of memory" (informational) with "you can save this
   much" (actionable) into one list.
3. *Fiction*: `cnt × retained` when retained includes shared subtree.
   Unbounded — can exceed heap total (logger-stack: 722 MB impact on
   11 MB heap). Not sortable in any meaningful sense.

Sorting a list of mixed (1)/(2)/(3) by "impact_bytes desc" effectively
ranks *fictions first*, *informational next*, *actual actions last* —
exactly backwards.

### Candidate resolutions

Three ways to resolve the tradeoff, ordered from least to most
structural change:

#### (A) Unify on `current_bytes`, keep the single sort axis

Fix `impact_bytes` to always mean *"how much memory currently fits this
pattern"*. Always ≤ heap total. The saving estimate, when the pass has
one, moves to a separate `saving_estimate_bytes` field shown in the
body of the finding but not used for ranking.

- Preserves the "everything in one ranked list" affordance.
- Eliminates the fictional-numbers problem (dedup_candidate etc.
  stop overshooting heap).
- Loses the "lever with the biggest saving floats to the top" sort,
  which was implicit in the actionable subset of the existing mix.

Minimum code churn; JSON schema only gains a field.

#### (B) Split the field, give up the unified sort

Rename `impact_bytes` into two narrowed fields and define them strictly:

- `current_bytes` (optional): "how much memory currently fits this
  pattern". Always ≤ heap total. Carries the informational quantity.
- `potential_saving_bytes` (optional): "how much memory an action on
  this finding could save, at best". Always ≤ `current_bytes`.
  Carries the actionable quantity.

Sort on `potential_saving_bytes` when present, falling back to
`current_bytes`. That gets actionable findings to the top naturally —
at the cost of burying informational orientation ("your heap is 96%
LogRecord") below action items that may be much smaller.

Per-kind mapping:

| Finding kind           | `current_bytes`              | `potential_saving_bytes`    |
|------------------------|------------------------------|-----------------------------|
| `choke_point`          | retained subtree             | retained subtree            |
| `structural_duplicate` | total instances              | total − one_representative  |
| `cycle_cluster`        | retained cycle               | retained cycle              |
| `dedup_candidate`      | total occurrences × shallow  | total − one_representative  |
| `bottleneck_path`      | leaf retained                | —                           |
| `dominant_class`       | total class memory           | —                           |
| `property_scaling`     | sum of per-instance props    | —                           |
| `companion_cluster`    | sum                          | —                           |
| `empty_object`         | total object memory          | —                           |
| `dominant_type`        | total type memory            | —                           |
| `dynamic_properties_overhead` | structural bytes      | avoidable bytes             |

Two consequences:

- `dedup_candidate` stops producing fictional numbers — the
  "722 MB impact on 11 MB heap" problem goes away definitionally.
- The text formatter can display one line as
  "Saves up to X (of Y currently in this pattern)" which reads as an
  actual answer rather than an opaque number.

#### (C) Add a composite `rank_score`, keep raw bytes untouched

Keep `impact_bytes` as a raw size on every finding (whichever quantity
the pass naturally produces), but stop sorting by it. Add an explicit
`rank_score` derived from multiple factors:

    rank_score = f(
        bytes,               # current or saving, whichever the pass emits
        severity,            # High / Medium / Low / Info
        confidence,          # High / Medium / Low
        actionability,       # "lever" vs "observation"
        heap_fraction,       # bytes / heap_total — normalises magnitude
    )

Report sorts on `rank_score`. Raw bytes remain visible for the reader
but don't pretend to be an importance measure. This is the most honest
about the fact that "importance" is a multi-factor judgement, at the
cost of tuning weights and justifying the formula.

#### (D) Expose multiple sort keys, let the caller pick

Carry `current_bytes` and `saving_estimate_bytes` as separate fields
(the (B) shape), but don't pick a primary sort — expose the choice as
a CLI flag:

    --sort-by=current     (default: "biggest patterns first")
    --sort-by=saving      ("biggest levers first")
    --sort-by=severity    ("most alarming first")
    --sort-by=heap-fraction  ("most dominant first")

JSON consumers sort themselves; the tool just provides the data.
Text output picks the flag's value as its sort key and notes the
choice in the report header ("sorted by: saving (--sort-by=saving)").

Honest about the fact that the "right answer" is probably
situational — a CLI user investigating an OOM wants savings first, a
reviewer glancing at a report wants orientation (biggest patterns)
first. The tradeoff sidesteps itself by deferring it to the caller.

This is somewhat "escapist" — defaults still have to be picked, and
most users will never touch the flag, so whatever default we choose
gets judged as if it were the only answer. But it adds very little
code (one strategy switch in the formatter) and gives power users
the lever they'd otherwise patch in locally.

### Recommendation

(A) is the smallest change that removes the "greater than heap" bug
without giving up the single-axis ranking. It's a strict improvement
over today, doesn't break MCP/JSON consumers (only adds a field), and
leaves (B), (C), and (D) open as future refinements if the sort ends
up feeling wrong in practice.

If users start asking for different defaults in different situations,
pivot to (D) — it's cheap and acknowledges that there's no single
right sort. (B) and (C) are bigger schema migrations; park them until
we see that the cheaper options aren't enough.

---

## Stocked proposal: framework shape detection

Observation from `rw2_eloquent-hydration.report.txt`: the memory shape
of a Laravel Eloquent collection — `Model` base class with
`$attributes` + `$relations` + `$original` + `$exists` + `$wasRecentlyCreated`
— is a distinctive fingerprint. Once you see it, the identity of the
framework is obvious from the report alone, but the current output
describes it only in generic terms (N instances, K properties, L MB
per-instance). The reader has to infer "this is Eloquent" before they
can reach the correct advice (`LazyCollection`, `chunkById()`,
`->setRelations([])`).

A `shape_detection` pass could match common signatures and replace the
one-size-fits-all `Next:` hints with framework-specific levers.

Candidate signatures (all match by class name + required property set
+ rough scale):

| Framework / library | Signature                                          | Lever                                                        |
|---------------------|----------------------------------------------------|--------------------------------------------------------------|
| Laravel Eloquent    | Model base + `$attributes`, `$relations`, `$original`, `$exists` | `LazyCollection`, `chunkById(N)`, `->setRelations([])` in loops |
| Doctrine ORM        | `UnitOfWork` with populated `$identityMap` / `$entityStates`     | `$em->clear()` per batch; `iterate()` for hydrators           |
| Symfony EventDispatcher | `EventDispatcher` + populated `$listeners` at large scale     | Lazy listener services, lazy event subscribers                |
| Monolog + big buffer | `Monolog\Handler\TestHandler` / any handler with many records  | TestHandler is meant for in-process tests; use a StreamHandler / RotatingFileHandler |
| Guzzle debug history | `GuzzleHttp\Middleware::history` container with many entries   | Remove `Middleware::history()` in production; rotate buffer   |
| PhpSpreadsheet      | `Spreadsheet` with many `Cell` / `Style` objects                | `setReadDataOnly(true)`, cell caching backend, `SimpleCache`  |
| Twig warm cache     | `Twig\Environment` + many `$loadedTemplates`                   | Usually expected — flag only if growth is unbounded           |
| nesbot/Carbon       | Many `Carbon\Carbon` instances with pinned state               | `CarbonImmutable` or plain `DateTimeImmutable`                |

### Shape matching is cheap

Each shape is a predicate over the substrate:

    (class_name matches pattern) AND
    (object has all of {prop_a, prop_b, ...}) AND
    (instance_count >= threshold) AND
    (sum_of_retained >= bytes_floor)

Running a dozen of these after the existing passes costs little and
keeps the rest of the pipeline clean — failures are soft (no match,
no finding). The result would usually fire exactly once per known
framework present in the capture.

### Output shape

Replace or annotate the generic property_scaling / structural_duplicate
findings for objects that matched a shape:

    [HIGH] laravel_eloquent_hydration: 100,000 User + 300,000 Order models
           currently retaining 310 MB
      Shape signature: Illuminate-style Model (Attributes+Relations+Original)
      Typical cause: `User::all()` / `->with(...)` on a table larger than
                     memory budget, or accumulation inside a loop without
                     `->setRelations([])` / chunking.
      Levers:
        - Switch the loop to `User::cursor()` or `User::lazy()` for
          streaming
        - Use `chunkById(1000)` if per-item processing needs to commit
        - Call `$model->setRelations([])` per iteration to drop the
          eager-loaded ownership chain
        - If you only need a subset of columns, `->select()` them
      Docs: https://laravel.com/docs/eloquent#chunking-results

That's dramatically more useful than "300,000 instances, 137 MB in
`$attributes`, consider lazy init".

### Boundaries

- Signatures live in a data table (or YAML), not hard-coded in the
  pass — so adding a framework is a data change.
- Shape detection is informational / actionable hints, not ground
  truth — if the signature is wrong, the worst case is a wrong
  hint, not a crash.
- Keep the existing generic findings available (JSON always includes
  them); the text formatter can demote the generic ones when a
  matching shape fires.

---

## Structural / presentation issues

### S1. Root-blame guidance is one-size-fits-all

Every `bottleneck_path` finding carries the same `Next:` hint:

    Next: Examine the leaf for the actual data consuming memory;
          Check if the accumulation can be bounded or streamed

That advice is correct for userland containers. It is irrelevant for
`class_table` origins, where "bound or stream" makes no sense. Similarly
for `function_table`, `interned_strings`, and `call_frames` origins.

**Important constraint.** Do not suppress or hide class_table findings:
when `class_table` genuinely dominates a small heap, the user's only lever
really is to reduce loaded class definitions, and the finding is factually
correct in pointing there. The fix is in the guidance wording, not in
filtering the finding.

### S2. `bottleneck_path` and `choke_point` repeat the same fact

Across all 7 real-world runs the top HIGH `bottleneck_path` and top HIGH
`choke_point` share the same (or near-identical) retained size and point
at overlapping subtrees:

| Scenario        | bottleneck_path | choke_point |
|-----------------|-----------------|-------------|
| logger-stack    | 5.46 MB         | 4.70 MB     |
| phpunit         | 5.80 MB         | 5.80 MB     |
| twig            | 3.03 MB         | 3.03 MB     |
| symfony-console | 2.14 MB         | 2.14 MB     |
| psr-7-stack     | 1.77 MB         | 1.77 MB     |

They are two views of the same phenomenon; one HIGH entry per phenomenon
is enough. Merge the two or demote the weaker into evidence of the other.

### S3. `DefinedClassesContext` choke-point adds no information

    choke_point: DefinedClassesContext (0 B shallow)
        holds 5.80 MB via 448 children — class_table

0 B shallow, N direct children. This is "you loaded N classes, totalling
X MB" restated as a finding. Collapse to a single Overview line
(`Loaded class definitions: N classes, X MB`) and drop the finding entry.

### S4. `companion_cluster` and `ownership_pattern` duplicate each other

Logger-stack emits both a `companion_cluster` and an `ownership_pattern`
for the same pair (`LogRecord` pairs 1:1 with
`JsonSerializableDateTimeImmutable`). Twig emits three matching pairs.
These are the same observation from two angles. Merge into a single
"1:1 ownership" finding and drop the companion cluster when coverage is
100%.

### S5. `shared_singleton` floods Additional Info with framework internals

PHPUnit run emits 14 lines like:

    [shared_singleton] assertarrayhaskey: 201 refs -> 1 target [singleton]
    [shared_singleton] assertarrayisequaltoarrayignoringlistofkeys: ...
    [shared_singleton] assertarrayisidenticaltoarrayignoringlistofkeys: ...

These are interned method name strings pointing to a single zend_string
per name — expected behaviour, zero actionable value. Collapse to one
summary line when the source is in `class_table` / `function_table` /
`interned_strings`:

    [shared_singleton] Interned identifiers from class/function tables:
        14 groups collapsed (201 refs each, all expected)

### S6. Long inline lists wrap badly

Twig's companion_cluster line is 320+ characters on a single line:

    companion_cluster: 6 classes x ~180 instances (151.88 KB):
    Twig\Node\Expression\ArrayExpression (180, 32.34 KB),
    Twig\Node\Expression\Variable\ContextVariable (180, 32.34 KB),
    Twig\Node\Expression\FilterExpression (180, 29.53 KB),
    ... (truncated)

Switch to one-line-per-class vertical layout with two aligned columns
(class, count, bytes).

### S7. `cycle_cluster` with zero classes produces empty output

    cycle_cluster: 1 identical cycle (0 classes, 152 B shallow, 3.86 KB retained)
    Per cycle:                  <-- empty after the colon
    Example: class_table->...->op_array->static_variables

A cycle inside internal op_array `static_variables` with no classes
involved is not actionable at the user level. Either suppress when
`classes_count == 0` or label clearly as "VM-internal cycle (not
user-actionable)".

### S8. Class name truncation hides namespace discriminator

    2  ...log\JsonSerializableDateTimeImmutable    3,000 instances
    3  ...e\Expression\Variable\ContextVariable      180 instances
    4  ...y\Component\Console\Input\InputOption       41 instances

The current truncation keeps the last 37 characters with a leading `...`.
That works for short class names but erases the namespace prefix for the
most common case — long namespaced framework classes. Prefer a middle
truncation that keeps the top-level namespace and the tail:

    Twig\...\Variable\ContextVariable
    Symfony\...\Input\InputOption

### S9. Overview is a 180-character single line

    memory_get_usage(): 11.96 MB | memory_get_usage(true): 14.00 MB |
    peak: 11.96 MB | memory_limit: 128.00 MB | RSS: 70.17 MB |
    Heap: 9.52 MB (79.6% analyzed), VM stack: 256.00 KB,
    Compiler arena: 896.00 KB

Wraps on 80-column terminals and hides the relationships between values.
Switch to a vertical Metric/Value table. Add a one-line gloss explaining
what "unaccounted" means (allocator pages not linked into the reachable
graph — not necessarily a leak).

### S10. `shared_fanin` `?` target is undocumented

    [shared_fanin] key -> ? (21,023 refs -> 30 targets, 700.8 each)

`?` = target class couldn't be resolved. No legend anywhere. Use
`(unresolved)` or similar. Separately, `key` / `value` / `name` as the
only identifier is ambiguous — qualify with where it came from (e.g.,
`HashTable bucket key (interned)` or `property: <owner>::<$prop>`).

### S11. "Collapse to DAG for exact" points at nothing

    [retained_approximate] 2 cycles (7 nodes) — retained size is approximate;
        collapse to DAG for exact

There is no visible flag or command to trigger DAG collapse. Either
remove the instruction, describe the operation more completely, or (if
planned) point at the CLI flag that enables it.

### S12. Multiple detectors reporting the same underlying problem

Same underlying phenomenon often surfaces as several separate findings
because each pass is scoped to one detection angle. Practically this
redundancy is a *signal* (multiple converging detectors = higher
confidence) but the current flat rendering repeats the same facts.

Observed pairings:

**bottleneck_path + choke_point** (7/7 runs)
Same node, same retained size, adjacent paths — the narrowest and the
owning container of the same subtree. Already listed as S2.

**dominant_class + property_scaling + structural_duplicate +
companion_cluster + ownership_pattern**
In `rw_logger-stack.report.txt`, `Monolog\LogRecord` appears as all five:
*it's the dominant class*, *its per-instance props scale linearly*,
*instances share the same shape*, *it always pairs with
JsonSerializableDateTimeImmutable*, *that partner is 1:1-owned*. All are
restatements of "you have 3,000 LogRecord instances".

**choke_point + choke_point** (in `rw2_json-decode-huge.report.txt`)
`$decoded[data]` at 84 MB and `$items` at 68 MB — these two arrays carry
the same dataset because `$items` wasn't unset before the `json_encode` →
`json_decode` round trip. Not a duplicate finding (both are real), but
the *relationship* ("these two roots hold equivalent data") is the
interesting insight and it isn't surfaced.

**dedup_candidate + structural_duplicate** (2/7 runs)
Both detect "many identical-shape instances". One emphasises slot-level
dedup, the other class-level shape equivalence, but to a reader they look
like the same thing described twice.

### Proposed: "problem entity" clustering

Group findings by their primary target node/class and render one entry
per problem with multiple detection reasons stacked underneath:

    [HIGH] $log->handlers->referenced[0]->records
           (4.70 MB retained, 3,000 children)
      Confirmed by 5 detectors:
        • choke_point — small container (47 KB) → 4.70 MB subtree
        • bottleneck_path — heaviest retained branch from $log
        • dominant_class — Monolog\LogRecord is 53.9% of object memory
        • companion_cluster — LogRecord pairs 1:1 with JsonSerializableDT
        • property_scaling — 3,000 × 1.44 KB/instance (retained)
      Levers:
        • Apply a size cap or TTL to the handler's record buffer
        • Swap TestHandler for a rotating file/Stream handler
      Explore: rmem:explore --node=7812

Implementation note: add a post-processing pass that joins findings by
evidence_node_ids and/or source_class, then groups them. The formatter
renders the group rather than each finding individually. When only one
detector hit a target, render unchanged — there's no cluster to form.

The pairing detection can also produce new derived findings that aren't
currently generated, such as "$items and $decoded[data] retain
equivalent data — remove one". That kind of *relationship* finding is
more actionable than either of the independent choke_points alone.

---

## Stocked design proposal: `class_definition_overhead` with per-class breakdown

Do **not** implement yet. Stored here for later — see sample-size caveat
at the top; users who genuinely hit this are rare, and the current
investment budget should go to higher-prevalence issues first.

### Motivation

When `class_table` dominates a small heap, the `bottleneck_path` output is
accurate but unhelpful because:

1. The descent chain is mostly structural nodes (`methods`, `op_array`,
   `dynamic_function_definitions`) that don't individually correspond to
   user-actionable weight.
2. The `Next:` hint ("bound or stream") is written for userland
   accumulation and doesn't apply to loaded class definitions.
3. The leaf can be any of several very different things — a doc comment,
   a nested closure, a static variable, raw opcodes — each implying a
   different fix.

### Proposed finding shape

    [HIGH] 5.46 MB impacted
      class_definition_overhead: Carbon\Carbon (5.46 MB retained)

      Heaviest retained branch within this class:
        methods->get->op_array->dynamic_function_definitions->0->op_array

      Breakdown under Carbon\Carbon:
        doc_comment strings ............. 2.31 MB  (42%)  in 180 locations
        dynamic_function_definitions .... 1.84 MB  (34%)  456 closures
        opcodes + literals .............. 0.71 MB  (13%)  1.2M instructions
        static_variables ................ 0.38 MB  (7%)   across 12 methods
        property_info / other ........... 0.22 MB  (4%)

      Read this as:
        - doc_comment dominant → strip comments (opcache save_comments=0)
          or remove the library
        - static_variables dominant → unbounded static cache inside a
          method (often memoisation that forgets to evict)
        - opcodes dominant → unusually large compiled code: generated
          code, unrolled loops, giant match expressions
        - dynamic_function_definitions dominant → many closures defined
          inside methods (closures-in-a-loop or generator patterns)

### Why "breakdown" instead of "truncate at class"

An earlier iteration of this proposal suggested truncating paths at the
class name and skipping the descent. That's wrong — `op_array` sizes are
*sometimes* actionable (auto-generated code, pathological doc comments,
runaway static caches). The descent is information; we shouldn't drop it.
Adding the breakdown lets both the common "it's just framework baseline"
case and the rare "our codegen blew up the opcode table" case read the
same report and reach the right conclusion.

### Implementation sketch

- New finding kind `class_definition_overhead` (and later
  `function_definition_overhead`) emitted by a new pass or by
  `ChokePointPass` when the ancestor chain starts in `class_table`.
- Breakdown is computed by summing retained size of tree children of the
  class-definition node grouped by `link_name` category
  (`doc_comment`, `static_variables`, `dynamic_function_definitions`,
  `opcodes`, `property_info`, ...).
- `TextReportFormatter` grows a new renderer branch for the finding that
  shows the breakdown table and the category-specific guidance block.
- Same shape reusable for `function_table` (userland functions with huge
  op_arrays) and `global_variables` (Top N variables by retained within
  that root).

### Gating

Ship only after B1 is in — the breakdown output still contains class
names, and displaying them lower-cased would undo most of the readability
win.
