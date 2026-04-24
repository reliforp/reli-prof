# Memory Report Implementation Handoff

**Audience**: the next (implementation) session. Delete this file once
the fixes land — it's transient directive content, not reference docs.

**Companion reading**: `docs/internals/memory-report-ux-improvements.md`
in the same commit history. That's the *why* document with 25 real
reports of evidence; this file is the *what to build, in what order,
with what gotchas* document.

## What this branch is

`claude/improve-memory-report-NKJ41` contains **no code changes** yet —
only the `docs/internals/memory-report-ux-improvements.md` investigation.
The investigation ran `inspector:memory:report` against 25 real-world
workloads (WordPress bootstrap, PhpSpreadsheet, Doctrine-like UoW,
Laravel queue envelopes, Closure captures, Eloquent hydration, PDO
fetchAll, etc.) and identified concrete bugs + presentation issues
each backed by a specific captured report.

Each claim carries a code line reference (e.g.,
`DrillDownPass.php:110`) and a "primary evidence report" name
(e.g., `rw2_json-decode-huge.report.txt`).

## Priority ordering

From the `N16` table at the bottom of the UX doc, ranked by
"how many of the 25 reports does this fix help" × "how wrong does
it look to a reader":

**Tier 1 — fix once, helps most reports, small surface.**
- **B1** (class/method names lower-cased in path labels) — 19+/25
- **B3** (preview newline escape in Top Strings) — 2/25 but table
  layout breaks visibly when it hits
- **N1** (`empty_object` should filter internal classes) — 9/25

**Tier 2 — correctness fixes, biggest "numbers look wrong".**
- **B8/B9** (bottleneck_path leaf path + root size mismatch) — 25/25
- **B2/B6/N10** (`retained × count` inflation; dedup heterogeneous
  bucket) — 4/25 but produces "722 MB impact on 11 MB heap" nonsense

**Tier 3 — narrative improvements for noisy reports.**
- **S12** (cluster findings by target across detector kinds)
- **N2** (positive-signal findings labelled as warnings)
- **N4** (collapse uniform sibling rows in Top Arrays / Top Strings)

Ship T1 first, T2 second, T3 third. T1 alone probably makes ~20/25
reports noticeably cleaner, with almost no risk.

## Artifact reuse for verification

All 25 dump/db pairs are saved at:

    /tmp/memreport-out/
    ├── rw_{phpunit,symfony-console,twig,parsedown,logger-stack,laravel-collections,psr-7-stack}.{rmem,db,report.txt}
    ├── rw2_{json-decode-huge,csv-mega,xml-dom-huge,error-context-capture,eloquent-hydration,guzzle-buffered,spreadsheet-xlsx}.{rmem,db,report.txt}
    ├── rw3_{doctrine-uow,static-cache,graphql-shape,reflection-heavy,messenger-envelopes,closure-leak}.{rmem,db,report.txt}
    └── rw4_{wordpress-bootstrap,generator-leak,graph-recursion,enum-collections,pdo-result-hoarding}.{rmem,db,report.txt}

**After a fix, the full regeneration is**:

    ./reli inspector:memory:report <path>.db --output-format=report \
      --output=<path>.report.new.txt --memory-limit=4G

No dump, no analyze, no docker — the .db is the cached post-analyze
state, and `inspector:memory:report` re-reads it cheaply (seconds).

So a verification loop for any report-level fix is:

1. Rerun `inspector:memory:report` against all 25 .db files in
   parallel.
2. `diff -u /tmp/memreport-out/rw*.report.txt
            /tmp/memreport-out/rw*.report.new.txt`
3. Check the diff matches the expected changes for the shipped tier.

For B1 (collector-side) the .rmem dumps would need to be
re-analyzed into fresh .db files (`inspector:memory:analyze`),
because the labels live in the substrate. That path needs docker
and takes minutes per scenario.

Schema of this handoff:
- **§Tier 1 implementation notes** — B1, B3, N1
- **§Tier 2 implementation notes** — B8/B9, B2/B6/N10
- **§Tier 3 implementation notes** — S12, N2, N4
- **§Verification checklist** — what to grep for after each fix
- **§Things to avoid** — walk-backs collected in the investigation
  session that the implementation session should not re-make

