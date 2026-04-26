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

## Verification status

After the initial observation pass produced several walk-backs under
closer reading, every testable claim below has been re-checked against
the actual implementation. Status at time of writing:

| Item | Claim                                                                                | Verified against                                                         | Status                                                                                     |
|------|--------------------------------------------------------------------------------------|--------------------------------------------------------------------------|--------------------------------------------------------------------------------------------|
| B1   | Class / method names lower-cased in path labels                                      | `EmitClassTableJob.php:101` (`$bucket->key`), `NodeLabeler` only handles frames | Confirmed. Fix has two shapes: emit-time (use `$class_entry->name`) or display-time (extend NodeLabeler to read `name` child). |
| B2   | `dedup_candidate` impact can exceed heap total                                       | `DedupCandidatePass.php:85–92`                                           | Confirmed as observation, **mechanism corrected**: not "cnt × retained double-counts shared subtree" — it's sample averaging over a **heterogeneous bucket** (B6). Fix follows from B6. |
| B3   | Preview strings with embedded newlines break the table                               | `TextReportFormatter.php:251–257` (no whitespace escape)                 | Confirmed. 1-line fix.                                                                     |
| B4a  | `dominant_class` HIGH severity on ratio alone                                        | `ClassRankingPass.php:57` (`$pct > 50.0` with no absolute floor)         | Confirmed.                                                                                 |
| B4b  | `cycle_cluster` under-reports severity                                               | `GcPendingPass` already separates GC-only cycles; zero `gc_pending_candidate` in 14 real reports | **Retracted.** LOW is the right label for structurally-reachable cycles; real leaks are emitted separately. |
| B5   | `cycle_cluster` flags WeakMap as false positive                                      | `EmitWeakMapJob` emits ht via `EmitArrayDirectJob`                       | **Needs investigation.** Observed but unclear whether the collector is emitting WeakMap entries with strong edges (real bug) or the SCC detection crosses through WeakMap correctly (acceptable). Downgrade to open question. |
| B6   | `dedup_candidate` groups heterogeneous classes by shallow size only                  | `DedupCandidatePass.php:195` (`GROUP BY link_name, node_size`)           | Confirmed. Root cause of the extreme B2 numbers.                                           |
| B7   | `bottleneck_path` leaf can stop on a structural container                            | `DrillDownPass` descends up to 12 levels, no leaf-kind filter            | Confirmed.                                                                                 |
| B8   | `bottleneck_path` shows leaf path with root's retained                               | `DrillDownPass.php:110` (`$total_size = $path_sizes[0]`)                 | Confirmed.                                                                                 |
| B9   | Uniform siblings produce arbitrary leaf                                              | `DrillDownPass.php:182–191` (only checks multi-sibling at last depth)    | Confirmed.                                                                                 |
| S3   | `DefinedClassesContext` choke_point is noise                                         | `ChokePointPass.php:66–80` (subtree ≥ 1 MB, ratio > 10, no kind filter)  | **Softened.** The finding is a real signal ("N classes take X MB"); it just lands as HIGH next to userland findings. Presentation issue, not "useless".  |
| S10  | `shared_fanin` `?` undocumented                                                      | `NonTreeEdgePass.php:226` (`target_class !== '' ? $target_class : '?'`)  | Confirmed; meaning is more specific than the earlier text: `?` = non-object target (HashTable key string etc.), not "unknown". |

The rest of the S items are presentational (what the text formatter
chooses to show) and don't need code-side verification — they're
accurate descriptions of what the current formatter does. The stocked
proposals (class_definition_overhead, shape detection, impact_bytes
refactor, non-ZendMM docs) are not yet implemented.

## Fresh pass: text formatter is under-using Finding facts

Comparing the JSON output (`-f report-json`) to the text output for
the same dump reveals that several of the "bugs" above are actually
**data the Finding already carries that the text formatter doesn't
render**. The Finding engine is producing enough, the text layer is
just thin.

Implications that tilt the fix strategy:

**B8 is a text-only fix.** The `bottleneck_path` finding's
`facts.sizes` already contains the full per-depth subtree size along
the descent. For `rw2_eloquent-hydration`:

    sizes = [325480905, 325479089, 325475106, 325475106, 324977898,
             3336, 3336, 3184, 2520, 2240, 2144, 2144]

The drop at index 5 (the uniform-sibling point, `$users[0]`) is right
there in the data. The text formatter renders `summary` (which uses
`sizes[0]`) and ignores `facts.sizes` entirely. A ~20-line change in
`TextReportFormatter` to detect the first large drop and render both
endpoints fixes B8/B9 presentation without touching `DrillDownPass`.

**Internal type names leak through `summary` strings.** Example from
the same dump:

    choke_point.summary = "ZendArrayTableMemoryLocation (1.53 MB shallow)
                           holds 309.92 MB via 100000 children — $users"
    choke_point.facts.path          = "$users"
    choke_point.facts.children_count = 100000
    choke_point.facts.shallow_size   = 1608544
    choke_point.facts.subtree_size   = 324977898

The facts are clean; the `summary` is what contains
`ZendArrayTableMemoryLocation`. That internal type name is produced
inside `ChokePointPass` when it builds the summary string. Two fix
shapes:

- Stop including the internal location type in the summary string
  at pass time.
- Have the text formatter re-render from `facts` instead of echoing
  `summary` verbatim.

The second is more invasive but gives the text formatter the freedom
to pick its own wording. The first is a 1-line fix.

**Text formatter already groups findings by kind.** JSON emits
`large_array`, `root_blame`, `type_ranking`, `class_ranking` as many
individual Findings; the text output aggregates those into tables
(Top Arrays, Root Blame Allocation, Type Breakdown, Top Classes).
That's exactly the narrative-synthesis pattern the S12 "cluster by
target" proposal asks for — it's already in place for some kinds. The
missing piece is **cross-kind clustering on the same target node**
(combining `choke_point` + `bottleneck_path` + `dominant_class` that
all point at the same class), which is an extension of an existing
pattern, not a new architecture.

**Finding count: JSON 27, text ~15.** The text formatter is already
dropping/grouping half the findings before the reader sees them.
That's fine — it's the narrative layer doing its job. The items
trimmed are mostly Info-severity `root_blame`, `type_ranking`,
`class_ranking` findings that get rolled into tables. Knowing this
means the "too many findings for the same target" complaint (S12) is
already partially addressed for some kinds; the ones still wasteful
are Medium/Low findings (`dominant_class`, `property_scaling`,
`expensive_property`, `structural_duplicate`, `companion_cluster`,
`ownership_pattern`) that converge on the same class.

### Quick-win text-formatter-only fixes

Listed in order of smallness:

1. **B3** (preview newline escape) — 1 line in `TextReportFormatter`.
2. **Internal type names in summary** — either strip
   `MemoryLocation` / `Context` suffixes in the formatter, or change
   summary generation in each pass. ~5–20 lines.
3. **B8/B9** (bottleneck_path descent rendering) — ~20 lines reading
   `facts.sizes` and `facts.path`, detecting the first large drop,
   rendering with both endpoints.
4. **S6** (long inline lists) — switch `companion_cluster` and
   similar single-line lists to vertical rendering when > 3 items.
   ~15 lines.
5. **S5** (`shared_singleton` flood) — cap at N items + one summary
   line, ~10 lines.

All five are additive; none need pass-level changes, JSON schema
changes, or MCP migration notices.

### Bigger items that need pass-level changes

- B1 (class name case) — collector-side change
- B2/B6 (dedup heterogeneous bucket) — SQL change in
  `DedupCandidatePass::loadDedupRowsFromSql`
