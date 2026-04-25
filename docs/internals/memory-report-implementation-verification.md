# T1 + T2 Implementation Verification

Verifies `claude/implement-memory-report-ux-0pxL5` (commit `f551b5d`,
"feat(memory-report): T1+T2 UX fixes from improvement investigation")
against the handoff spec
`docs/internals/memory-report-implementation-handoff.md`.

Verification method: re-ran `inspector:memory:report` using the impl
branch against the 25 saved .db files in `/tmp/memreport-out/`. For
B1 (collector-side), additionally re-ran `inspector:memory:analyze`
on the .rmem dumps for 4 representative scenarios so the new
labels are visible.

## Status summary

| Item       | Status            | Notes                                                                |
|------------|-------------------|----------------------------------------------------------------------|
| **B1** class names | ✅ Done   | Class-table labels render canonical case (`Twig\Extension\CoreExtension`, `LookupService`, `Composer\Autoload\ComposerStaticInit32e1def...`). |
| **B1** method names | ❌ Missing  | `methods->getattribute`, `methods->runbare` still lower-cased — fix only applied to class-bucket label, not to the methods-table iteration. |
| **N11** function-table function names | ❌ Not addressed | `function_table->function_name` still uses the case-folded HashTable key. (`remove_accents` happens to be all-lowercase so renders unchanged.) |
| **B3** preview newlines | ✅ Done    | `\n` / `\r` / `\t` / `\0` escaped before truncation. Carbon doc-comment Top Strings rows are now single-line. |
| **N1** internal classes excluded | ✅ Done | Denylist used (correct stepping stone). 7 hits BEFORE on `Closure` / `Generator` / `Reflection*` → 0 hits AFTER. Residual `empty_object` findings are user-defined classes only (`BusNameStamp`, `Twig\Node\NameDeprecation`). |
| **B8/B9** bottleneck spine | ✅ Done | "Spine: mass drops at depth N (X → Y); leaf retains only Z" added under `bottleneck_path` summaries when descent crosses >2× drop. Uniform-sibling tail label fires (rarely, see notes). |
| **B6** dedup heterogeneous bucket | ✅ Done | `(link_name, node_size, target_class, target_location_type)` GROUP BY. logger-stack went from `722 MB (TestHandler+LogRecord mixed)` to `4.88 MB (LogRecord only)`. messenger-envelopes' single mixed bucket split into 3 per-message-class findings. |
| **N10** impact clamp (DedupCandidatePass) | ✅ Done | `cnt × retained` clamped to `heap_total_bytes` with `total_waste_unclamped` preserved in JSON facts and a hypothesis note. graph-recursion `108.55 GB → 111.45 MB`. |
| **N10** impact clamp (property_scaling pass) | ❌ Missing | Handoff explicitly named both passes. The `property_scaling` finding in graph-recursion still emits `[MEDIUM] 53.73 GB impacted` on a 111 MB heap. |

## Detailed evidence

### B1 — class names canonicalised

Re-analyzed `rw3_static-cache.rmem`:

    Before:  class_table->lookupservice->static_properties->staticCache
    After:   class_table->LookupService->static_properties->staticCache

Re-analyzed `rw_twig.rmem`:

    Before:  class_table->twig\extension\coreextension->methods->getattribute
    After:   class_table->Twig\Extension\CoreExtension->methods->getattribute

Re-analyzed `rw_phpunit.rmem`:

    Before:  class_table->generatedtest0->methods->runbare
    After:   class_table->GeneratedTest0->methods->runbare

    class_table->Composer\Autoload\ComposerStaticInit32e1def09fefbf05b3038ecf2fa0a6e2->static_properties->classMap

All class names render correctly. **But methods stay lower-case** —
see "Gaps" below.

### B3 — preview newlines

`rw_logger-stack` Top Strings before:

    1     156.16 KB   #135807  ...arboninterface->doc_comment  /**
     * Common interface for Carbon an...

After:

    1     156.16 KB   #135807  ...arboninterface->doc_comment  /**\n * Common interface for Carbon a...

Single-line, parseable by tooling, readable.

### N1 — internal classes excluded

Across the 25 reports:

    BEFORE — empty_object on internals (corpus-wide grep):
      empty_object: Closure: 6,000 instances ...
      empty_object: Closure: 320 instances ...
      empty_object: Closure: 50,000 instances ...
      empty_object: Generator: 2,000 instances ...
      empty_object: Closure: 800 instances ...
      empty_object: ReflectionClass: 200 instances ...
      empty_object: ReflectionMethod: 177 instances ...

    AFTER — same grep:
      (none)

    Residual empty_object after the filter:
      empty_object: BusNameStamp: 50,000 instances ...
      empty_object: Twig\Node\NameDeprecation: 1,440 instances ...

Both residuals are genuinely user-defined classes with no declared
properties — exactly the case where the "consider value type / enum"
advice applies. Implementation matches the handoff spec.

### B8/B9 — spine rendering

`rw2_json-decode-huge` (the flagship B8 case):

    Before:  bottleneck_path: $decoded[data][10100][profile] (171.45 MB)

    After:   bottleneck_path: $decoded[data][10100][profile] (171.45 MB)
             Spine: heaviest-child mass drops at depth 2 (171.44 MB → 84.33 MB);
                    leaf retains only 1.94 KB