---

## Tier 1 implementation notes

### B1 — class/method names lower-cased in path labels

**File**: `src/Lib/PhpProcessReader/PhpMemoryReader/Collector/Job/EmitClassTableJob.php:101–111`

**What's wrong today**: the collector uses `$bucket->key` (the Zend
class_table HashTable key — case-folded for case-insensitive
dispatch) as the node label. Evidence: every `class_table->...`
path in the corpus shows lowercased class names
(`class_table->symfony\component\dependencyinjection\containerbuilder->methods->createservice->...`,
`class_table->composer\autoload\composerstaticinit32e1def09fefbf05b3038ecf2fa0a6e2->...`).

Same issue applies to the methods table (`methods->createService`
shows as `methods->createservice`) starting around line 309 of the
same file.

**Two fix shapes, pick one (recommendation: (i))**:

**(i) Emit-time fix.** Resolve `$class_entry->name` before
`emitNode` and use its string as the label. The canonical name is
already collected a few lines down as a `name` child:

        $class_name_context = CollectorHelpers::collectZendStringPointer(
            $class_entry->name,
            ...
        );

Just do that dereference earlier and pass the resolved string to
`emitNode`. Guard against dereference failure: fall back to the
lowercased `$bucket->key` so a malformed class table doesn't abort
the walk.

Methods table: same pattern — the canonical method name is
available from `$zend_function->common.function_name`.

**(ii) Display-time fix (less invasive on the collector):**
extend `NodeLabeler` (src/Inspector/Output/MemoryOutput/Report/Substrate/NodeLabeler.php)
to look up the `name` child of class-definition nodes and prefer
that. Downside: `name` is stored as a child node, not as a
`context_node_attributes` row, so `NodeLabeler::ensureLoaded`'s
SQL query would need joining through tree edges. Adds runtime cost
on the display path.

(i) is cleaner. Probably ~15–30 lines including the methods table.

**N11 extension**: same case-folding issue applies to
`function_table`. See rw4_wordpress-bootstrap — `remove_accents`
renders correctly only because WP chose all-lowercase names; any
camelCase user function would be munged. If the class-table fix
generalises to look up canonical names, apply the equivalent
treatment to function_table in the same pass.

### B3 — preview newline escape in Top Strings

**File**: `src/Inspector/Output/MemoryOutput/Report/Formatter/TextReportFormatter.php:251–257`

**What's wrong today**: Top Strings' preview is
`substr($preview, 0, 37) . '...'` with no whitespace escape. Strings
with embedded newlines (Carbon doc comments, Parsedown-rendered
HTML, PDO result rows with multi-line values) split the table row in
two. See `rw_logger-stack.report.txt` (Carbon doc_comments, 7 rows
each wrap) and `rw_s4_big_string.report.txt:52–53` (`$huge_html`).

**Fix**: one-line whitespace escape before truncation:

        $preview = strtr($preview, [
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
            "\0" => '\\0',
        ]);

