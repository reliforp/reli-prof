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
of owning copies counts those bytes N times. Short-term fix: clamp to
`min($cnt * $retained, $heap_total_bytes)` and label the impact as
"(overcounted via sharing)". Longer-term: compute the actual dedup saving
as `$total - one_copy_retained` or via set-union over owned subtrees.

### B3. String previews with embedded newlines break the table

Any string whose preview contains `\n`, `\r`, or `\t` wraps to the next row
and shifts the whole table layout. Carbon's doc comments are the worst
offender — every row of "Top Strings" in `rw_logger-stack.report.txt`
splits into two visible rows.

Fix: escape whitespace in `TextReportFormatter::format()` when building the
preview (around `src/Inspector/Output/MemoryOutput/Report/Formatter/TextReportFormatter.php:251-257`):

    $preview = strtr($preview, ["\n" => '\\n', "\r" => '\\r', "\t" => '\\t']);

### B4. Severity threshold ignores absolute magnitude

`dominant_class` emits `[HIGH]` based on percentage of object memory only,
not absolute bytes, so in tiny heaps the severity is wrong:

    [HIGH] 720 B impacted — laravel-collections run
    [HIGH] 10.94 KB impacted — phpunit run

A finding that accounts for under ~1 MB should never be HIGH regardless
of percentage. Gate HIGH on `impact_bytes >= HIGH_MIN_BYTES` in addition
to ratio.

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