Reader can now reconcile the path (`...->10100->profile`) with the
size (`171.45 MB`) — the spine line clearly says the leaf is tiny
and the mass dropped at depth 2.

Spine lines fire on 8+ reports (csv-mega, eloquent-hydration,
error-context-capture, guzzle-buffered, json-decode-huge,
xml-dom-huge, graphql-shape, generator-leak, etc.).

The "uniform-sibling tail" decoration only fires once across the
corpus (rw2_eloquent-hydration). It requires "no further >2× drop
in the tail", which is conservative — `rw2_csv-mega`'s tail goes
`2.47 KB → ... → 405 B` (a >2× drop) so the heuristic considers
the descent "still moving". Not a defect, but the label could
catch more cases with a relative-variance threshold instead of a
strict no-drop rule.

### B6 — dedup_candidate heterogeneous bucket

`rw_logger-stack`:

    Before:  dedup_candidate: value (Monolog\Handler\TestHandler):
             3,001 copies x 246.45 KB retained = 722.27 MB
             Examples: TestHandler (152B), LogRecord (152B), LogRecord (152B)

    After:   dedup_candidate: value (Monolog\LogRecord):
             3,000 copies x 1.67 KB retained = 4.88 MB
             Examples: LogRecord (152B), LogRecord (152B), LogRecord (152B)

The TestHandler outlier no longer pollutes the LogRecord bucket. The
4.88 MB number is a sane representation of the per-LogRecord retained
× count.

`rw3_messenger-envelopes`: previously one merged bucket of mixed
ProcessUserRegistration / SendNotification / ExportReport
(50,000 × 349 B = 16.64 MB). Now three separate per-class findings
(2.75 / 8.98 / 2.77 MB). The +1 finding count is a feature of the
fix: per-class accuracy.

### N10 — impact clamp

`rw4_graph-recursion`:

    Before:  dedup_candidate: GraphNode::$outEdges[value]: 108.55 GB
    After:   dedup_candidate: GraphNode::$outEdges[value]: 111.45 MB
             (impact clamped from 108.55 GB to heap total — cnt × retained
              over-counts shared subtree memory)

Clamp annotation makes the over-counting visible to the reader. The
`total_waste_unclamped` field in JSON facts preserves the raw number
for tooling.

## Gaps

### G1. Methods table & function_table not de-cased (B1 partial / N11 missing)