Apply around line 251, before the `strlen > 40` check. Consider
also escaping in the `$display_path` branch above it just below
line 252–254 for consistency (paths are less likely to carry
newlines, but the formatter shouldn't assume).

### N1 — `empty_object` should filter internal classes

**File**: likely `src/Inspector/Output/MemoryOutput/Report/Pass/`
— grep for `kind: 'empty_object'`. (The investigation session didn't
pinpoint the exact file.)

**What's wrong today**: `empty_object` flags every class with zero
user-declared properties as "may be replaceable". It misfires on
every PHP internal class because internal classes by definition have
no user-declared properties while still carrying significant C-side
state.

Corpus hits (9/25 reports): rw_phpunit (ReflectionClass),
rw_symfony-console (Closure), rw_twig (ReflectionMethod),
rw3_closure-leak (Closure — the *actual leak root*),
rw3_messenger-envelopes (Closure — the retry-stamp retainer),
rw3_graphql-shape (Closure resolvers),
rw4_generator-leak (Generator), rw4_wordpress-bootstrap (Closure
hook callbacks), rw4_messenger-envelopes (BusNameStamp — user
class without declared properties; this one should legitimately
remain).

**Right fix**: check the Zend class-entry `type` (user = 0x01 vs
internal = 0x02) and skip internals. If the substrate doesn't
already expose this, a short-term denylist will do:

        Closure, Generator, Fiber,
        WeakMap, WeakReference,
        SplObjectStorage, SplFixedArray, SplDoublyLinkedList,
          SplQueue, SplStack, SplHeap, SplPriorityQueue,
        DateTime, DateTimeImmutable, DateInterval, DateTimeZone,
          DatePeriod,
        DOMDocument, DOMElement, DOMNode, DOMText, DOMAttr,
          DOMNodeList,
        SimpleXMLElement, XMLReader, XMLWriter,
        PDO, PDOStatement,
        mysqli, mysqli_stmt, mysqli_result,
        ReflectionClass, ReflectionMethod, ReflectionProperty,
          ReflectionFunction, ReflectionParameter,
          ReflectionExtension, ReflectionAttribute,
          ReflectionEnum, ReflectionEnumBackedCase,
          ReflectionEnumUnitCase, ReflectionUnionType,
          ReflectionIntersectionType, ReflectionNamedType,
        ArrayObject, ArrayIterator, CachingIterator,
          RecursiveDirectoryIterator, DirectoryIterator,
          FilesystemIterator, GlobIterator,

The class-entry type check is the correct long-term implementation;
the denylist is defensible as a stepping stone.

**After the filter, residual empty_object findings should be
genuinely user-defined classes with no declared properties** —
cases where the advice "consider value type / enum / flag-only
class" actually applies. From the corpus:
  - `Twig\Node\NameDeprecation` (1,440 instances — candidate for enum)
  - `BusNameStamp` (50,000 instances — marker stamp, could be enum)

---

## Tier 2 implementation notes

### B8/B9 — `bottleneck_path` shows the leaf path with the root's retained size

**Files**:
- `src/Inspector/Output/MemoryOutput/Report/Pass/DrillDownPass.php`
  (line 110 is the "bug origin")
- `src/Inspector/Output/MemoryOutput/Report/Formatter/TextReportFormatter.php`
  (the fix lives here)

**What's wrong today**: `DrillDownPass::analyze()` descends the
heaviest-child spine up to 12 depths. At line 110:

        $total_size = $path_sizes[0] ?? 0;

That's the **root of the spine's** retained size. The summary then
reads:

        sprintf('%s (%s)', $path_str, SizeFormatter::format($total_size))

so the leaf path (depth 12) is printed with the size from depth 0.
In `rw2_json-decode-huge.report.txt` this produces:

    bottleneck_path: $decoded[data][10100][profile] (171.45 MB)

where `$decoded[data][10100][profile]` is a ~2 KB sub-dict among
25,000 uniformly-sized siblings, and the 171 MB is the total
global_variables subtree.

**Critical insight** (don't miss this): the Finding already carries
**all the information needed to fix this** in `facts.sizes`. For
the above report:

    "sizes": [
        325480905,  325479089,  325475106,  325475106,  324977898,
        3336,       3336,       3184,       2520,       2240,
        2144,       2144
    ]

The drop from ~325 MB to 3 KB at index 5 is the "uniform sibling
point". A pass-only fix isn't required — the text formatter can
read `facts.sizes` and `facts.path` and render correctly.

**Recommended fix (text-formatter only, ~20 lines)**:

1. In `TextReportFormatter`, when rendering a `bottleneck_path`
   finding, read `$finding->facts['sizes']` and `->facts['path']`.
2. Walk `sizes[]` from index 0. Find the first `i` where
   `sizes[i+1] < sizes[i] * 0.5` (dominance drop threshold —
   tunable; 0.5 is a safe start).
3. Render the pre-drop chain as the "spine" (with its shrinking
   sizes) and the post-drop continuation as a "leaf example" with
   a note like "mass drops — ~N similar siblings below":

        [HIGH] bottleneck_path
          Drill-down from root (heaviest-child at each depth):
            $decoded                310 MB
            → [data]                310 MB (one of 2 roots, dominant)
            → [10100]               3.4 KB  ← weight spreads from here
                                            (25,000 uniform siblings)
          Leaf example continues:
            → [profile] → ... → (string)

4. If no drop is found (descent is uniformly heavy), render the
   whole chain with the final leaf's size as impact.

**Do not** change `DrillDownPass` itself in this step. The pass's
data is fine; the formatter is the leak.

### B2 / B6 / N10 — `retained × count` inflation across passes

Symptom: impact values that vastly exceed the heap total.

Evidence:
- `rw_logger-stack.report.txt`: `[LOW] 722.27 MB impacted /
  dedup_candidate` on an 11.96 MB heap (60× heap)
- `rw4_graph-recursion.report.txt`:
  `[MEDIUM] 53.73 GB impacted / property_scaling` on 111 MB (**500×**)
  `[LOW] 108.55 GB impacted / dedup_candidate` (**1000×**)
- `rw3_doctrine-uow.report.txt`: saner magnitudes but same shape

There are **two independent causes**; both need fixing:

#### B6 — `dedup_candidate` groups heterogeneous classes by shallow size

**File**: `src/Inspector/Output/MemoryOutput/Report/Pass/DedupCandidatePass.php:180–201`

The SQL groups by `(link_name, node_size)` only:

        GROUP BY e.link_name, cs.node_size
        HAVING count(...) > 50 AND count(...) * cs.node_size > 10240

So any two nodes linked via the same slot with the same shallow
size land in the same bucket — regardless of class. In the
logger-stack run, `Monolog\Handler\TestHandler` (1 instance,
shallow 152 B, retained 4.7 MB) and 3,000 `Monolog\LogRecord`
instances (shallow 152 B, retained ~3 KB each) ended up in the same
bucket. `getRetainedForDedup` then averaged the sample's retained
sizes — the outlier dominates the mean — and multiplied by 3,001.

**Fix**: include `target_class` in the GROUP BY. You'll need a JOIN
against the child node's class-entry location info. Once the
bucket is class-homogeneous, outliers of this kind can't form.

#### N10 — `retained × count` is not heap-valid under SCC sharing

Affects both `DedupCandidatePass.php:92`:

        if ($retained > $shallow_size) {
            $size = $retained;
            $total = $cnt * $retained;
        }

and (per N10 evidence from rw4_graph-recursion) `property_scaling`
pass — grep for it; the same `× count` pattern lives there too.

When nodes share a subtree (SCC members, or any DAG), `retained`
double-counts the shared part. Multiplying by N re-counts it N times
on top of that.

**Short-term guardrail** (ships independently of the real fix):
clamp the emitted `impact_bytes` to `min($total, $heap_total_bytes)`
in both passes, and append a note to the hypothesis when the clamp
fires: "(clamped from X MB — involves shared subtree)". That alone
eliminates the "1000× the heap" surprises.

**Medium-term fix**: switch `impact_bytes` for these kinds from
`total = count × per_copy_retained` to a saving estimate:
`saving = total_memory_currently_in_pattern - one_representative_size`.
That computes what you'd actually save by de-duplicating /
removing one copy's worth.

**Longer-term fix**: `impact_bytes` across finding kinds doesn't
mean the same thing. See
`memory-report-ux-improvements.md` §"The `impact_bytes` semantics
tradeoff" for 4 candidate resolutions (A–D). Recommendation:
implement resolution (A) — `current_bytes` unified, with a separate
`saving_estimate_bytes` — which removes the cross-kind mixing
without breaking JSON consumers. That work is larger than this
handoff's scope; the clamp guardrail is enough to ship T2.

---

## Tier 3 implementation notes

### S12 — cluster findings by target across detector kinds

**File**: `src/Inspector/Output/MemoryOutput/Report/Formatter/TextReportFormatter.php`

Already a partial clustering exists: JSON emits `large_array`,
`root_blame`, `type_ranking`, `class_ranking` as individual Findings
but the text formatter aggregates those kinds into tables (Top
Arrays, Root Blame Allocation, Type Breakdown, Top Classes).

What's missing: **cross-kind clustering** where several kinds
converge on the same target. `rw3_messenger-envelopes.report.txt`
shows the worst case — 22 findings for one phenomenon (50k envelope
accumulation), split across `choke_point`, `bottleneck_path`,
`companion_cluster`, `expensive_property` (6×), `empty_object` (3×),
`ownership_pattern`, `structural_duplicate` (7×), `dedup_candidate`.

**Approach**: post-process the Findings list before rendering:
group by primary target (node_id or class_name), pick one
representative per group, render others as "also detected as"
evidence lines.

Probably 80–150 lines in the formatter. Start with just two
grouping keys:
- `facts.class_name` when present
- `evidence_node_ids[0]` otherwise

If those produce reasonable groupings on the four noisy reports
(`rw3_messenger-envelopes`, `rw3_doctrine-uow`, `rw3_eloquent-hydration`,
`rw_logger-stack`), ship it.

### N2 — separate positive signals from warnings

Affected finding kinds: `shared_singleton`, `shared_fanin` (when
refs/targets ratio is high).

Evidence:
- `rw3_reflection-heavy`: `AttributeMetadata::$ignoredAttributes:
  4,499 refs -> 1 target` means CoW is already sharing — positive
- `rw4_pdo-result-hoarding`: `key -> ? (4,500,030 refs -> 39 targets,
  115385.4 each)` means string interning is working — positive
- `rw4_enum-collections`: `$payment -> PaymentMethod (59,996 refs
  -> 4 targets)` means enum is correctly shared — positive

Currently all three land in "Additional Info" with `[shared_*]`
formatting identical to actual problems.

**Fix**: split the Additional Info section into two sub-sections:

    Observations (no action needed):
      [CoW share] AttributeMetadata::$ignoredAttributes (4,499 × 1)
      [interning] HashTable keys: 4.5M refs → 39 interned strings

    Minor findings:
      (rest)

Deciding which bucket a finding goes into:
- `shared_singleton` → always "Observations" (it means shared)
- `shared_fanin` with `refs/targets >= 100` → "Observations"
  (it means well-deduped)
- `shared_fanin` with `refs/targets < 10` → "Minor findings"
  (potential dedup opportunity)

### N4 — collapse uniform-sibling rows in Top Arrays / Top Strings

**File**: `src/Inspector/Output/MemoryOutput/Report/Formatter/TextReportFormatter.php`
(Top Arrays and Top Strings rendering)

Recurring across the corpus: multiple rows of same-sized siblings
take separate lines:

    rw2_error-context-capture:
      3  54.50 KB  #199    $errors[0]->frames
      4  53.99 KB  #1104238 $errors[1000]->frames
      5  53.99 KB  #1105342 $errors[1001]->frames
      ... (7 rows total)

    rw3_graphql-shape:
      8  672 KB  $queryResult[viewer][recentActivity][0]
      9  672 KB  $queryResult[viewer][recentActivity][1]
     10  672 KB  $queryResult[viewer][recentActivity][2]

    rw4_pdo-result-hoarding: $byUser[0..5] all 19.42 KB (6 rows)
    rw4_enum-collections: $byStatus[pending..refunded] all 256 KB (6 rows)

**Fix**: in the rendering loop, detect runs where consecutive rows
have the same `retained`, same `element_count`, and paths that
differ only in an array-index suffix. Collapse into one row with a
"+N more similar" annotation:

    3  54.50 KB  #199  $errors[0]->frames
        (+6 more similar siblings at ~54 KB each, indices [1000..1006])

Same shape for Top Strings (multiple identical-size Carbon
doc_comments in rw_logger-stack).

~30 lines total. Purely formatter-side.

---

## Verification checklist

After each tier lands, rerun `inspector:memory:report` against all
25 saved .db files and check the diff. Suggested grep patterns:

### T1 verification

**B1 — no more lower-cased class names in class_table/function_table paths**:

    grep -E 'class_table->[a-z][a-z0-9_\\]+\b' \
      /tmp/memreport-out/rw*_*.report.txt

Before: hundreds of hits (`composerstaticinit...`,
`twig\extension\coreextension`, `symfony\component\dependencyinjection\...`).
After: only genuinely-lowercase names like `stdclass` or framework
classes that really are lowercase. The big red flag:
`class_table->composer\autoload\composerstaticinit32e1def...` should
read `class_table->Composer\Autoload\ComposerStaticInit32e1def...`.

Spot-check reports: `rw_symfony-console`, `rw_twig`, `rw_phpunit`,
`rw3_static-cache` (paths go through `class_table->LookupService->...`),
`rw4_wordpress-bootstrap` (`function_table->remove_accents` — should
be unchanged, since `remove_accents` is lowercase).

**B3 — no split Top Strings rows**:

    grep -B1 '^\.\.\.$' /tmp/memreport-out/rw*_*.report.txt

Before: matches in `rw_logger-stack` (Carbon doc_comments) and
`rw_s4_big_string` (huge HTML blob). After: zero matches.

Also spot-check that `\n`/`\r`/`\t` appear literally in previews,
not as actual newlines/tabs.

**N1 — no `empty_object` findings for internal classes**:

    grep -E 'empty_object: (Closure|Generator|Reflection|WeakMap|DateTime|DOMDocument|PDO|mysqli|Spl)' \
      /tmp/memreport-out/rw*_*.report.txt

Before: ~9 hits. After: 0 hits. Residual `empty_object` findings
should be user-defined classes only (expect `Twig\Node\NameDeprecation`
in rw_twig and `BusNameStamp`/other marker stamps in
rw3_messenger-envelopes to remain).

### T2 verification

**B8/B9 — bottleneck_path shows sensible leaf + size relation**:

Eyeball these specific findings against their reports:

- `rw2_json-decode-huge`: was `$decoded[data][10100][profile] (171.45 MB)`.
  After: path should stop at or near `$decoded[data]` with ~84 MB,
  or show a spine → leaf with both sizes.
- `rw2_csv-mega`: was `$orders[0][items][0] (461.70 MB)`. After:
  shouldn't attribute 461 MB to a single item.
- `rw4_pdo-result-hoarding`: was `$rows[0][metadata][ref] (442.00 MB)`.
  After: similar.
- `rw3_closure-leak`, `rw3_messenger-envelopes`, `rw4_enum-collections`,
  `rw4_generator-leak` — all show arbitrary-index leaves today.

Automated smoke test: for each report, check that the impact size
in `bottleneck_path: ... (X MB)` is less than the total heap size.

**B2/B6/N10 — no finding impact exceeds heap total**:

    for db in /tmp/memreport-out/rw*_*.db; do
      # extract heap total from the matching .report.txt and compare
      # against each finding's impact_bytes
      ...
    done

Concrete failures to eliminate:
- `rw_logger-stack`: `[LOW] 722.27 MB impacted` on 11.96 MB heap
- `rw4_graph-recursion`: `[MEDIUM] 53.73 GB impacted`,
  `[LOW] 108.55 GB impacted` on 111 MB heap
- `rw3_laravel-collections`: `[LOW] 2.23 MB impacted` (borderline)

After the clamp, no finding's impact_bytes should exceed the heap
total. After the B6 bucket fix, the `dedup_candidate` groups should
be class-homogeneous — verify `facts.source_class` /
`facts.target_class` in the JSON are single values per finding, and
the `Examples:` line doesn't mix classes.

### T3 verification

**S12 — noisy reports collapsed**:

Pre-fix finding counts:
- `rw3_messenger-envelopes`: 22 findings
- `rw3_doctrine-uow`: 25 findings
- `rw2_eloquent-hydration`: 10+ findings

Post-fix: expect the noisy reports to show ≤10 top-level findings,
with the collapsed ones visible as "also detected by" evidence
lines. Check `rw3_messenger-envelopes` specifically — the 22-finding
case is the flagship evidence report.

**N2 — Additional Info split into two blocks**:

Expect:

    === Observations (no action needed) ===
    [CoW share] ...
    [interning] ...

    === Minor findings ===
    ...

in the noisy reports. Spot-check `rw3_reflection-heavy` (has
`$ignoredAttributes: 4499 -> 1`) and `rw4_pdo-result-hoarding`
(has `key -> ? (4.5M → 39)`).

**N4 — uniform sibling rows collapsed**:

Look for Top Arrays / Top Strings showing `(+N more similar ...)`
annotations in: `rw2_error-context-capture`, `rw3_graphql-shape`,
`rw4_pdo-result-hoarding`, `rw4_enum-collections`.