- B4a (`dominant_class` severity floor) — one line in `ClassRankingPass`
- B5 (WeakMap edge strength) — collector-side investigation
- S12 full clustering — text formatter, but needs a new post-processing
  step that joins findings by target across kinds

### Text formatter chartering (normative)

Promote the descriptive observation above to a working principle for
new findings and ongoing maintenance.

**Principle**: The text output is a human-friendly rendering of the
same `Finding.facts` that JSON exposes. It is *not* a separately
authored narrative that summarises the analysis at lower fidelity.

What this commits us to:

1. **Don't invent prose that elides structured data already in
   facts.** If a fact array carries per-step retained sizes
   (`bottleneck_path.facts.sizes`), per-child shape (a future
   `choke_point.facts.children_distribution`), or example lists
   (`dedup_candidate.facts.examples`), the text formatter renders
   them — with whitespace and labels for legibility — rather than
   collapsing to a single summary number.
2. **If the text formatter wants to express a derived insight,
   either compute it from facts at render time or promote it into
   facts.** "Promote into facts" is preferred when the insight
   benefits JSON consumers too (MCP, `rmem:viz`, `rmem:serve`).
3. **Categorical labels invented by the text formatter
   (`concentrates / shared / tapers / distributed`) should be
   derived rules over numeric facts**, not pass-level pre-classification
   that fixes the user's interpretation. Keep `facts` numeric;
   apply categorical reading at render time so JSON consumers can
   apply their own.
4. **Self-test for new text rendering**: "could a JSON consumer
   derive this same insight from `Finding.facts`?" If no, the
   insight is being lost on the JSON side; either promote into
   facts or reconsider whether the prose is honest.

What this does *not* commit us to:

- A dumb 1:1 dump of `facts` into text. Aggregation across findings
  (Top Arrays from many `large_array` findings, Type Breakdown from
  `type_ranking`) is the text layer doing legitimate narrative work
  — the principle is about per-finding rendering, not about whether
  the text layer is allowed to group/sort/elide at the surface.