The B1 fix in `EmitClassTableJob.php:106–112` is class-bucket only.
The methods-table iteration at `EmitClassTableJob.php:309` still
uses `$function_name` from the HashTable key:

    foreach ($array->getItemIterator($ctx->dereferencer) as $function_name => $zval) {
        ...
        $defined_functions_context->add($function_name, $result);

PHP lower-cases function names when stored in HashTables for
case-insensitive dispatch, so user code like `getAttribute()` shows
as `getattribute`. Same code path is shared between methods (per
class) and the global `function_table`.

Visible across the corpus:

    rw_twig:           ->methods->getattribute->op_array
    rw_phpunit:        ->methods->runbare->op_array
    rw_symfony-console: ->methods->createservice->op_array
    rw3_doctrine-uow:  ->methods->getclassname (etc.)

Fix: at line 312 inside the loop, dereference
`$zval->value->func->common.function_name` to the canonical name
and use that when adding to `$defined_functions_context` (with the
same fallback-to-bucket-key on deref failure that the class loop
uses).

### G2. property_scaling pass missing the impact clamp (N10 partial)

The handoff explicitly called out:

> Affects both `DedupCandidatePass.php:92` and (per N10 evidence
> from rw4_graph-recursion) `property_scaling` pass — grep for it;
> the same `× count` pattern lives there too.

Only DedupCandidatePass got the clamp. `rw4_graph-recursion` still
shows:

    [MEDIUM] 53.73 GB impacted
      property_scaling: GraphNode (10,000 instances): 4 per-instance
                       props (5.50 MB/instance retained), 0 shared

53.73 GB impact on a 111 MB heap, no clamp note. Fix: apply the
same `min(total, heap_total_bytes)` + hypothesis-note pattern in
the property_scaling pass.

## Findings count delta (corpus-wide)

| Direction | Count | Cause                                                  |
|-----------|-------|--------------------------------------------------------|
| -1        | 7     | N1 internal-class empty_object findings removed        |
| +1        | 1     | B6 split heterogeneous dedup bucket into per-class     |
| ±0        | 17    | B-side fixes (B3 / B8/B9 / B6 numbers / N10 clamp) modify content but don't change finding count |

No regressions introduced (no findings disappeared that shouldn't
have, no spurious new findings beyond the B6 splits).

## Recommendations for follow-up commit

1. **Finish B1**: add canonical-name resolution at
   `EmitClassTableJob.php:309` so methods table and function_table
   stop showing lower-cased names. Same shape as the class-bucket
   fix; ~10 lines plus fallback.
2. **Finish N10**: apply the impact clamp to `property_scaling`
   pass (find via `grep -rn "kind: 'property_scaling'" src/`),
   matching the DedupCandidatePass shape:
   - Constructor takes `$heap_total_bytes`
   - At impact computation, `min($total, $heap_total_bytes)` with
     `total_waste_unclamped` and a hypothesis note when clamped
   - Wire `$heap_usage` from ReportGenerator to all call sites
3. (Optional polish) Loosen the "uniform-sibling tail" heuristic
   from "no >2× drop in tail" to a relative-variance threshold so
   it fires on csv-mega / pdo-result-hoarding (where the leaf
   really is one of many ~similar-sized siblings).

Otherwise: clean implementation, comprehensive denylist for N1,
proper preservation of unclamped value for tooling consumers,
spine renderer respects the existing finding payload (no schema
break).

---

## G1/G2/G3 follow-up verification (commit `857b695`)

After the gaps were reported, the implementation session shipped
`857b695 fix(memory-report): close T1+T2 verification gaps (G1, G2, G3)`.
Re-running the same verification on the new commit:

### G1 — methods table & function_table de-cased

A new helper `CollectorHelpers::resolveCanonicalFunctionName()`
derefs `$zval->value->func->common.function_name` (via
`$func->getFunctionName()`) and falls back to the bucket key on
deref failure. Used in both `EmitClassTableJob` (per-class methods)
and `EmitFunctionTableJob` (top-level function_table).

Re-analyzed `rw_twig.rmem` and `rw_phpunit.rmem` (collector-side
change requires re-analyze):

    Before:  class_table->Twig\Extension\CoreExtension->methods->getattribute->op_array
    After:   class_table->Twig\Extension\CoreExtension->methods->getAttribute->op_array

    Before:  class_table->GeneratedTest0->methods->runbare->op_array
    After:   class_table->GeneratedTest0->methods->runBare->op_array

Method names render with their declared case. function_table uses
the same helper so de-casing applies symmetrically (WP's
`remove_accents` happens to be all-lowercase by declaration so it
renders unchanged, which is correct).

**Status: ✅ closed.**

### G2 — `property_scaling` impact clamp

`PropertyScalingPass` now takes `heap_total_bytes` and clamps the
per-instance retained sum, mirroring `DedupCandidatePass`. The pre-
clamp value is preserved as `per_instance_total_bytes_unclamped` in
facts; the hypothesis carries a clamp note when triggered.
`ReportGenerator` wires `$heap_usage` to all three call sites.

`rw4_graph-recursion`:

    Before:  [MEDIUM] 53.73 GB impacted
             property_scaling: GraphNode (10,000 instances): ...

    After:   [MEDIUM] 111.45 MB impacted
             property_scaling: GraphNode (10,000 instances): ...
               GraphNode::$outEdges: 10,000 copies x 5.50 MB = 53.71 GB
               GraphNode::$inEdges: 10,000 copies x 634 B = 6.05 MB
               GraphNode::$properties: 10,000 copies x 629 B = 6.01 MB
               GraphNode::$label: 10,000 copies x 32 B = 321.18 KB
             (1 scalar properties per-instance, included in object size)
             (impact clamped from 53.73 GB to heap total — per-instance
              retained over-counts shared subtree memory)

The headline `impact_bytes` is now bounded by heap total. The
per-property breakdown lines retain their unclamped figures, which
is the right call — those are informational, and the clamp note
makes the over-counting explicit.

**Status: ✅ closed.**

### G3 — uniform-sibling tail heuristic

The strict "no >2× drop in tail" rule was loosened to "no >3× single
step OR overall max/min < 4×" — a single sharp step is allowed if
total tail spread stays bounded.

Firing rate across the corpus:

| Implementation        | Reports firing the label | Notes |
|-----------------------|--------------------------|-------|
| f551b5d (impl1)       | 8 / 25                   | (Earlier verification doc said "1/25" — that was a miscount on my part. The actual impl1 number was 8.) |
| 857b695 (impl2, G3)   | 11 / 25                  | +3: rw2_csv-mega, rw4_enum-collections, rw_laravel-collections |

`rw2_csv-mega` (the headline G3 case in the handoff):

    bottleneck_path: $orders[0][items][0] (461.70 MB)
    Spine: heaviest-child mass drops at depth 5 (460.75 MB → 2.47 KB);
           leaf retains only 405 B
           leaf is one of many similar-sized siblings — weight is
           distributed, no single deep spine

The reader can now tell at a glance that `[0]` and `[items][0]` are
arbitrary picks among many ~equal siblings.

`rw4_pdo-result-hoarding` still doesn't fire — its tail descent
drops sharply enough that even the loosened heuristic doesn't
classify it as uniform. Could be tightened further with a
relative-variance check, but the current shape covers the obvious
cases.

**Status: ✅ closed (improved from 8/25 → 11/25).**

### Regression check (G1/G2/G3 vs impl1)

All previous T1/T2 fixes still hold:
- B3 (`\n` escape): present in `rw_logger-stack` Top Strings
- N1 (internal classes filtered): `empty_object: Closure|Generator|Reflection|...` count = 0
- B6 (homogeneous bucket): `Examples:` lines now show single class
  consistently across logger-stack and messenger-envelopes
- N10 dedup clamp: `108.55 GB → 111.45 MB` annotation intact
- B8 spine line: 22 spine lines fire across 25 reports

No findings counts changed between impl1 and impl2 — G1/G2/G3 are
purely modifications of existing finding content, no spurious
additions or removals.

### Final status

T1 + T2 verification gaps closed. Every claim in the handoff doc
has a corresponding verified fix. T3 (S12 clustering, N2
positive-signal re-section, N4 uniform-sibling row collapse) is
pending — not started yet on this branch. The handoff's
"Scope discipline" section calls each tier independently shippable,
and T3 lives in a separate PR by design, not as an exclusion.

### Caveat: clamp is a guardrail, not a saving estimate

Worth being explicit so the verification isn't read as "the
impact_bytes numbers are now correct":

`min(cnt × retained, heap_total)` removes the "108 GB on a 111 MB
heap" embarrassment but the clamped value is **the ceiling, not the
actual saving**. In `rw4_graph-recursion` after the clamp:

    [MEDIUM] 111.45 MB impacted
      property_scaling: GraphNode (10,000 instances)
        GraphNode::$outEdges: 10,000 copies x 5.50 MB = 53.71 GB

`111.45 MB` equals the heap total. A reader who interprets that as
"fixing this saves 111 MB" is over-estimating: the GraphNodes
referenced via `$outEdges` are shared across the SCC, so removing
this property only frees the strictly-unshared portion — much
less than the heap. The clamp keeps the number physically possible;
it doesn't make it tight.

The handoff explicitly framed this as a stepping stone:

> **Short-term guardrail**: clamp ... eliminates the "1000× the
> heap" surprises.
>
> **Medium-term fix**: switch `impact_bytes` for these kinds from
> `total = count × per_copy_retained` to a saving estimate.

The medium-term fix lives in the parked
"`impact_bytes` semantics tradeoff" section of
`memory-report-ux-improvements.md` (resolution A: split into
`current_bytes` + `saving_estimate_bytes`). Until that ships, the
clamped numbers in `dedup_candidate` and `property_scaling` should
be read as "memory currently sitting in this pattern, capped at
heap total" — not as a delete-and-save figure.

#### Where the over-counting actually happens (clarification)

A natural question while reading this section: does `retained`
itself double-count shared subtrees, or is the over-counting in
the aggregation? Worth being precise.

`GraphSubstrate::computeSubtreeSizes()` walks only `strong_children`
(tree-edge children). Tree edges are stored with one parent per
child (`tree_parents[$child] = $parent`), so the tree-edge graph
is a true forest. Each node's `node_size` is summed into exactly
one tree ancestor's subtree. **Retained per node is well-defined
and not double-counted via DAG sharing.**

The over-counting happens in the `cnt × retained` aggregation in
`DedupCandidatePass` (and similarly per-instance retained sums in
`PropertyScalingPass`). For a highly-connected SCC like
`rw4_graph-recursion`'s 140,000-node graph:

1. Analyse picks a spanning tree across the SCC (one tree parent
   per node; remaining edges become non-tree).
2. Spanning-tree-root-ish nodes have subtree_size ≈ entire SCC
   subtree. Leaf-ish nodes have subtree_size ≈ shallow.
3. `getRetainedForDedup` averages 20 sampled members' retained
   sizes — the closer the sample skews to spanning-tree roots,
   the larger the mean.
4. That mean × N counts the spanning tree's ancestors many times
   over (each member's retained already includes everything below
   it, and the descendants are themselves SCC members whose
   retained also includes their descendants, all of which overlap).

So the formula `cnt × retained` is a hypothetical "if N copies each
owned a fresh independent subtree of mean retained size" — useful
when copies truly are independent (`rw_logger-stack`'s LogRecord
case after B6: 3,000 × ~1.67 KB ≈ 4.88 MB, plausible). But for
DAG/SCC members it overstates, sometimes massively. The substrate's
retained number is fine; the dedup-pass aggregation is the leaky
abstraction.

---

## T2.1 follow-up verification (PR #649 — union aggregation)

Re-ran `inspector:memory:report` against the 25 saved `.db` files
using PR #649's `claude/dedup-union-aggregation` branch (commit
`9f58256`). Pass-level changes only, so no re-analyse needed.

