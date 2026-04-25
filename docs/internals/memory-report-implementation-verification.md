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
has a corresponding verified fix. Open follow-ups for a future
commit are limited to T3 (S12 clustering, N2 positive-signal
re-section, N4 uniform-sibling row collapse), which were never in
scope for this round.