- Removing all prose. Hypothesis lines, "Next:" suggestions,
  category labels in shape annotations — these are the value-add
  of the text layer. The principle restricts *what* the prose
  replaces (don't replace numeric facts), not *whether* prose
  appears.

Practical impact on residual items:

- **N17 (`bottleneck_path` size-meaning ambiguity)**: render
  `path[i]` with `sizes[i]` per step. The "concentrates / shared /
  tapers" framing collapses to derived shape annotation read off
  `sizes[]` ratios at render time. Single-line summary `(186.11 MB)`
  next to leaf path is the elision the principle forbids — drop it.
- **N18/N19 (engine type names in user output)**: parked, but the
  principle gives the right framing — `summary` strings carry
  internal vocabulary because they were authored at pass time;
  rendering from `facts` (which is clean) is the natural fix vector
  once the glossary / role-label work lands.
- **N28 (`expensive_property×5` ellipsis)**: expand from
  `facts.detected_as` (or whatever the cluster fact is named)
  rather than counting and printing a number.
- **B8/B9 spine endpoints**: already covered by this principle,
  partially landed via PR #644's `Spine: heaviest-child mass drops
  after X` line. The full per-step descent is the next step in the
  same direction.

This section is normative for new findings and refactors. Existing
findings get migrated opportunistically when their text rendering
is touched for other reasons.

---

## Confirmed implementation bugs

### B1. Class and method names are lower-cased everywhere in paths

Every path that descends into `class_table` renders class and method names
as the case-folded Zend hashtable key instead of the canonical name:

    class_table->symfony\component\dependencyinjection\containerbuilder->methods->createservice->...
    class_table->twig\extension\coreextension->methods->getattribute->...
    class_table->generatedtest0->methods->runbare->op_array
    class_table->composer\autoload\composerstaticinit32e1def09fefbf05b3038ecf2fa0a6e2->...

Origin: `src/Lib/PhpProcessReader/PhpMemoryReader/Collector/Job/EmitClassTableJob.php:101–111`
uses `$bucket->key` (PHP lower-cases class_table keys for
case-insensitive dispatch) as the node label:

    if ($bucket->key !== null) {
        $zend_string = $ctx->dereferencer->deref($bucket->key);
        $class_name = $zend_string->toString($ctx->dereferencer);
    } else {
        $class_name = '?';
    }
    ...
    $ctx->emitNode($class_def_context, $parent, $class_name);

The canonical name is already collected as a `name` **child node** a few
lines below:

    $class_name_context = CollectorHelpers::collectZendStringPointer(
        $class_entry->name, $ctx,
    );
    $class_definition_context->add('name', $class_name_context);

Same lowercasing applies to the methods table (method names hashed for
lookup are lower-cased too).

Two fix shapes, each viable:

**(i) Emit-time:** resolve `$class_entry->name` and use it as the
label at the `emitNode` call. Minimal change, runs on the hot
collection path. Needs a guard in case the name pointer can't be
dereferenced (fall back to `$bucket->key`).

**(ii) Display-time:** leave collection alone, extend `NodeLabeler`
to look up the `name` child for class-definition nodes and prefer
that over the raw link-name for path display. `NodeLabeler` currently
only handles CallFrameContext via `function_name`/`lineno` attributes
(see `NodeLabeler.php:76–103`); adding class-definition handling is
additive. Downside: `name` is stored as a child node, not as an
attribute in `context_node_attributes`, so the labeler has to join
through tree edges (or we add an attribute mirror).

(i) is probably less total code; (ii) is more conservative about the
collection pipeline. Either way it's the single highest-ROI fix —
every path that descends into `class_table` becomes readable.

### B2. `dedup_candidate` impact can exceed the entire heap

A `[LOW] 722.27 MB impacted` finding on a heap whose total analysed size is
11.96 MB (see `rw_logger-stack.report.txt`).

An earlier version of this note blamed a "`cnt × retained` with retained
double-counting shared subtree" formula. Re-reading the code showed that
explanation is wrong. The actual mechanism:

1. The SQL that selects dedup groups (`loadDedupRowsFromSql`,
   `DedupCandidatePass.php:180–201`) keys the group by
   `(link_name, node_size)` only — class is not part of the group key.
   So any two objects linked via the same slot with the same shallow
   size land in the same bucket. This is **B6** — heterogeneous bucketing.
2. `getRetainedForDedup` (line 461–500) then samples up to 20 children
   from that bucket and computes the **arithmetic mean** of their
   retained sizes.
3. That mean is multiplied by `cnt` to produce `impact_bytes` (line 92).

For the logger-stack bucket: `Monolog\Handler\TestHandler` (1 instance,
retains the entire handler tree ≈ all logged records) ended up in the
same bucket as 3,000 `Monolog\LogRecord` instances (which retain just
themselves). The mean is dominated by the one outlier; multiplying by
3,001 gives the 722 MB figure.

So **B2 is a symptom of B6**. Fix the grouping (include target class
in the key, or at least require bucket homogeneity before emitting
the finding) and B2's extreme numbers go away without touching the
cnt × retained formula. The cheapest short-term clamp is still
`impact_bytes = min(impact_bytes, heap_total)` as a guardrail.

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

#### B4b. `cycle_cluster` LOW severity was probably correct — retract

An earlier draft of this section claimed that a 53.90 MB retained
cycle on a 59 MB heap must be HIGH. Retract that.

In `rw2_spreadsheet-xlsx.report.txt`:

    [LOW] 53.90 MB impacted
      cycle_cluster: 1 identical cycle (15 classes, 6.70 KB shallow,
                                        53.90 MB retained)
      Back-reference: cellXfSupervisor

Two separate reasons the HIGH call was wrong:

1. **PHP has a cycle GC.** The presence of a cycle does not
   automatically mean a leak. In a live-process snapshot everything
   visible is still reachable; the cycle GC will handle it when the
   cycle becomes unreachable.

2. **reli already has the distinction.** `GcPendingPass` emits a
   separate `gc_pending_candidate` finding specifically for SCC
   members reachable *only* through `objects_store` — i.e., not
   reachable from user-code roots, waiting on cycle GC. That's the
   "this cycle is actually the leak" case.

   Across all 14 real-world reports collected so far, **zero**
   `gc_pending_candidate` findings fired — including on the
   PhpSpreadsheet scenario, the `s3_cycles` Node graph, and the
   Twig/Monolog WeakMap false-positive reports. All observed cycles
   were reachable from user-land forward edges, consistent with
   "structural cycle, not leak", consistent with LOW.

So `cycle_cluster` LOW isn't a mis-assigned severity — it's the
right label for "I found a cycle, it isn't the leak, here's the
shape for your information". `gc_pending_candidate` is the HIGH
variant (properly scoped to the actual leaks), just not firing on
my captured data. Nothing to fix here.

What *would* be worth doing: `cycle_cluster` currently reports the
subtree's `retained` as `impact_bytes`, which still reads like a
saving estimate even when severity is LOW. Re-labelling to
`current_retained_bytes` (a size, not a lever) would let severity
and the number be read consistently.

The B4a (`dominant_class` HIGH on ratio alone) part of B4 still
stands.

### B5. `cycle_cluster` flags cycles containing WeakMap — needs investigation

Observed:

    Per cycle: 1x WeakMap
    Example: $log->fiberLogDepth          (rw_logger-stack)
    Example: $twig->parser->parsers->precedenceChanges  (rw_twig)

Two possible readings, can't pick between them from the data alone:

**(a) False positive.** WeakMap keys are weak by definition, so a
cycle-through-WeakMap shouldn't retain anything; flagging it is
noise.

**(b) Real cycle the collector emits with strong edges.**
`EmitWeakMapJob` just calls `EmitArrayDirectJob` on the WeakMap's
backing HashTable (`EmitWeakMapJob.php:47–57`). If that emits entries
as strong edges (treating the WeakMap's ht like any other array),
then the collector is over-strong-edging, and the cycle is real in
reli's graph even though it's weak in PHP. That's a collector bug,
not a cycle-pass bug, and the right fix is marking WeakMap entry
edges as weak/non-tree.

Earlier note dismissed this as a straight false positive without
checking. Downgrade to "open question" pending a look at how
`EmitArrayDirectJob` handles the edge strength for WeakMap ht
entries. Either way, the user-visible fix is probably to skip
WeakMap-only SCCs in `cycle_cluster`'s output until the underlying
representation is decided.

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

### S3. `DefinedClassesContext` choke_point is real but mis-prioritised

    choke_point: DefinedClassesContext (0 B shallow)
        holds 5.80 MB via 448 children — class_table

`ChokePointPass` fires this because subtree ≥ 1 MB and
subtree/shallow ratio > 10, both true for any heavy class_table.
Earlier wording said "adds no information" — that's overstated. It
does tell the reader "class definitions account for X MB", which is
genuine information; the issue is the severity/priority relative to
userland findings on the same report.

Better framing than "drop the finding": lower its priority (e.g.,
demote to Info) or move it to a dedicated "Loaded class definitions:
N classes, X MB" line in Overview instead of occupying a HIGH slot
in the Findings list alongside userland choke_points.

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

Per `NonTreeEdgePass.php:226`, `?` is emitted when `$target_class`
is the empty string — i.e., the target has no class because it's
not an object (a ZendString hashtable key, an array's inner bucket,
etc.). Not "couldn't be resolved" but specifically "target isn't an
object".

Either way the symbol is undocumented. Replace with `(non-object)`
or similar, and consider qualifying `key` / `value` / `name` (which
currently appear as bare words) with where the slot comes from — a
HashTable bucket key is a different structural meaning than a
property named `key`.

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

---

## Third-batch observations (rw3_*, Doctrine / static-cache / GraphQL /
reflection metadata / messenger / closure-leak)

Six more scenarios targeting patterns not previously exercised:
Doctrine-like UnitOfWork (30k products + 90k variants + identity map),
unbounded static property caches (80k entries on a class static), a
GraphQL schema + resolved result tree with captured resolvers, a
Symfony-Serializer-style metadata cache (300 DTOs × 15 attrs),
Symfony-Messenger envelopes with closure-capturing retry stamps, and
the classic captured-$this event-listener leak.

Reports live in `/tmp/memreport-out/rw3_*.report.txt`. Observations
below are grounded in specific findings from those reports — not
speculation.

### N1. `empty_object` should exclude internal classes

`empty_object` flags instances with zero user-declared properties as
"pure overhead, may be replaceable". The logic assumes the object is a
user-land class that could be refactored to a plain struct — but the
finding misfires on every PHP internal class, because internal classes
by definition have no user-declared properties while still carrying
significant C-side state.

Captured across the 20-report corpus (see index below), internal
classes showing up as `empty_object`:

| Report                      | Class                 | Count | Note                                        |
|-----------------------------|-----------------------|-------|---------------------------------------------|
| `rw_phpunit`                | `ReflectionClass`     | 200   | internal; reflection handle                 |
| `rw_twig`                   | `ReflectionMethod`    | 177   | internal                                    |
| `rw_symfony-console`        | `Closure`             | 56    | internal; carries captured `$this`/`use`    |
| `rw3_closure-leak`          | `Closure`             | 6,000 | the *actual leak root*, not "replaceable"   |
| `rw3_messenger-envelopes`   | `Closure`             | 50,000| `RetryStamp::$replay` — the retainer        |
| `rw3_graphql-shape`         | `Closure`             | 320   | field resolvers                             |
| `rw4_generator-leak`        | `Generator`           | 2,000 | captures `$this` + pipeline state           |

In every one of those cases, "no stored properties — may be replaceable"
is misleading: internal objects *cannot* be made into a plain value
shape, and several of those rows are pointing the reader directly at
the retainer they should be investigating ("this 2,000 Generators / 6k
Closures isn't waste, it's your leak").

Fix: filter internal classes at the pass level. `EmptyObjectPass`
already has class information; use the Zend class-entry `type` (user
= 0x01 vs internal = 0x02) to skip internal. A hard-coded denylist
works as a short-term substitute:

    Closure, Generator, Fiber,
    WeakMap, WeakReference, SplObjectStorage, SplFixedArray,
    SplDoublyLinkedList, SplQueue, SplStack, SplHeap, SplPriorityQueue,
    DateTime, DateTimeImmutable, DateInterval, DateTimeZone, DatePeriod,
    DOMDocument, DOMElement, DOMNode, DOMText, DOMAttr,
    SimpleXMLElement, XMLReader, XMLWriter,
    PDO, PDOStatement,
    mysqli, mysqli_stmt, mysqli_result,
    ReflectionClass, ReflectionMethod, ReflectionProperty,
    ReflectionFunction, ReflectionParameter,
    ArrayObject, ArrayIterator,
    CachingIterator, RecursiveDirectoryIterator, // ... (many more)

The class-entry `type` check is the correct implementation; the
denylist is only defensible as a stepping stone until the substrate
exposes the internal/user flag cleanly.

After this filter, the remaining `empty_object` findings are
user-defined classes with no declared properties — for which the
finding's advice ("consider value type / enum / flag-only class") is
actually applicable. From the corpus, genuinely-relevant residues
would include:

- `Twig\Node\NameDeprecation` (1,440 instances, user-defined, holds
  only static metadata via inheritance → candidate for enum)
- `BusNameStamp` (50,000 user-defined marker stamps → could be an
  enum or a shared singleton)

Those rows the advice actually helps with. Everything internal-class
should be gone.

### N2. `shared_singleton` reads as a warning when it's a positive signal

In `rw3_reflection-heavy`:

    [shared_singleton] AttributeMetadata::$ignoredAttributes: 4,499 refs -> 1 target
    [shared_singleton] PropertyInfoCacheEntry::$types: 4,499 refs -> 1 target
    [shared_singleton] ClassMetadata::$discriminatorMap: 299 refs -> 1 target

Same pattern in `rw3_graphql-shape` with `FieldDefinition::$args`.
These mean: "all instances of this class happen to share the same
value for this property via CoW — you're already getting the dedup for
free". That's a positive fact about the heap, not a problem. But the
finding appears in the `Additional Info` section with the same `[tag]`
formatting as the actual issues above it, and the name
`shared_singleton` doesn't carry the positive valence.

Two fixes that help independently:

- **Phrase it positively**: rename to `shared_property_ok` or similar,
  with wording like "CoW is already sharing this property — no action
  needed".
- **Separate positive from negative**: split the "Additional Info"
  section into "Observations (no action needed)" and "Minor
  findings" so the valence is explicit from where an item lands.

### N3. `spl_object_hash`-keyed arrays produce hostile paths

`rw3_doctrine-uow`:

    bottleneck_path: $uow->originalEntityData[00000000000000090000000000000000][attributes]

`00000000000000090000000000000000` is a valid `spl_object_hash()` key;
the path is technically correct. But the reader can't derive any
meaning from the hash. Doctrine's UnitOfWork specifically uses
`spl_object_hash`-keyed maps for identity tracking; any Doctrine-style
report will show this pattern.

Possible fix: detect keys that match `spl_object_hash`'s format
(32-char hex, or 33 with trailing tag in older PHP) and render as
`[obj#<short>]` where `<short>` is a short counter assigned per dump.
The verbatim hash goes into `facts.key_raw` for the reader who needs
it. Low risk: the rendered form still uniquely identifies the slot
within the report.

### N4. Uniform-sibling rows in "Top Arrays" waste space

Recurring across `rw2_error-context-capture`, `rw2_guzzle-buffered`,
`rw2_eloquent-hydration`, `rw3_graphql-shape`:

    8       672.00 KB   ...    $queryResult[viewer][recentActivity][0]
    9       671.94 KB   ...    $queryResult[viewer][recentActivity][1]
    10      671.94 KB   ...    $queryResult[viewer][recentActivity][2]

Three to seven rows of identically-sized siblings, each a separate line.
`TextReportFormatter`'s Top Arrays table could detect runs where
`retained`, `element_count`, and the path suffix pattern match, and
collapse them:

    8       672.00 KB   ...    $queryResult[viewer][recentActivity][0]
       (+2 more at ~672 KB each — see rmem:explore)

Same shape is useful for "Top Strings" where duplicate-content strings
get one row each today.

### N5. `dedup_candidate` severity floor is flat; saving potential is not reflected

Per `DedupCandidatePass.php:128–130`:

    severity: $total > 102400
        ? FindingSeverity::Low
        : FindingSeverity::Info,

Anything above 100 KB is Low. In `rw3_doctrine-uow`:

    [LOW] 5.15 MB impacted
    dedup_candidate: value: 30000/30000 copies have identical content (100%).
    Example: "Product description text. Product description text. Produ..."

30,000 identical 180-byte strings — interning them would save ~5 MB. On
a 148 MB heap that's ~3.5%. The current rule has two separate problems:

1. **Threshold is absolute bytes, not proportional.** Other size-based
   passes (`choke_point`, `dominant_class`, `dominant_type`) all key
   severity off `% of heap`, so a 5 MB dedup_candidate ranks the same
   on a 50 MB heap (10%, meaningful) and a 5 GB heap (0.1%, noise).
   Aligning with the proportional scale used elsewhere lets
   "size-relative-to-script" drive attention, which is what helps
   the user understand where their memory is actually concentrated.
2. **Severity ignores the identical-vs-same-size sub-case distinction.**
   "100% identical content" is *definitely* actionable; "same size,
   different content" is a weaker, statistical hint that may not
   actually be dedup-able even if the bytes are big.

Refined proposal — proportional, with confidence-aware tiering:

- **High-confidence sub-case** (`content_identical`, and possibly
  the "objects already shared via reference" variant) — apply the
  same proportional scale used by `choke_point`:
  - `≥ 30%` of heap → High
  - `≥ 10%` → Medium
  - `≥ 1%` → Low
  - otherwise → Info
- **Low-confidence sub-case** (`same_size_different_content`) — cap
  at Low regardless of impact, since the savings figure is an upper
  bound that may not materialise.

The pass already distinguishes the sub-cases in its hypothesis text
(`3000/3000 copies have identical content (100%)` vs `Same size but
different content`); the severity logic just doesn't pick up on it.

Why proportional is the right axis: severity is meant to surface
"where to look first" within a single report. Absolute thresholds
give the same `Low` to a 5 MB dedup on a 50 MB heap and on a 5 GB
heap, even though the first is 10% of the script's footprint and
the second is rounding error. Proportional thresholds make the
ranking respect the script's own scale, which is the lens the user
is actually reasoning in.

(Out of scope here: whether `dedup_candidate` should also cluster
with `content_identical` sub-cases for `expensive_property` /
`structural_duplicate` to avoid triple-listing the same root cause —
covered by S12 / "problem entity" clustering above.)

### N6. Closure-capture leak shape is detectable from existing findings

`rw3_closure-leak` produces exactly the signature that would let a
shape-detection pass recognise this pattern:

    dominant_class:     Closure 6,000 x 344 B
    dedup_candidate:    this_ptr (Handler): 3,000 copies (retained)
    ownership_pattern:  RequestContext owned 1:1 by Handler (3,000)
    choke_point:        $bus->listeners[request.completed] (3,000 children)

"N distinct closures, each `this_ptr` points at a distinct
Handler-like instance, listener container grew to N" is the exact
fingerprint of the captured-$this leak pattern. Today the reader must
synthesise this from four separate findings. A `closure_this_leak`
shape-detection rule keyed off that signature would produce one clear
finding with actionable guidance:

    [HIGH] captured_$this leak via Closure:
      N closures (each bound to a different Handler/Controller) accumulate
      in $bus->listeners[...]. Closures capture $this by default, so each
      handler's entire context tree stays alive. Typical fix:
        - Remove listeners when the request ends
        - Use a static closure (fn or `static fn`) if the handler state
          is not actually needed
        - Or invert: put the listener on a persistent service, not on
          per-request Handler

Adds weight to the stocked shape-detection proposal — this is a
generic PHP anti-pattern, not framework-specific.

### N7. static_properties paths are clean once B1 is fixed

`rw3_static-cache` only has 5 findings, all correctly pointing at
`class_table->...->static_properties->...` of user classes
(`LookupService::$staticCache`, `UserRepository::$byIdCache`, etc.).
Once B1 lands, those paths read as proper class names and the report
is essentially already narrative — this is the cleanest report in the
whole corpus.

Confirms the intuition that the text output is *close* to good for
well-shaped heaps; the complaints we've collected are concentrated on
a few recurring issues (B1 casing, B8 descent, duplicate findings,
Closure noise) rather than a global "the whole layout is wrong".

### N8. `rw3_messenger-envelopes`: 22 findings for one root problem

50,000 envelope accumulation — unambiguously one phenomenon ("worker
didn't process-and-discard envelopes") — produced 22 separate
findings:

- 1 bottleneck_path
- 1 choke_point on `$processedEnvelopes`
- 2 companion_cluster (one with 8 classes on a single 320-char line,
  one with 3)
- 6 expensive_property (one per stamp property)
- 3 empty_object (Closure + 2 stamp classes)
- 1 ownership_pattern (Closure via RetryStamp)
- 7 structural_duplicate (one per stamp/message class)
- 1 dedup_candidate

This is the strongest empirical case for S12 (cluster by target).
22 items to describe "50k envelopes, each with 6 stamps + 1 closure"
is unreadable in practice. Most of the 22 could be rolled into one
block:

    [HIGH] $processedEnvelopes: 50,000 accumulated, 185.86 MB retained
      Shape: each Envelope owns 6 stamps (BusNameStamp, HandledStamp,
             RetryStamp, SentStamp, DelayStamp, TransportMessageIdStamp)
             and wraps a message (ProcessUserRegistration / SendNotification
             / ExportReport, 1/3 each).
      Hot retainer: RetryStamp::$replay is a Closure (50,000 × 344 B =
                    16.40 MB) capturing the wrapped message via `use`.
      Levers:
        - Bound the envelope list (rotate / drain after handling)
        - Drop RetryStamp once the handler succeeded
        - Use `static fn` if the replay closure doesn't actually need
          to capture

That's 10 lines vs 22 findings; all the facts are in the existing
findings, the formatter just has to join them on target.

### N9. Text formatter is already close for some scenarios

Scenarios that produced clean, focused reports with the existing
formatter:

- `rw3_static-cache` (5 findings, all actionable)
- `rw2_csv-mega` (2 findings — bottleneck + choke — for a 462 MB heap)
- `rw3_graphql-shape` (8 findings, all relevant)

Scenarios that produced noisy reports:

- `rw3_messenger-envelopes` (22)
- `rw3_doctrine-uow` (25)
- `rw_logger-stack` (14)
- `rw_twig` (10 with shared_singleton flood)

The noise correlates with **many per-instance objects of several
companion classes**. Scenarios dominated by a single large userland
array produce focused reports. S12 clustering would mostly affect the
object-heavy scenarios; scenarios like `rw3_static-cache` don't need
it.

This suggests prioritising: ship B1 + B3 first (help everyone), then
S12 clustering (help the 22-finding cases).

---

## Reports corpus snapshot

As of this revision: **25 real-world reports** across four batches.

- `rw_*` (7): framework-baseline sized (2–12 MB heap) — phpunit,
  symfony-console, twig, parsedown, logger-stack, laravel-collections,
  psr-7-stack.
- `rw2_*` (7): OOM-sized (43–462 MB) modelled on classic issues —
  json-decode-huge, csv-mega, xml-dom-huge, error-context-capture,
  eloquent-hydration, guzzle-buffered, spreadsheet-xlsx.
- `rw3_*` (6): pattern-diverse — doctrine-uow, static-cache,
  graphql-shape, reflection-heavy, messenger-envelopes, closure-leak.
- `rw4_*` (5): shape-diverse and WordPress — wordpress-bootstrap
  (real WP core loaded), generator-leak, graph-recursion (highly
  cyclic, 10k-node graph), enum-collections (PHP 8.1+ enums),
  pdo-result-hoarding (500k-row fetchAll).

Every B-item and S-item claim in this document is supported by at
least one of those reports. Each claim's primary evidence report is
named inline with the claim.

## Fourth-batch additions

### N10. `B2` extends to `property_scaling`, not just `dedup_candidate`

`rw4_graph-recursion` (111 MB heap, heavily cyclic 10,000-node graph):

    [MEDIUM] 53.73 GB impacted
      property_scaling: GraphNode (10,000 instances): 4 per-instance props
                       (5.50 MB/instance retained), 0 shared
      PER-INSTANCE (retained, scales with count):
        GraphNode::$outEdges: 10,000 copies x 5.50 MB = 53.71 GB

    [LOW] 108.55 GB impacted
      dedup_candidate: GraphNode::$outEdges[value] (GraphNode):
                       10,000 copies x 11.12 MB retained = 108.55 GB

53 GB and 108 GB impacts on a 111 MB heap — **500× and 1000× the heap
respectively**. Root cause is the same `retained × count` formula (now
seen in at least two passes, `DedupCandidatePass` and whichever pass
emits `property_scaling`) being applied to SCC members. When every
GraphNode is part of one 140,000-node SCC (per the final
`[retained_approximate]` line), each node's "retained" includes the
entire SCC via reachability, so `N × retained` = `N × (whole heap)` =
`N × N × shallow`.

Confirms that B2 isn't a dedup-specific bug — it's **any pass that
multiplies per-node retained by a population count** without
accounting for shared subtrees / SCC membership. The clamp and the
dedup-bucket fix both still apply; the bigger rule is "retained is
not additive across nodes that share a subtree."

### N11. `function_table` is the mirror of `class_table`

`rw4_wordpress-bootstrap` (3.69 MB heap):

    [HIGH] 1.70 MB impacted
      bottleneck_path: function_table->remove_accents->op_array (1.70 MB)
    [HIGH] 1.70 MB impacted
      choke_point: ZendArrayMemoryLocation (72.37 KB shallow) holds
                   1.70 MB via 1802 children — function_table

    Root Blame Allocation
      function_table     1.71 MB   37.3%
      global_variables   1.48 MB   32.2%
      class_table        806 KB    17.1%

`function_table` accounts for 37% of the heap, driven by WordPress's
`remove_accents()` — a 1.7 MB compiled op_array (mostly a huge
literal-table for accent transliteration + a 31 KB doc comment).

Implications:

- Everything said about `class_table` in S1 / S2 / S3 and the stocked
  `class_definition_overhead` proposal applies equally to
  `function_table`. WP loads hundreds of top-level functions; Laravel
  / Symfony apps do too (helper files, global helpers).
- Same B1 lowercase-name issue in principle (function_table keys are
  case-folded too). `remove_accents` happens to be all-lowercase so
  it renders correctly by accident; a function named
  `formatDate()` would display as `formatdate`.
- The `class_definition_overhead` stocked proposal should either
  generalise to `definition_overhead` (classes + functions) or be
  paired with a matching `function_definition_overhead` kind.

### N12. `pdo-result-hoarding` is a clean report at 442 MB heap

    === Findings ===
    [HIGH] 442.00 MB impacted  bottleneck_path: $rows[0][metadata][ref]
    [HIGH] 251.70 MB impacted  choke_point: $rows (500,000 children)
    [HIGH] 189.82 MB impacted  choke_point: $byUser (10,000 children)

Three findings for a 500,000-row fetchAll-style dump. Each correctly
points at user-land state. This is the cleanest of the four
hundred-MB reports and matches the csv-mega pattern noted earlier:
"array-dominated scenarios produce focused reports naturally".

Adds weight to N9 — the noise-per-root-cause complaint is really
"many-per-instance-objects + several companion classes" complaint.
Array-heavy heaps don't need S12 clustering.

### N13. `shared_fanin key -> ? (4,500,030 refs -> 39 targets)` is a positive signal

Same `rw4_pdo-result-hoarding`:

    [shared_fanin] key -> ? (4,500,030 refs -> 39 targets, 115385.4 each)

4.5 million HashTable keys resolve to only 39 interned string targets.
That's PHP's string interning of repeated column names in every row of
a fetchAll result — working as intended. Without interning, this
would cost ~80 MB extra. The finding is **excellent empirical
evidence that interning is doing its job** but reads as noise because
it appears under "Additional Info" with the same bullet formatting as
actual issues.

Same N2 pattern: positive information presented as warning. Two
narrow observations per kind:

- `shared_fanin` to a small number of targets with a high
  refs-per-target ratio → **interning/deduplication is already
  working**, not a problem.
- `shared_fanin` to many targets with low refs-per-target → only
  *this* is a potential dedup opportunity.

Current output doesn't distinguish. Add the "is this good or bad"
judgment in the formatter: a 115k-refs-per-target ratio is clearly
good; a 3-refs-per-target ratio over 100k targets is a weak-but-real
dedup candidate.

### N14. PHP 8.1+ enums are handled correctly — positive confirmation

`rw4_enum-collections`:

    property_scaling: OrderSnapshot (60,000 instances):
      OrderSnapshot::$items: 60,000 copies x 1,013 B = 58.00 MB
      OrderSnapshot::$customerEmail: 60,000 copies x 48 B = 2.79 MB
      OrderSnapshot::$status: 6 copies x 104 B = 626 B     ← enum singleton
      OrderSnapshot::$region: 6 copies x 98 B = 590 B       ← enum singleton
      OrderSnapshot::$payment: 4 copies x 104 B = 418 B     ← enum singleton

The per-property "copies" count correctly dropped from 60,000 to
the number of distinct enum cases (6, 6, 4). The report correctly
attributes the memory to `$items` and `$customerEmail` (the scaling
per-row data), not to the enum fields. Nothing to fix; worth noting
because the opposite — "60k × Enum instance" counted as scaling
memory — would have been a plausible failure mode.

`shared_fanin OrderSnapshot::$payment -> PaymentMethod (59,996 refs
-> 4 targets)` likewise correctly labels the fan-in on the target
class.

### N15. WordPress bootstrap validates the scope-gap narrative

`rw4_wordpress-bootstrap`:

    Heap: 3.69 MB (78.8% analyzed)      RSS: 61.56 MB (ratio 16.6×)

WP's core-load RSS is dominated by opcache's shared-memory page
cache for included .php files, which lives outside ZendMM. 3.7 MB of
Zend heap is the analysable script state; the rest is opcache
baseline + glibc fragmentation. Consistent with the calibration
table in the scope section earlier:

| Scenario          | RSS    | Heap   | Ratio | Primary gap cause          |
|-------------------|--------|--------|-------|----------------------------|
| csv-mega          | 533 MB | 462 MB | 1.15× | glibc fragmentation        |
| json-decode-huge  | 242 MB | 172 MB | 1.41× | glibc + mmap               |
| xml-dom-huge      | 395 MB | 43 MB  | 9.2×  | libxml2 (DOM tree)         |
| wordpress-bootstrap | 62 MB |  4 MB | 16.6× | opcache (many included files)|
| pdo-result-hoarding | 520 MB | 442 MB | 1.18× | glibc fragmentation        |

The WP row is exactly the case the tool-level scope docs should name:
"you loaded WordPress core, opcache is hundreds of .php files into
shared memory that reli can't see — the 'Heap: 3.69 MB' is script
state only". A reader investigating "why does my WP worker use 62 MB
per process" without that context would look at the report and
conclude reli couldn't find anything.

### N16. 25-report corpus confirms priority ordering

Tallying B-/S-/N-item occurrences across all 25 reports:

| Item                            | Occurrences | Severity of reader impact |
|---------------------------------|-------------|----------------------------|
| B1 (class name lowercased)      | 19+ / 25    | high — every class_table path looks broken |
| B8/B9 (bottleneck leaf≠size)    | 25 / 25     | high — every report has one |
| N4 (uniform sibling rows)       | 12+ / 25    | medium — cosmetic         |
| N1 (empty_object internal class)| 9 / 25      | medium — misleading advice |
| B2/N10 (retained × count inflation) | 4 / 25  | high in affected reports  |
| N2 (shared_singleton positive-as-warning) | 11+ / 25 | low — noise  |
| S12 (cluster across detectors)  | 4 / 25 (noisy ones) | high in affected |
| B3 (preview newline)            | 2 / 25      | high when it hits         |
| B6 (dedup heterogeneous bucket) | 2 / 25      | high when it hits         |

Priority re-stated:

**Tier 1: fix once, helps every report.**
- B1 (class name lowercase) — collector-side 1-line equivalent
- B3 (preview newline escape) — formatter 1-line
- N1 (filter internal classes from empty_object) — 1-line filter

**Tier 2: fixes the biggest number-is-wrong complaints.**
- B8/B9 (bottleneck descent) — formatter reads `facts.sizes`
- B2/B6/N10 (retained × count inflation) — clamp + dedup bucket fix

**Tier 3: narrative improvements for noisy cases.**
- S12 (cluster findings by target)
- N2 (separate positive signals from warnings)
- N4 (collapse uniform sibling rows)

Tier 1 alone probably makes 20/25 reports noticeably cleaner. Tier 2
rescues the remaining "numbers look wrong" cases. Tier 3 is polish
that matters for power-user readability but doesn't fix any
incorrectness.

---

## Tool-level UX outside the main report

So far every observation has been about `inspector:memory:report`.
The broader set of tools (`inspector:memory:compare`, `rmem:query`,
`rmem:explore`, `rmem:viz`, `rmem:serve`) are the natural follow-up
surfaces when a report's `Explore:` hint sends the reader further.
Notes from walking those tools on the captured dumps:

### T1. `Explore: rmem:explore --node=N` hint is broken on raw dumps

Every finding with evidence nodes emits a line like:

    Explore: rmem:explore --node=205  (#205, #206, #215)

But `rmem:explore` / `rmem:query` reject the `.rmem` file produced by
`inspector:memory:dump`. On `rw3_closure-leak.rmem`:

    $ ./reli rmem:query rw3_closure-leak.rmem --node=203
    Invalid rmem magic: 52454c49

The file starts with `RELIMEM` (the raw dump format); `rmem:query`
expects the *intermediate* binary format produced by
`inspector:memory:analyze --output-format=binary`. The user is
expected to run a separate conversion step that the hint does not
mention.

Workarounds the hint could surface:

- Append the prerequisite: `Explore: after converting with
  'inspector:memory:analyze … --output-format=binary', rmem:explore
  --node=205`.
- Better: make `rmem:query` / `rmem:explore` accept raw dumps by
  running the conversion internally on first use.
- Better still: emit the hint with the path to a binary file the
  user already has, if analyse was run with `--output-format=binary`.

Even the direction "run inspector:memory:analyze first" is non-obvious
because users typically already ran `analyze` — but with
`--output-format=sqlite3` (the format the report needs) and that file
isn't accepted by `rmem:query` either.

### T2. `rmem:query` drill-down works but is verbose

Following the hint from `rw3_closure-leak` after the conversion
(node 203 is a Closure):

    $ ./reli rmem:query rw3_closure-leak.binary.rmem --node=203 --children
    Node 203 locations:
      type=ZendObjectMemoryLocation addr=0x7ed50aa80000 size=344 B
      refcount=1 class=Closure

    Path to root:
      [value] node=203 (ObjectContext)
        [0] node=202 (ArrayElementContext)
          [array_elements] node=201 (ArrayElementsContext)
            [value] node=199 (ArrayHeaderContext)
              [request.completed] node=198 (ArrayElementContext)
                [array_elements] node=197 (ArrayElementsContext)
                  [listeners] node=195 (ArrayHeaderContext)
                    [object_properties] node=194 (ObjectPropertiesContext)
                      [value] node=192 (ObjectContext)
                        [bus] node=191 (ArrayElementContext)
                          [array_elements] node=117 (ArrayElementsContext)
                            [global_variables] node=115 (ArrayHeaderContext)
                              [<root>] node=4294967295 (?)

    Children:
      [object_handlers] node=204 (ObjectHandlersContext)
      [object_properties] node=205 (ObjectPropertiesContext)
      [closure] node=206 (ClosureContext)

Strengths: the path-to-root is useful, locations section is specific.
Weaknesses:

- **Every query prints the full path to root**, 14 lines in this
  case. For a user walking multiple nodes that's the same preamble
  repeated. Add a `--no-path` flag or skip path when the parent
  matches the previous query.
- **Structural-context class names leak unchanged**: ArrayElementContext,
  ObjectPropertiesContext, ClosureContext, ArrayHeaderContext,
  ArrayElementsContext. Same S-tier friction as in the main report —
  these names are engine-internal and don't help a user understand
  what they own.
- **Bulk traversal is missing.** The actionable answer for this
  particular node was "the Closure captures `$this_ptr`, which points
  at a Handler". Getting there required drilling node 203 → 206 → 215
  manually. A `--show-retained-tree` or `--show-captures` mode for
  closure nodes would land the answer in one step.

### T3. `inspector:memory:compare` Findings Diff is incomplete

Compared `rw_logger-stack.db` (baseline, 12 MB worker heap) with
`rw3_messenger-envelopes.db` (target, 172 MB worker heap) as a
"accumulation grew" test:

The class-memory and location-type sections are excellent:

    === Class Memory Changes ===
      New classes (target only):
        Envelope                50,000 instances  3.43 MB
        HandledStamp            50,000 instances  3.43 MB
        RetryStamp              50,000 instances  3.43 MB
        ...
      Removed classes (baseline only):
        Monolog\LogRecord        3,000 instances  445.31 KB
        ...

But the Findings Diff is missing a `New findings:` section despite
the target having 22 findings the baseline doesn't. Output has:

    Resolved findings:   (36 items — everything from baseline)
    Changed findings:    (1 item — companion_cluster impact changed)

Likely cause: the matching heuristic pairs findings across snapshots
by `kind` alone, so e.g. `choke_point` in baseline and `choke_point`
in target are treated as "the same finding, value changed" even
though they point at completely different subtrees. The 22 target
findings either all get paired up (making them Changed rather than
New) or get dropped.

Fixes:

- Match findings by `(kind, primary_target_node_id or equivalent)`,
  not `kind` alone. Two choke_points at different nodes should not
  pair.
- When no reasonable match exists in the baseline, emit as New.
- When baseline had findings of a kind that target lacks, emit as
  Resolved (currently seems to work for Resolved).

### T4. Other tools (`rmem:viz`, `rmem:serve`, `rmem:mcp`) unexplored

This doc only covers the command-line report path. `rmem:viz` (HTML),
`rmem:serve` (query server), and `rmem:mcp` (AI-assisted MCP server)
are additional surfaces that would benefit from the same JSON-vs-text
discrepancy analysis — e.g., does `rmem:viz` render the full
`facts.sizes` array that the text formatter ignores? Is `rmem:mcp`
exposing the cleaner `facts.*` fields or the raw `summary` string?
Worth a separate pass.

---

## Parked design ideal: typed edges instead of stringly link_names

Recurring symptom across multiple polish items: Zend-internal edge
labels (`'key'`, `'value'`, `'array_elements'`, `'object_properties'`,
`'static_properties'`, `'methods'`, `'name'`, `'func'`, ...) leak
through pass labels and report output. Each callsite that wants to
render or aggregate has to know what those mean *in its specific
parent context*, because the same string carries different
semantics depending on what kind of node it dangles from:

- `link_name='value'` from an `ArrayElementContext` ≈ "array
  element value"
- `link_name='value'` from a `ZendReferenceContext` ≈ "reference
  target"
- `link_name='value'` from a user object whose property happens to
  be named `$value` ≈ "the literal `$value` field"

Filtering by string at the formatter / pass layer (today's
`PathFormatter::STRUCTURAL` list, `DedupCandidatePass::buildDedupLabel`'s
`[$link_name]` interpolation, `NonTreeEdgePass`'s
`shared_fanin: $link_name -> $target_class` template) is a leaky
abstraction: every site has to re-derive what the bare string means
and pick its own rendering, with no compile-time check that the
sites agree.

### Idealised model

Replace the `link_name: string` field on edges with a typed
`EdgeKind`:

    edge { parent_id, child_id, kind: ArrayElementValueEdge,
           key: zval-or-int, ... }
    edge { parent_id, child_id, kind: ObjectPropertyEdge,
           name: 'addressMinNode' }
    edge { parent_id, child_id, kind: ReferenceTargetEdge }
    edge { parent_id, child_id, kind: ClassNameEdge }
    edge { parent_id, child_id, kind: StaticPropertyEdge,
           name: 'staticCache' }
    edge { parent_id, child_id, kind: MethodDefinitionEdge,
           name: 'getAttribute' }
    ...

Each `EdgeKind` carries:

- Its own **rendering strategy**:
  - `ArrayElementValueEdge::renderForPath()` → `[*]` or `['key']`
    if `key` is known
  - `ObjectPropertyEdge::renderForPath()` → `->$name`
  - `StaticPropertyEdge::renderForPath()` → `::$name`
  - `MethodDefinitionEdge::renderForPath()` → `::method()`
  - `ReferenceTargetEdge::renderForPath()` → transparent (or `&`)
  - `ClassNameEdge::renderForPath()` → elide (it's structural)
- Its own **structural-elision flag**:
  `EdgeKind::isStructuralElision(): bool` (replaces today's
  `PathFormatter::STRUCTURAL` hardcoded list)
- Its own **aggregate behaviour**:
  Used by `shared_fanin` and similar: aggregating across edges
  becomes type-driven. Mixed-kind aggregations are explicit
  ("fan-in across multiple kinds") rather than ambiguous bare
  `value -> ?`.

Result:

- `'key'` and `'value'` strings disappear from report output
  entirely — they're internal implementation details of the
  graph, never visible to a reader.
- New semantic relationships (e.g., "Closure captured `$this`",
  "Generator captured frame", "WeakMap entry") get added by
  introducing new `EdgeKind` types, not by sprinkling new
  string literals across the codebase.
- Schema changes (a property gets renamed; a relationship's
  semantics evolve) get type-checked at compile time.

### Why local fixes are insufficient

The handoff's T2.7 candidate ("clean up `[value]` in
DedupCandidatePass labels, clean up `key/value` bare in
shared_fanin labels, ...") would be a callsite-by-callsite
patching pass. Each fix needs to know its parent context. Each
fix duplicates a tiny piece of the typed-edge logic
ad-hoc. Once a future site emits another `'value'` link with a
new meaning, the cycle starts over.

So either:

- **Don't do T2.7 at all** until typed edges are on the table —
  the work is paid up front and the fix is principled, OR
- Do T2.7 as **acknowledged technical debt**: surface-level
  cleanup that buys readability now, with the understanding that
  typed edges supersedes it and the local fixes will be reverted
  when the refactor lands.

The verification team's recommendation is the first: park T2.7
as a candidate that depends on the typed-edges decision. Don't
build the local-cleanup substrate that becomes throwaway scaffolding.

### Migration sketch (if pursued)

Three phases, each independently shippable:

1. **Introduce `EdgeKind` alongside `link_name`** — collector
   populates both for new captures. Schema (sqlite, .rmem
   binary, JSON) gains an additional column / field. Existing
   captures lack the new field; readers fall back to `link_name`
   for them.
2. **Migrate render and aggregation sites** — every pass and
   formatter that consumed `link_name` now consumes `EdgeKind`
   when present. Old captures still flow through the
   string-based path. PathFormatter's `STRUCTURAL` list gets
   replaced by `EdgeKind::isStructuralElision()`.
3. **Drop the string field** — once captures from N versions ago
   have aged out, remove `link_name` from the schema entirely.

Costs vs benefits, briefly:

- **Costs**: schema migration touching collector, substrate, all
  passes, all formatters, plus `.rmem` binary format bump. Risk
  surface is broad but each phase is local and verifiable
  against the corpus.
- **Benefits**: every "string interpretation by context" gripe
  in this doc resolves itself; new `EdgeKind` types are the
  natural place to land features like Closure/Generator captured
  state, WeakMap entries, Fiber locals, etc.; report code stops
  hardcoding internal vocabulary.

This belongs in the same file with the `impact_bytes` semantics
refactor and the `class_definition_overhead` proposal — all three
are "if a future round wants to invest in proper architecture,
this is the move" parking lots.

---

## Parked: engine-internal type vocabulary in user-facing output

### Symptoms (N18, N19)

Captured during the impl14 fresh-eye review.

**N18** — `choke_point` owner-name leaks `MemoryLocation` /
`ReferenceContext` class names into the report:

```
choke_point: ZendArrayTableMemoryLocation (781.26 KB shallow)
  holds 185.86 MB via 50000 children — $processedEnvelopes
choke_point: ClassDefinitionContext (0 B shallow)
  holds 1.01 MB via 9 children — class_table->Carbon\Carbon
```

A general PHP developer hitting this for the first time has to
look up what `ZendArrayTableMemoryLocation` is and how it
differs from `ZendArrayMemoryLocation` and
`ZendArrayTableOverhead`, none of which appear in their own
codebase or in the PHP manual.

**N19** — same vocabulary leak in `=== Type Breakdown ===`:

```
ZendArrayTable                  1,010,010    213.86 MB    48.4%
ZendArrayTableOverhead          1,010,009    109.45 MB    24.8%
ZendString                      1,500,095     64.78 MB    14.7%
ZendArray                       1,010,011     53.94 MB    12.2%
```

Across the impl14 corpus the `Zend*` types dominate every
breakdown. They *are* what's allocated, so we can't just collapse
them — but the labels demand engine-level mental models from the
reader.

### Why a naive rename doesn't work

Initial impulse was to rename in-report to PHP-friendly
language: "array bucket storage" instead of
`ZendArrayTableMemoryLocation`, "object" instead of
`ZendObject`, etc. The user pushed back: each of these distinct
internal types carries precision the report needs to keep:

- `ZendArrayMemoryLocation` (~56 B `zend_array` header) vs.
  `ZendArrayTableMemoryLocation` (the `arData` bucket buffer)
  vs. `ZendArrayTableOverhead` (unused capacity within
  `arData`) — these are three physically distinct allocations
  that resize independently. Collapsing all three under
  "array" hides where the bytes actually went.
- `MemoryLocation` (real heap allocation, freeable from PHP)
  vs. `ReferenceContext` (analyzer-inserted virtual grouping
  node, often 0 B shallow, no PHP-level handle) — these are
  semantically opposite kinds of nodes. Both currently surface
  with engine-style class names, but the user can act on a
  Location and not on a Context.

Losing those distinctions to gain readability is a regression
for power users (and for diagnostic precision in support
tickets).

### Design discussion outcomes (still open)

Three approaches surfaced; none chosen yet.

**(a) Role labels + technical name in-report**

```
choke_point: $processedEnvelopes — array bucket storage
  (ZendArrayTableMemoryLocation; 781.26 KB shallow)
  holds 185.86 MB via 50,000 children
```

Add a `displayRole(): string` to the `MemoryLocation` and
`ReferenceContext` hierarchies. Findings and Type Breakdown
render the role label first, technical name in parentheses.
Cheap to ship, doesn't sacrifice precision, but the role label
still needs a one-time explanation for full understanding.

**(b) Glossary documentation**

Single doc covering all surfaceable types. Two-part structure
(Memory Locations vs Reference Contexts), categorised
sub-groups, three-tier depth (commonly-seen entries get
intent + sizing + actionable hint; rarer ones get one-line
definition).

Scope is **larger than report-surfacing types**: `rmem:explore`
does not elide path nodes the way the report does, so any
`*Context` from `src/Lib/PhpProcessReader/PhpMemoryReader/ReferenceContext/`
(53 classes) can show up under the cursor. Reducing glossary
scope to "only what the report prints" would leave the
explorer underdocumented.

Estimated 25–30 entries with full treatment + ~40 entries
with one-line treatment ≈ 600–800 lines markdown.

**(c) HTML report**

The vocabulary problem becomes a mostly-solved problem in
HTML: every type name becomes an inline link to the glossary
section (or a hover-popover with the definition). No
loss of precision because the technical name stays; the
role / definition is one click away rather than mandatory
upfront knowledge.

HTML output also unlocks adjacent UX wins identified during
the same review — circle-pack visualization for retained-size
hierarchy, click-to-focus cross-linking between findings and
viz, filter / search, click-through from `Top Arrays` rows
into the tree. These are not strictly required to fix N18/N19
but the HTML scaffold makes them natural follow-ups.

Phasing sketch:
- P1: curated HTML, no viz, glossary inline links — solves
  N17/N18/N19/N28 directly. ~2k lines.
- P2: D3 circle-pack viz + finding↔region focus highlight.
  ~1.5k lines.
- P3: filter / search / cross-linking. ~1k lines.
- P4 (stretch): replace or augment `rmem:explore` with the
  HTML viewer.

Output flag: `--format=text` (default) | `--format=html` |
`--format=json`. Self-contained single `.html` file with
embedded curated subset of nodes (top-N + spine + finding
targets, ~1k–10k node budget) plus the full `.rmem` for users
who want to drill further with `rmem:explore`. Glossary URL
embedding pinned to the release tag (build-time
substitution), not `main`, so a v1.2 binary's report links
the v1.2 glossary.

### Why this is parked

(a) is the cheapest fix but doesn't fully solve N18/N19 on
its own — readers still need glossary access to act on the
role label. (b) is necessary content regardless of which
delivery vector wins. (c) is the most thorough fix but a
substantial new surface (HTML templating, JS bundling,
versioned static assets, viz performance budgeting) that
wants to be planned as its own initiative rather than tacked
onto a polish round.

The right next move is probably **(b) glossary first**,
because that content is reused by all of (a)/(c) anyway. (a)
becomes a small follow-up. (c) becomes a planned major
feature once (b) exists.

For now: keep N18 and N19 unaddressed in code, accept the
current `Zend*` / `*Context` labels in output, and treat
this section as the design memo to revisit when capacity is
available for the glossary doc.

Related: rendering precision concerns ("array bucket storage
vs. array header vs. unused capacity") parallel the typed-edges
park above — both are about not collapsing engine-level
distinctions just because the surface text is awkward.