### Status: ✅ closed cleanly

| Check                                              | Result |
|----------------------------------------------------|--------|
| Clamp annotations gone                             | ✅ all 25 reports — zero `(impact clamped from ...)` lines |
| `total_waste_unclamped` gone from JSON facts       | implied (the field is removed from the Finding shape) |
| SCC over-count actually fixed (graph-recursion)    | ✅ `dedup_candidate 111.45 MB → 11.13 MB`, `property_scaling 111.45 MB → 22.25 MB`. Both well under heap, both representing the actual SCC subtree |
| Independent-copies cases stay sane (logger-stack)  | ✅ `4.88 MB → 4.66 MB` (drop reflects that union de-dups some shared interned/metadata) |
| Per-class buckets after B6 (messenger-envelopes)   | ✅ `2.75 / 2.77 / 8.98 → 2.37 / 2.38 / 9.04` — same magnitude |
| Findings count regression                          | ✅ `±0` on all 25 reports vs the pre-T2.1 baseline |
| Wording reflects union semantics                   | ✅ `"X KB retained"` → `"X KB avg retained"` (X = total / cnt) |

### Caveat: `impact_bytes` is now "current" but still not "saving"

T2.1 closes the "ceiling vs actual" complaint from the previous
verification round. The displayed value is now the **memory
currently sitting under any of these N copies**, intrinsically
bounded by heap, no clamp needed.

But it is still **not** a saving estimate. For the graph-recursion
case the new `11.13 MB` reflects "11 MB of SCC bytes are reachable
under any of the 10,000 GraphNodes". If a reader interprets it as
"de-duplicating these would save 11 MB", they'll be over-estimating —
the GraphNodes can't actually be deduplicated in the simple sense
(they're mutually-referencing nodes in a graph, not interchangeable
copies of one logical thing).

The honest reading after T2.1:
**"this is how much memory is currently in the union of these N
copies' reachable subtrees — and that's also the upper bound on
what you could free by collapsing them"**. That's a tighter,
correct bound — strictly better than the pre-T2.1 clamp ceiling.

The remaining "tight saving estimate" piece stays parked under
resolution A's full implementation (`current_bytes` +
`saving_estimate_bytes` split — see
`memory-report-ux-improvements.md` §"The `impact_bytes` semantics
tradeoff").

### Final state of the `impact_bytes` saga

| Stage          | What `dedup_candidate.impact_bytes` represents                                                  |
|----------------|--------------------------------------------------------------------------------------------------|
| Pre-PR #644    | `cnt × sample_mean(retained)`. Could exceed heap by 1000× in SCC scenarios.                       |
| PR #644 (T2)   | `min(cnt × sample_mean(retained), heap_total)`. Ceiling, not tight; clamp note in hypothesis.    |
| PR #649 (T2.1) | `unionReachableTreeSize(seeds)`. **Actual memory under those seeds.** Bounded by heap intrinsically. |
| Future (Res. A)| Adds `saving_estimate_bytes` next to `current_bytes`. Requires dominator-tree calculation.       |

T2.1 ships the third row. The fourth is the resolution A refactor
that's still parked.

---

## G4 follow-up verification (PR #651 — `is_tree=1` filter removed)

PR #651 (`claude/canonical-names-include-non-tree-edges`, commit
`d6aa1c7`) drops the `is_tree = 1` filter from all three sites
(`NodeLabeler.php` SQL JOIN, `BinaryReportDataProvider.php`'s
`castSection` and raw-bytes branches inside `loadCanonicalNames`).
Re-ran `inspector:memory:report` against the 25 saved `.db` files
on the new branch.

### Status: ✅ closed

The agreed acceptance grep:

    grep -E 'class_table->[a-z]+\\' /tmp/memreport-out-impl*/rw*_*.report.txt

| Stage         | Total hits | Reports affected |
|---------------|------------|-------------------|
| Before G4 (impl4 / pre-PR #651) | **9**     | **4**             |
| After G4 (impl5 / PR #651)      | **0**     | **0**             |

Spot-check expectations from the PR description, all confirmed:

| Report          | Pre                                                       | Post                                                      |
|-----------------|-----------------------------------------------------------|-----------------------------------------------------------|
| `rw_phpunit`    | `class_table->generatedtest0->methods->runbare`           | `class_table->GeneratedTest0->methods->runBare`           |
| `rw_phpunit`    | `class_table->composer\autoload\composerstaticinit32e1def...` | `class_table->Composer\Autoload\ComposerStaticInit32e1def...` |
| `rw_twig`       | `class_table->twig\extension\coreextension->getattribute` | `class_table->Twig\Extension\CoreExtension->getAttribute` |
| `rw3_static-cache` | `class_table->lookupservice / userrepository / queryparser / templaterenderer` | `class_table->LookupService / UserRepository / QueryParser / TemplateRenderer` |

Regression check across the 25 reports: **0 changed, 25
unchanged** in finding count. No spurious additions or
disappearances.

### B1 saga, complete

| Stage                                  | What happens to a class name like `Twig\Extension\CoreExtension` |
|----------------------------------------|------------------------------------------------------------------|
| Original                               | `twig\extension\coreextension` (case-folded HashTable bucket key) |
| PR #644 (T1 emit-time, **reverted**)   | `Twig\Extension\CoreExtension` — but broke PHP 8.x target-version CI |
| PR #648 (T1 display-time, partial)     | `Twig\Extension\CoreExtension` for ~54% of classes; user-defined classes that arrived via `is_tree = 0` `name` edges (autoload / opcache) still rendered as case-folded |
| PR #651 (G4)                           | All classes resolved (filter dropped). Corpus-wide `class_table->[a-z]\\` hits = 0. |

T1's B1 / G1 / G4 chain is functionally complete. The remaining
"function_table non-definition entries" gap (PR #648's
out-of-scope note) only affects edges that don't have a
`*FunctionDefinitionContext` target — internal builtins registered
via different paths. Those are uncommon and not user-actionable.

---

## T2.2 verification (PR #652 — spine drop by path component name)

Re-ran `inspector:memory:report` on all 25 saved `.db` files
against PR #652's `claude/spine-drop-name-component` branch
(`961b3a8`). Pass-time + formatter-time changes only — no
re-analyse needed.

### Status: ✅ closed

| Check                                          | Result |
|------------------------------------------------|--------|
| `at depth N` substring eliminated              | ✅ 0 hits across all 25 reports (was 22) |
| Drop component reads as user-side identifier   | ✅ `$orders`, `$users`, `$errors`, `$history`, `$uow`, `$bus->listeners[request.completed]`, etc. |
| Spine line count preserved                     | ✅ 22 → 22 (same descent triggers the same line) |
| Findings count regression                      | ✅ 0 changed, 25 unchanged |

### Sample renderings before / after

| Report                | Before                                                                                     | After                                                                                                |
|-----------------------|--------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------|
| `rw2_csv-mega`        | `Spine: heaviest-child mass drops at depth 5 (460.75 MB → 2.47 KB); leaf retains only 405 B` | `Spine: heaviest-child mass drops after $orders (460.75 MB → 2.47 KB); leaf retains only 405 B`      |
| `rw2_eloquent-hydration` | `Spine: heaviest-child mass drops at depth 5 (309.92 MB → 3.26 KB)`                      | `Spine: heaviest-child mass drops after $users (309.92 MB → 3.26 KB)`                                |
| `rw3_doctrine-uow`    | `Spine: heaviest-child mass drops at depth 5 (146.04 MB → 48.03 MB)`                       | `Spine: heaviest-child mass drops after $uow (146.04 MB → 48.03 MB)`                                 |
| `rw3_closure-leak`    | `Spine: heaviest-child mass drops at depth 10 (7.12 MB → 2.66 KB)`                         | `Spine: heaviest-child mass drops after $bus->listeners[request.completed] (7.12 MB → 2.66 KB)`      |

### Edge case noted

In `rw2_json-decode-huge` the rendered drop component is
`global_variables -> array_elements` — the descent root is the
`global_variables` HashTable and the first descent step is into
its `array_elements` bucket. That arrow notation is the standard
PathFormatter form for "step from a structural root through its
array-elements container", not a user-named variable. Acceptable
as-is — it conveys "the descent goes through the array of all
globals" — but is the one place where the new component-named
form still includes engine-internal terminology. If a future polish
pass wants to elide that, it'd be a PathFormatter change, not
T2.2.

### Net for T2 series

After PR #652 + the still-pending T2.4 (call-frame `:lineno` strip),
spine and bottleneck output reads as:

    bottleneck_path: <user-path>
    Spine: heaviest-child mass drops after <user-recognisable component>
           (X → Y); leaf retains only Z

with no engineering-internal `:lineno` or `at depth N` artefacts —
matching the T2-series ergonomic goal.

### Re-verification on 0.12.x HEAD (G4 + T2.2 combined)

The first T2.2 verification ran against PR #652's branch in
isolation, which forks from a base that pre-dates PR #651's merge.
That made the lowercase residue I noted (`...->lookupservice->...`)
look like a T2.2 issue when it was actually pre-existing G4
territory. Re-ran on `origin/0.12.x` HEAD (`a3696d9` — both PRs
merged) for clarity:

- Corpus-wide `class_table->[a-z]+\\` residue: **0** (G4 holds)
- Spine drop labels render with declared casing, e.g.
  `after ...->LookupService->static_properties->staticCache`
- All other T2.2 properties unchanged (22 → 0 `at depth N` etc.)

### Repeat-name ambiguity check

A reasonable concern about T2.2: if the rendered drop label were a
single component name (e.g., `after outEdges`) and that name
appeared multiple times in the descent, the reader couldn't tell
**which** `outEdges` the drop is at.

Checked the implementation — `renderBottleneckSpine` slices
`path[0..drop_index]` inclusive and runs the whole prefix through
`PathFormatter::toPhpSyntax`. So even when a component name
repeats in the descent, the rendered label carries the full
prefix:

    Spine: heaviest-child mass drops after $nodes[0]->outEdges[0]->outEdges
                                          ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
                                          full prefix → position is unambiguous

Survey of all 25 reports: **no ambiguous component names** in the
emitted drop labels. The closest the corpus comes to repetition is
`after global_variables -> array_elements` appearing in two
different reports (json-decode-huge, graph-recursion), but those
are distinct findings on distinct snapshots, not in-path
repetition. Multi-segment labels like
`after $bus->listeners[request.completed]` and
`after $queryResult[viewer][recentActivity]` show the full prefix
correctly.

### Remaining polish item (out of T2.2 scope)

`bottleneck_path` and `Spine` sometimes describe the same descent
with different vocabularies:

    bottleneck_path: $decoded[data][10100][profile] (171.45 MB)
    Spine: heaviest-child mass drops after global_variables -> array_elements
           (171.44 MB → 84.33 MB); leaf retains only 1.94 KB

`bottleneck_path` elides the structural roots (`global_variables`,
`array_elements`) and starts at the first user-named slot
(`$decoded`). `Spine` renders the full prefix from the structural
root, so for shallow drops it shows the structural-root part that
`bottleneck_path` elides. The two then read as if discussing
different paths.

Two ways to converge:

- Spine extends its slice past purely-structural components until
  it includes a user-named segment.
- Or share the elision logic between `bottleneck_path`'s
  `summary_path` and Spine's drop label so they always agree on
  what counts as "user-side".

Either is a small refinement on top of T2.2 — not a regression in
T2.2 itself, but worth queueing as a polish item if the
convergence matters in practice. (Filed as T2.5 in the handoff if
prioritised.)

---

## T2.4 verification (PR #653 — call-frame `:lineno` strip from non-stack paths)

Re-ran `inspector:memory:report` on all 25 saved `.db` files
against PR #653's `claude/strip-call-frame-line-from-paths`
branch (commit `153ea60`). NodeLabeler-only change at report time
— no re-analyse needed.

### Status: ✅ closed (with caveat about corpus coverage)

| Check                                              | Result |
|----------------------------------------------------|--------|
| `:lineno` in non-Call-Stack paths                  | ✅ 0 hits in the corpus (impl7 and impl8 both 0 — my corpus doesn't produce this leakage in the first place; see below) |
| Call Stack section `function:lineno` form preserved | ✅ `#1 <main>:65` form intact across reports |
| Findings count regression                          | ✅ 0 changed, 25 unchanged |

### Caveat: corpus doesn't exercise the bug directly

My 25 captured scripts pause at `fgets(STDIN)` at the top level,
so the captured call stack is always shallow (`#0 fgets:-1`,
`#1 <main>:N`). The bottleneck-path descent never reaches into a
deeper `CallFrameContext` for any of the .db files I have.

The user's original example —
`Reli\Lib\…\MemoryLocationsCollector::collectAll:297::$sink->...` —
came from a "reli profiling itself" capture, where the running
collector is mid-frame in `collectAll` and the spine descends
through that frame's locals. None of my 25 reports reproduce that
shape.

So T2.4's correctness rests on:

- Unit tests (8/8 pass per PR — including the 4 new T2.4
  cases on top of the G4 set)
- Call Stack section's `:lineno` form preserved (positive
  control — show the only render site that still requires the
  line number stays on the with-line form)
- No regression in the 25 reports

Re-checking against a "reli profiling itself" capture would be
the proof-positive run; pending whoever runs into that scenario
again. Pending that, the code review of NodeLabeler's two-mode
resolver (`frame_labels_with_line` vs `frame_labels_path_form`)
is the strongest evidence.

---

## T2.3 verification (PR #654 — binary `node_classes` writer signedness)

PR #654 fixes the T2.3 root cause identified by the investigation
session:
`BinaryContextTreeSink::$perNodeClasses` was an FFI `int32_t[]`
accumulator, but the unset sentinel `Format::NULL_STRING_ID`
(`0xFFFFFFFF`) read back through a signed slot as `-1`. The
update guard `=== Format::NULL_STRING_ID` (= `4294967295`)
therefore never matched, slots stayed null, and the on-disk
`node_classes` section came out all-NULL. Fix: switch the
accumulator to `uint32_t[]` (same wire bytes, correct PHP-side
read-back).

### Why my SQLite-only corpus missed this

The 25 saved `.db` files in `/tmp/memreport-out/` are SQLite
intermediate output. `inspector:memory:report` against a `.db`
takes the SQL path, which reads `class_name` straight from
`LocationRow` and isn't affected by the binary writer bug. To
reproduce the bug I had to re-analyze with
`--output-format=binary` and feed the binary intermediate to
`inspector:memory:report`.

The bug was real but invisible to a SQLite-only corpus —
explaining why the `dedup_candidate: raw:` example the user
originally shared (presumably from a binary-path run) was never
reproducible from any of the .db files I had.

### Status: ✅ closed

Verified on `rw3_doctrine-uow.rmem` re-analyzed to binary
intermediate, before and after PR #654:

| Finding                                                  | Before                              | After                                              |
|----------------------------------------------------------|-------------------------------------|----------------------------------------------------|
| 90,000 Variant via Product::$variants                    | `value: 90,000 ...`                 | `Product::$variants[value] (Variant): 90,000 ...`  |
| 30,000 ProductProxy via Product::$category               | `value: 30,000 x 88 B`              | `value (ProductProxy): 30,000 x 88 B`              |
| 30,000 ZendString descriptions / interned strings        | `value: 30,000 x 180 B` etc.        | unchanged (target is a string, not an object — legitimately no class to surface) |

After the fix, the binary path's dedup_candidate labels match the
SQL path's exactly on the same `.rmem`. Two findings that
previously dropped to the bare `value:` form now read with full
ownership info. The remaining `value:` entries are the
"target is a string / non-object location" cases where there
genuinely is no class to surface.

### What's not in PR #654

The original T2.3 doc proposed a `?::$link_name -> TargetClass`
display fallback as a second line of defence in
`DedupCandidatePass::buildDedupLabel`. PR #654 deliberately skips
it — once the writer is fixed, the only observed bare-label case
goes away, and the fallback would be guarding speculative future
failures with no concrete data behind them. Per CLAUDE.md's
no-hypothetical-features guidance, fail-loud > softened defence.
Filed as the explicit non-goal in the PR description.

The investigation also recommended (3) a codebase-wide audit of
other `int32_t[]` FFI accumulators using `=== NULL_STRING_ID`
guards and (4) a sweep for other substrate consumers degrading
silently on the binary path. Both are separate follow-ups for the
verification team — neither blocks closing T2.3.

### Methodology lesson

T2.3 is a clean example of why my earlier corpus-only verification
runs missed a real bug: the entire 25-report corpus was SQLite,
and the SQL path reads `class_name` from `LocationRow` directly
without touching the buggy `node_classes` section. The bug only
manifested on the binary path — which is what `rmem:explore`,
`rmem:viz`, and the parallel binary-report consumers actually use.

For future verification rounds, **regenerating reports against
both `--output-format=sqlite3` and `--output-format=binary`**
would catch this kind of path-specific regression early. Adding
that to the verification checklist for any change touching the
binary-format writer or its readers.

---

## T2.5 verification (PR #655 — Spine extends past structural intermediaries)

PR #655 implements shape (B) from the T2.5 handoff: the Spine
renderer walks forward from `drop_index + 1` past structural
link names (`global_variables`, `array_elements`,
`object_properties`, `value`, …) until it finds a user-named
identifier, then renders the extended slice through
`PathFormatter::toPhpSyntax`. If the entire descent is
structural, falls back to the legacy `at depth N` form.

### Status: ✅ closed

Re-ran `inspector:memory:report` against all 25 saved `.db` files.

| Check                                                          | Result |
|----------------------------------------------------------------|--------|
| Structural-only Spine labels (`-> array_elements`, etc.)        | ✅ 2 → 0 |
| `bottleneck_path` and Spine vocabulary match within a finding   | ✅ both lines now reference the same user identifier |
| Findings count regression                                       | ✅ 0 changed, 25 unchanged |

### Sample renderings before / after

| Report                 | Before                                                                                | After                                                                  |
|------------------------|---------------------------------------------------------------------------------------|------------------------------------------------------------------------|
| `rw2_json-decode-huge` | `Spine: drops after global_variables -> array_elements (171.44 MB → 84.33 MB)`        | `Spine: drops after $decoded (171.44 MB → 84.33 MB)`                   |
| `rw4_graph-recursion`  | `Spine: drops after global_variables -> array_elements (110.97 MB → 49.60 MB)`        | `Spine: drops after $reachabilityCache (110.97 MB → 49.60 MB)`         |

In both reports the `bottleneck_path` line already showed
`$decoded[data][10100][profile]` / `$reachabilityCache[0][0]`.
After T2.5, the Spine line agrees on what the user-side root of
the descent is, eliminating the cross-line vocabulary mismatch.

### Mid-userland drops unchanged

Reports where the drop already lands on a user-named component
(`rw3_closure-leak`'s `after $bus->listeners[request.completed]`,
`rw_logger-stack`'s `after $log->handlers->referenced[0]->records`,
etc.) render unchanged, as expected — the slice extension only
kicks in when `drop_index` itself sits on a structural-only
segment.

### Shape choice

PR #655 takes shape (B) from the handoff (extend the slice past
structural components) rather than shape (A) (share elision logic
between `selectSummaryPath` and Spine rendering). (B) is the
smaller change and produces the same observable result on this
corpus, per the handoff's "either works; (B) is simpler"
recommendation. (A) remains an option if the teams later need to
unify a wider set of path-render sites against one elision helper.

---

## T2.6 verification (PR #656 — quote string array keys in path display)

PR #656 wraps non-numeric array keys in single quotes inside
`PathFormatter::toPhpSyntax`. Numeric keys stay bare. Embedded
`'` and `\` in keys are escaped.

### Status: ✅ closed (within stated scope)

| Check                                          | Result |
|------------------------------------------------|--------|
| Bareword `[<string>]` in PathFormatter output  | ✅ dropped from 10+ unique forms to 3 (the 3 are non-PathFormatter — see below) |
| Numeric keys remain bare                       | ✅ `[10100]`, `[0]`, etc. unchanged |
| Findings count regression                      | ✅ 0 changed, 25 unchanged |

### Sample renderings before / after

| Report                  | Before                                                                | After                                                                       |
|-------------------------|-----------------------------------------------------------------------|-----------------------------------------------------------------------------|
| `rw2_csv-mega`          | `$orders[0][items][0]`                                                | `$orders[0]['items'][0]`                                                    |
| `rw2_json-decode-huge`  | `$decoded[data][10100][profile]`                                      | `$decoded['data'][10100]['profile']`                                        |
| `rw3_messenger-envelopes` | `$processedEnvelopes[0]->stamps[RetryStamp]`                        | `$processedEnvelopes[0]->stamps['RetryStamp']`                              |
| `rw3_static-cache`      | `class_table->LookupService->...->staticCache[lookup_…][data]`        | `class_table->LookupService->...->staticCache['lookup_…']['data']`          |

### Residual bareword: DedupCandidatePass label format

Three patterns survive across the corpus:

    GraphNode::$outEdges[value] (GraphNode)
    Product::$variants[value] (Variant)
    Twig\Node\Expression\GetAttrExpression::$nodes[value] (Twig\…\ConstantExpression)

These come from `DedupCandidatePass::buildDedupLabel()`:

    $label = "{$source_class}::\${$owner_prop}[{$link_name}]";

The `[{$link_name}]` interpolation injects the raw Zend HashTable
bucket value-slot name (`value`), which is a Zend-internal term —
not a user array key. PR #656's scope was PathFormatter only (per
the handoff: "modulo non-PathFormatter labels"), so this label
format wasn't touched.

The semantics differ from PathFormatter's case:

- PathFormatter renders user-side array keys captured in the
  graph → quote because they're literal user data.
- buildDedupLabel encodes the structural relationship "each
  element of `$owner_prop`'s value slot reaches a `target_class`"
  → `[value]` is a Zend-internal label. Reading it as
  `$variants['value']` is wrong — there's no string key `'value'`,
  the iteration is over all elements.

Possible follow-up (call it T2.7 if pursued):

- Drop `[value]` entirely: `Product::$variants → Variant`
- Or use an "iteration over elements" mark:
  `Product::$variants[*] (Variant)`
- Or arrow form: `Product::$variants → Variant: 90,000 copies …`

Each is a small DedupCandidatePass change. Not a regression in
T2.6 — just a PathFormatter-vs-DedupCandidatePass label scope
asymmetry.