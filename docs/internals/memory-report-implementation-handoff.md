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

## T2.1 implementation notes — dedup `cnt × retained` → union aggregation

Independent from T3. Can ship in either order. Closes the
"`impact_bytes` is just a clamped ceiling, not actual memory in this
pattern" caveat surfaced during T2 verification (see
`memory-report-implementation-verification.md` § "Caveat: clamp is a
guardrail, not a saving estimate" and § "Where the over-counting
actually happens").

### What's wrong today

`DedupCandidatePass.php:92` and `PropertyScalingPass.php:191` (and
their FFI/binary equivalents) compute `impact_bytes` as `cnt ×
sample_mean(retained)`. The substrate's per-node retained is
correct (tree-edge DFS, no DAG double-count), but the **aggregation
treats N copies as if each owned an independent retained subtree**.
For a spanning-tree of an SCC, members near the spanning-tree root
have `retained ≈ entire reachable subtree`. Multiplying by N counts
the spanning tree's upper-level bytes O(N²) times.

`rw4_graph-recursion` exhibits both passes:

    [LOW] 111.45 MB impacted   (clamped from 108.55 GB) — dedup_candidate
    [MEDIUM] 111.45 MB impacted (clamped from 53.73 GB) — property_scaling

Heap is 111 MB, so clamp = heap ceiling, which is **still wrong as
a "memory in this pattern"** number. A reader interpreting
"111 MB impacted" as either the current usage of that pattern or
the savings if removed gets a wrong answer in either direction.

### Proposed fix

Replace `cnt × sample_mean(retained)` with **union of tree-edge
subtrees from the N seeds**:

    visited = empty set
    for each seed in dedup_group_members:
        BFS from seed via strong_children, skipping nodes already in visited
        add traversed nodes to visited
    impact_bytes = sum_over_visited(node_size)

Properties:

- Each node counted exactly once. No DAG / spanning-tree
  double-counting.
- Bounded above by heap total automatically — no clamp needed.
- Represents "memory currently sitting under the union of these
  N copies" — a meaningful, well-defined number.
- For the independent-copies case (e.g., logger-stack 3,000
  LogRecord), produces ≈ the same number as today's clamped
  output (since the subtrees are nearly disjoint). For the
  SCC-shared case, produces a much smaller, accurate number.

### What this does *not* fix

This is the *current_bytes* part of resolution A in the
`impact_bytes` semantics tradeoff. It does not produce a
**saving_estimate** — i.e., "if you de-duplicate these N copies,
how much would actually be freed". That requires either
`(N-1) × per_seed_exclusive_size` (where exclusive_size is what
each seed dominates uniquely — needs a dominator tree) or some
other dedup-specific calculation. Saving estimate stays parked
under resolution A's full implementation.

After T2.1: the displayed impact_bytes is honest as "memory
currently in this pattern", reads naturally as the upper bound
on potential saving (you can't save more than what's there), and
the clamp note becomes unnecessary.

### Performance budget

Per-finding cost: O(union of reachable set). For the top-10 dedup
groups the pass currently emits, total work is bounded by
O(10 × heap_node_count). Concretely:

- `rw4_graph-recursion` (10k seeds in a 140k SCC): ~140k visits
  per group × 10 groups = ~1.4M ops
- Independent-copies cases (`rw_logger-stack`, `rw3_messenger-envelopes`):
  each seed reaches its own small subtree; union ≈ direct-sum ≈
  cheap

Existing parallelism: `DedupCandidatePass` and `PropertyScalingPass`
already run in `ParallelPassRunner`'s Phase 3 alongside heavier
passes. Per `docs/internals/memory-report-performance-hotspots.md`
priority list, the wall-clock-critical passes are
**`CycleClusterPass`** (multiple graph walks per top SCC group) and
**`BlameAllocationPass`** (BFS from every root). The added union
BFS in dedup passes likely fits inside the wall-clock window of
those, so the change should be invisible in end-to-end
`inspector:memory:report` time on most reports.

If profiling shows otherwise on a specific scenario, the substrate
hotspots-doc Priority 1 (no-allocation `getChildren` /
`getStrongChildren` APIs) reduces the per-visit cost across all
passes simultaneously and is a higher-leverage win than
re-optimising T2.1 specifically.

### Implementation sketch

1. Add `unionReachableTreeNodes(list<int> $seeds): array<int, true>`
   to the substrate (returns set of node_ids reachable via
   `strong_children` from any seed). Cost is O(|union|), shared
   across multiple uses.
2. In `DedupCandidatePass`, replace
   `$total = $cnt * $retained` with the union sum, and drop the
   clamp logic (no longer needed — union is intrinsically bounded
   by heap). Keep `total_waste_unclamped` field omitted (no clamp
   to record).
3. In `PropertyScalingPass`, do the same: per-property union over
   the seeds for that property's child node, summed once.
4. The `(impact clamped from … — over-counts shared subtree)`
   hypothesis line goes away.
5. JSON consumers: `total_waste` / `per_instance_total_bytes`
   semantics change from "N × mean retained, clamped to heap" to
   "union over seeds". Document the shift in the JSON schema notes.
   Old `total_waste_unclamped` field disappears.

### Verification plan

Same artifacts as T1/T2 (the saved `.db` files in
`/tmp/memreport-out/`). Re-run `inspector:memory:report` on each
and check:

- `rw4_graph-recursion` `dedup_candidate` and `property_scaling`
  emit values within heap total, ideally much *less* than the
  clamp's 111.45 MB (the SCC is shared; union should be a fraction
  of the SCC).
- `rw_logger-stack`, `rw3_messenger-envelopes`, `rw4_enum-collections`:
  values stay close to today's clamped numbers (their copies are
  largely independent so union ≈ N × per-copy).
- No finding's reported impact exceeds heap total (no clamp needed).
- Hypothesis text no longer carries the clamp note.

### Order vs T3

T2.1 is purely pass-level math. T3 is formatter-level narrative.
They don't conflict. Pick whichever has the maintainer's interest
first; either can ship without the other.

If T2.1 ships before resolution A (the full `current_bytes` /
`saving_estimate_bytes` split), the displayed `impact_bytes` will
be a tight `current_bytes` value rather than a misleading clamped
ceiling. resolution A would then add the saving-side metric on
top, without disrupting the work T2.1 does on the current-side.

---

## T2.2 implementation notes — Spine drop point should name a path component, not a depth number

Polish-tier T2 follow-up. Independent of T2.1 and T3, ~15 lines.

### What's wrong today

`TextReportFormatter::renderBottleneckSpine()` emits:

    Spine: heaviest-child mass drops at depth 5 (30.24 MB → 10.00 MB);
           leaf retains only 0 B

`depth 5` is engineering-internal: the reader has to count path
components from the start of `facts.path[]` to find what's at depth
5, then map that back to the rendered `summary_path`. There's no
context that would make "5" meaningful by itself — it's just an
index into an array the reader doesn't see.

For a real `bottleneck_path` like

    Reli\...\MemoryLocationsCollector::collectAll:297::$sink->addressMinNode[0]

"drops at depth 5" forces the reader to mentally split the rendered
path into 6+ segments and count, just to identify *where* in their
own variables the dominance breaks.

### Proposed fix

Render the drop position by **path component name** rather than depth
index:

    Spine: heaviest-child mass drops after $sink->addressMinNode
           (30.24 MB → 10.00 MB); leaf retains only 0 B

The pre-drop component is `facts.path[$drop_index]`. Two render
shapes worth considering — both small:

**(a) Single-line, drop-component named:**

    Spine: heaviest-child mass drops after $sink->addressMinNode
           (30.24 MB → 10.00 MB); leaf retains only 0 B

**(b) Mini staircase showing where mass concentrates:**

    Spine: 30.24 MB at $sink->addressMinNode
         → 10.00 MB at [0]
         → ... → 0 B (leaf)

(a) is the simpler change; (b) gives a sharper visual when the
spine is interesting. Either works.

### Implementation sketch

1. In `renderBottleneckSpine()` (around the `sprintf('Spine: ...')`
   line), read `$finding->facts['path']` (already populated by
   `DrillDownPass`).
2. Resolve the drop-index component to a user-facing label. Two
   options:
   - Use `PathFormatter::toPhpSyntax($path_slice, $types_slice)`
     on the prefix up to and including `$drop_index` — produces
     the `$sink->addressMinNode`-style suffix that matches what
     `summary_path` already shows.
   - Or just take `$path[$drop_index]` raw and skip the
     PathFormatter call (cheaper but inconsistent with the rest of
     the path rendering — won't render `array_elements` correctly).
3. The existing `depth %d` becomes either an "after `<component>`"
   phrase or the staircase form.

### Edge cases

- **Long paths**: cap at last 2–3 components when the prefix is
  long: `... ->config->servers[0]` rather than the full string.
  `summary_path` already does similar truncation logic to learn
  from.
- **Path-only-up-to-drop**: the `summary_path` field on the
  `bottleneck_path` finding shows the full leaf path. The Spine
  line should show **the component where the drop occurs**, not
  the leaf — those are different things and the reader needs the
  former.
- **No-drop case**: existing code returns `[]` (no spine line)
  when there's no drop; keep that branch unchanged.

### Verification plan

Same artifacts (the 25 saved `.db` files in `/tmp/memreport-out/`).
Re-run `inspector:memory:report` and check:

- All existing `Spine:` lines still appear
- `at depth N` substring is gone
- The drop component is a recognisable user-side identifier (`$sink`,
  `$decoded[data]`, etc.) and not an internal label like
  `array_elements` or `value`

Spot-check reports: `rw2_json-decode-huge`, `rw2_csv-mega`,
`rw4_pdo-result-hoarding`, `rw4_eloquent-hydration`, and the
profile-yourself one with the spine descending into
`MemoryLocationsCollector::collectAll`.

---

## G4 implementation notes — display-time canonical names skip is_tree=0 edges

Bug fix follow-up to PR #648 (the display-time approach that
replaced the reverted PR #644 emit-time approach). PR #648's
`NodeLabeler` and `BinaryReportDataProvider::loadCanonicalNames`
both filter the `name` edge by `is_tree = 1`, which causes nearly
half of all `ClassDefinitionContext` rows to fail the canonical-
name lookup in real captures.

### What's wrong

`rw_phpunit.db` (one of the 25 saved corpus reports) has 448
`ClassDefinitionContext` rows; the labeler's SQL resolves
canonical names for only **240** (54%). The 208 missing entries
are user-defined classes (Composer\Autoload\ClassLoader,
PHPUnit\Framework\TestCase, GeneratedTest0, etc.) — exactly the
classes a reader of the report would care about. Internal classes
(Traversable, Iterator, Closure, ...) succeed because their
`name` edge happens to land as `is_tree = 1`.

The mechanism: when a class is registered after its name string
has already been collected by some other path (autoload,
opcache-loaded interned strings, etc.), the string node already
exists at `$class_definition_context->add('name', ...)` time, so
the resulting edge is recorded as `is_tree = 0` (back-reference
to an existing node). For internal classes loaded first via the
class table walk, the edge is `is_tree = 1`. Either way the
target string node and `string_value` are identical — the edge
flag just records who discovered the node first.

The labeler's `is_tree = 1` filter doesn't affect data validity;
it just throws away half the data.

### Files

- `src/Inspector/Output/MemoryOutput/Report/Substrate/NodeLabeler.php:178`
  (`AND name_edge.is_tree = 1`)
- `src/Inspector/Output/MemoryOutput/Report/BinaryReportDataProvider.php:691, 708`
  (the parallel binary-path edge walks; both branches of the
  `castSection`-vs-raw-bytes fork have the same filter)

### Fix

Remove the `is_tree` filter from all three sites. Each
`ClassDefinitionContext` (and `*FunctionDefinitionContext`) has
at most one `name`-link edge by construction, so dropping the
filter doesn't introduce duplicates. The `string_value` of the
target node is the same regardless of edge tree-ness.

Sanity check after the fix: the 240/448 ratio should become
roughly 448/448 (modulo the small set of class entries that
truly have no `name` child for unrelated reasons).

### Verification

Same artifacts as everything else — the 25 saved `.db` files in
`/tmp/memreport-out/`. After the fix, re-running
`inspector:memory:report` should show:

- `rw_phpunit`:
    `class_table->generatedtest0->...` → `class_table->GeneratedTest0->...`
    `class_table->phpunit\framework\testcase->...` →
       `class_table->PHPUnit\Framework\TestCase->...`
    `class_table->composer\autoload\composerstaticinit32e1def...->...` →
       `class_table->Composer\Autoload\ComposerStaticInit32e1def...->...`
- `rw_twig`:
    `class_table->twig\extension\coreextension->...` →
       `class_table->Twig\Extension\CoreExtension->...`
- `rw_symfony-console`:
    `class_table->symfony\component\dependencyinjection\containerbuilder->...` →
       `class_table->Symfony\Component\DependencyInjection\ContainerBuilder->...`
- `rw3_static-cache`:
    `class_table->lookupservice->...` → `class_table->LookupService->...`
    (and the three sibling repositories)

Quick comprehensive grep:

    grep -hE 'class_table->[a-z]+\\' /tmp/memreport-out-impl-postfix/rw*.report.txt | wc -l

Should fall to a small number (only genuinely-lowercase project
identifiers remain).

### Why this surfaced after PR #648 merged

I (the verification session) spot-checked PR #648 by comparing
the pre-fix and post-fix outputs on three representative reports
where canonical names *were* showing — a confirmation bias on
already-working internal classes. Running a corpus-wide
`grep 'class_table->[a-z]'` immediately after merge would have
caught this; the verification doc now records that grep as the
primary B1/G1/G4 acceptance check.

---

## T2.3 implementation notes — `dedup_candidate` label `raw:` (and similar) lacks owner class

Mixed bug-investigation + UX fallback request. Independent of all
other T2.* items. Recommended split: ship the **display fallback**
immediately (text-only, ~10 lines, no regression risk), run the
**investigation** in a separate session against a small repro DB.

### What's wrong

The user-facing report contains findings like

    [LOW] 16.28 MB impacted
      dedup_candidate: raw: 6,429 copies x 2.59 KB retained = 16.28 MB
      Multiple copies of same-size objects via shared references; ...
      Examples: FFI\CData (88B)

`raw:` is the bare `link_name`. Per
`DedupCandidatePass::buildDedupLabel()` the label degrades to that
shape only when **both `$source_class` and `$target_class` are null**.

But the next line — `Examples: FFI\CData (88B)` — proves the target
class is detectable somewhere. So the output we'd want is at minimum
`raw (FFI\CData)`, and ideally
`Reli\Lib\PhpInternals\CastedCData::$raw -> FFI\CData`.

### Investigation task (separate session)

Reproduce the scenario, get a `.db`, find out which of the candidate
failure modes is real:

**Repro recipe** (rough — adjust as needed):

    cat > /tmp/ffi-cdata-test.php <<'EOF'
    <?php
    require __DIR__ . '/vendor/autoload.php';
    $wrappers = [];
    for ($i = 0; $i < 5000; $i++) {
        $cdata = FFI::new('int');
        $wrappers[] = new \Reli\Lib\PhpInternals\CastedCData($cdata, $cdata);
    }
    fputs(STDOUT, "ready\n");
    fgets(STDIN);
    EOF

Then dump → analyze → query the resulting `.db`:

    -- 1. how many 'raw' edges?
    SELECT COUNT(*) FROM context_edges WHERE link_name = 'raw';

    -- 2. parent context types of those edges
    SELECT cn.type, COUNT(*) FROM context_edges e
    JOIN context_nodes cn ON cn.node_id = e.parent_node_id
    WHERE e.link_name = 'raw' GROUP BY cn.type;

    -- 3. parent's tree-link-name (what resolveDirectSourceClassFromSubstrate checks)
    SELECT pe.link_name, COUNT(*) FROM context_edges e
    JOIN context_edges pe
      ON pe.child_node_id = e.parent_node_id AND pe.is_tree = 1
    WHERE e.link_name = 'raw' GROUP BY pe.link_name;

    -- 4. grand-parent's class_name (the owner — the CastedCData object)
    SELECT cnl.class_name, COUNT(*) FROM context_edges raw_e
    JOIN context_edges obj_props_e
      ON obj_props_e.child_node_id = raw_e.parent_node_id
      AND obj_props_e.is_tree = 1
      AND obj_props_e.link_name = 'object_properties'
    JOIN context_node_locations cnl
      ON cnl.node_id = obj_props_e.parent_node_id
    WHERE raw_e.link_name = 'raw' GROUP BY cnl.class_name;

The diagnostic tells:

- **Query 3 returns something other than `object_properties`** →
  `resolveDirectSourceClassFromSubstrate` rejects on the first
  guard. Investigate whether FFI-wrapping classes don't go through
  `ObjectPropertiesContext` (e.g. CastedCData might emit
  properties via a different context).
- **Query 4 returns NULL or empty `class_name`** → `getNodeClass`
  returns null; investigate why the CastedCData object's class
  isn't recorded in `context_node_locations`. Possibly an
  EmitObjectJob path that skips class_name recording for some
  object kind.
- **Both queries return the expected shape but the report still
  shows bare `raw:`** → the dedup pass's
  `min(parent_node_id)` heuristic is picking a parent that isn't
  representative; investigate `loadDedupRowsFromSql` (in
  particular how the `sample_parent_node_id` interacts with
  class-homogeneous bucketing).

### Display fallback (independent quick win)

Whatever the root cause turns out to be, the formatter should
**always show whatever context is available**. The bare `raw:`
form drops both ends of the relationship even when the target
class is known.

Patch shape in `DedupCandidatePass::buildDedupLabel()`:

    if ($source_class !== null && $owner_prop !== null) {
        $label = "{$source_class}::\${$owner_prop}[{$link_name}]";
    } elseif ($source_class !== null) {
        $label = "{$source_class}::\${$link_name}";
    } else {
        // Source unknown. Surface the target class if known so the
        // reader doesn't read "raw:" as the entire identifier.
        if ($target_class !== null) {
            return "?::\${$link_name} -> {$target_class}";
        }
        return "(unknown owner)::\${$link_name}";
    }

    if ($target_class !== null) {
        $label .= " ({$target_class})";
    }

    return $label;

Result on the user's example:

    Before:  dedup_candidate: raw: 6,429 copies x 2.59 KB retained = 16.28 MB
    After:   dedup_candidate: ?::$raw -> FFI\CData: 6,429 copies x 2.59 KB retained = 16.28 MB

Even without solving the root cause, the reader now knows "some
class's `$raw` property points at FFI\CData". The `?::` is honest
about the gap.

### Order

Display fallback is text-only, ~10 lines, no JSON schema impact —
ship as part of any near-term follow-up.

Investigation is a separate session's deeper work; once the root
cause is known, the proper resolver fix supersedes the `?::`
fallback (which then only fires on cases where source is genuinely
unknowable, e.g. dynamic class loading).

---

## T2.4 implementation notes — Call-frame line numbers leak into non-stack path labels

Polish-tier T2 follow-up. Independent of all other T2.* items,
~10 lines.

### What's wrong

`NodeLabeler` stores a single string per call-frame node:

    // src/Inspector/Output/MemoryOutput/Report/Substrate/NodeLabeler.php:35
    /** @var array<int, string> node_id => "function_name:lineno" */
    private array $frame_labels = [];

`resolvePathLabel($link_name, $child_node_id)` returns this string
verbatim regardless of the calling context. The `:lineno` suffix
appears in every report section that traverses a call-frame node
on the way to user-land state, even though only the explicit
**Call Stack** rendering needs the line number for disambiguation.

Example from `bottleneck_path`:

    Reli\…\MemoryLocationsCollector::collectAll:297::$sink->addressMinNode[0]
                                          ^^^^
                                       noise — $sink doesn't depend on
                                       which line the frame is paused at

For Call Stack output the `:297` is genuinely informative ("we paused
at line 297 of `collectAll`"). For an ownership chain it just
distracts.

### Proposed fix

Two-part change:

**(1) Strip the line number when not rendering Call Stack.** Either:
- Add a `$include_call_site` flag to `resolvePathLabel()` (default
  `false`), set to `true` only at the Call Stack render site.
- Or store two maps in `NodeLabeler`: `frame_labels_with_line` and
  `frame_labels_function_only`. Pick by context.

**(2) Wrap the bare function name in parens for path contexts** so
the call-frame nature is obvious without ambiguity against PHP's
`Class::staticMethod::$staticProp` syntax:

    Before:  MemoryLocationsCollector::collectAll:297::$sink->...
    After:   MemoryLocationsCollector::collectAll()::$sink->...

The `()` clearly marks the frame as an invocation rather than a
class scope, while keeping `::` as the consistent "scope into" join.

### Final form by context

| Where rendered                       | Format                       | Example                                      |
|--------------------------------------|------------------------------|----------------------------------------------|
| Call Stack section                   | `function_name:lineno`       | `#0 MemoryLocationsCollector::collectAll:297` |
| `bottleneck_path` / other paths      | `function_name()::$var…`     | `MemoryLocationsCollector::collectAll()::$sink->addressMinNode[0]` |
| `Spine:` drop annotation (T2.2)      | same as path                 | `Spine: drops after collectAll()::$sink`     |

### Files

- `src/Inspector/Output/MemoryOutput/Report/Substrate/NodeLabeler.php`
  (the `frame_labels` map and `resolvePathLabel`)
- Whatever passes / formatter sites use the labeler — most need the
  no-lineno mode; only the Call Stack render site (in
  `TextReportFormatter` for the "=== Overview ===" block) needs the
  `:lineno` form

### Edge cases

- **`()` already in the function name** (closure name might end up
  including parens): unlikely, but keep the formatter idempotent
  (don't double-wrap).
- **Internal functions / built-ins**: same form, `()` reads
  naturally — `internalFunction()::...` is consistent.
- **Anonymous closures**: today they get a synthetic name like
  `{closure}:LineNo`. The same `:lineno` strip applies; rendering
  becomes `{closure}()::...`.

### Verification plan

Same artifacts (the 25 saved `.db` files in `/tmp/memreport-out/`).
Re-run `inspector:memory:report` and check:

- Call Stack section preserves `:lineno` form unchanged.
- All other sections (`bottleneck_path`, `choke_point`,
  `Top Arrays` paths, `Spine:` drop labels) render call frames as
  `function_name()` without the line number.

Quick check across the corpus:

    grep -hE '::[a-zA-Z_][a-zA-Z0-9_]*:[0-9]+::' \
      /tmp/memreport-out/rw*_*.report.txt

should return only Call Stack lines (or zero matches if the formatter
strips even there in the rare case it's not needed).

Spot-check reports include any "reli profiling itself" capture
where `MemoryLocationsCollector::collectAll` shows up on the spine.

---

## T2.5 implementation notes — bottleneck_path and Spine should share elision vocabulary

Polish-tier T2 follow-up surfaced during T2.2 re-verification.
Independent of T2.3 / T2.4. ~10–20 lines depending on shape.

### What's wrong

For shallow drops the two lines under a `bottleneck_path` finding
disagree on what to call the descent root:

    bottleneck_path: $decoded[data][10100][profile] (171.45 MB)
    Spine: heaviest-child mass drops after global_variables -> array_elements
           (171.44 MB → 84.33 MB); leaf retains only 1.94 KB

`bottleneck_path` elides the structural roots `global_variables` and
`array_elements` and starts the user-visible path at `$decoded`.
`Spine`, after PR #652, renders the full path prefix from the
structural root through to the drop index — so for a depth-1 drop
into `array_elements` it surfaces engine vocabulary the
`bottleneck_path` line consciously hides.

The two reads as if they're describing different paths.

Affected reports in the corpus:

- `rw2_json-decode-huge` — drop is at the `array_elements` of
  `global_variables`; Spine: `after global_variables -> array_elements`
- `rw4_graph-recursion` — same shape: `after global_variables -> array_elements`
- `rw3_static-cache` — drop is at `static_properties->staticCache`;
  Spine: `after ...->LookupService->static_properties->staticCache`
  (this one happens to read OK because the prefix already includes
  the user class, but the principle is the same)

Reports with the drop mid-userland (like
`after $bus->listeners[request.completed]`) are unaffected.

### Proposed fix

Two shapes; either works.

**(A) Share elision logic.** Whatever `DrillDownPass::selectSummaryPath`
and `PathFormatter::toPhpSyntax` use to pick the user-visible
`summary_path` (`$decoded[data][10100][profile]`), apply the same
rule to the Spine prefix slice. This keeps `bottleneck_path`'s
existing one-line summary unchanged and makes Spine speak the same
language.

In code: when rendering the Spine drop label, run the path[] +
path_types[] slice through the same elision wrapper used for
`summary_path` rather than calling `PathFormatter::toPhpSyntax`
on the raw slice.

**(B) Extend the slice.** When the slice ends on a structural-only
component (`array_elements`, `value`, `object_properties`,
`function_table`, `class_table`, etc.), extend it by one or two
steps until the last component is user-named. The drop point
itself doesn't change — the rendered label is just a slightly
longer prefix that reaches a recognisable identifier.

For the json-decode-huge case:

    Slice with drop at depth 1: [global_variables, array_elements]
    Extended to first user name: [global_variables, array_elements, decoded]
    Rendered: "after $decoded"

(B) is simpler and preserves Spine's mechanical "this was the
heaviest-child step where mass dropped" semantics. (A) is
more principled and guarantees consistency by construction. Pick
based on how much PathFormatter / DrillDownPass refactor the team
wants to do.

### Edge cases

- **Drop already at a user-named component** (most reports): no
  change needed; both schemes render identically to today.
- **Drop in the deepest slot of a user-named structure**
  (`$bus->listeners[request.completed]`): no change needed.
- **Reports with an entirely structural descent path**
  (rare — would be a path that never names a user variable, e.g.
  pure class_table internals): the extension in (B) might run
  out before finding a user-named segment. Cap at the original
  drop_index in that case so we don't accidentally surface deeper
  structural noise.

### Verification plan

Same artifacts (the 25 saved `.db` files in `/tmp/memreport-out/`).
Re-run `inspector:memory:report` and check:

- Reports that previously showed
  `after global_variables -> array_elements` now show a
  user-named identifier matching the `bottleneck_path` line.
  Specifically `rw2_json-decode-huge` and `rw4_graph-recursion`.
- All Spine lines that already named user-side components stay
  unchanged.
- `bottleneck_path` line is unchanged.
- Findings count regression: 0 across all 25 reports.

Quick consistency check:

    grep -hE 'bottleneck_path|Spine: ' /tmp/memreport-out/rw*_*.report.txt \
      | paste - -

Each pair should now describe the same path with consistent
vocabulary (no `global_variables -> array_elements` form on the
Spine line when `bottleneck_path` shows `$decoded`).

---

## T2.6 implementation notes — Quote string array keys in path display

Polish-tier T2 follow-up. Independent of all other T2.* items.
~5 lines in `PathFormatter::toPhpSyntax` (or its array-key
rendering helper).

### What's wrong

Current path output renders both numeric and string array keys
without quotes:

    $decoded[data][10100][profile]
    $bus->listeners[request.completed]
    $cache[query_cfcd208495d565ef66e7dff9f98764da]

These are read by PHP developers who normally write
`$arr['data']`, `$arr[$var]`, `$arr[42]`. An unquoted bareword
inside `[ ]` reads as either a constant or an undefined-constant
warning depending on PHP version — never as a string literal.
The path output diverges from that mental model just enough to
slow the eye down.

### Not about disambiguation

PHP arrays auto-cast numeric-string keys to integers, so there's
no semantic difference between `$arr[0]` and `$arr['0']` — both
land in the same int-keyed slot. Quoting numeric keys would be
incorrect (the runtime stores them as ints).

The fix is purely about matching what PHP source code looks like.

### Proposed fix

In `PathFormatter::toPhpSyntax` (the array-element step), wrap
non-numeric keys in single quotes:

    if (preg_match('/^-?\d+$/', $key)) {
        $rendered = "[$key]";        // numeric — leave bare
    } else {
        $rendered = "['$key']";      // string — quote
    }

Edge cases:

- Keys containing single quotes themselves (rare in real captures
  but possible): escape with `\'` so the rendered form parses as
  the original PHP literal.
- Keys that look numeric but aren't (e.g., `'01'`, `'0e123'`):
  PHP leaves these as strings at runtime. The simple `^\d+$`
  test treats them as numeric here, which is technically wrong
  but matches user intuition for the common case. A stricter
  check could use `is_int($key) || (is_string($key) &&
  $key === (string)(int)$key)` if needed.

### Examples after the fix

| Before                                            | After                                                   |
|---------------------------------------------------|---------------------------------------------------------|
| `$decoded[data][10100][profile]`                  | `$decoded['data'][10100]['profile']`                    |
| `$bus->listeners[request.completed]`              | `$bus->listeners['request.completed']`                  |
| `$cache[query_cfcd208495d565ef66e7dff9f98764da]`  | `$cache['query_cfcd208495d565ef66e7dff9f98764da']`      |
| `$rows[0][metadata][ref]`                         | `$rows[0]['metadata']['ref']`                           |

### Verification plan

Same artifacts (the 25 saved `.db` files in `/tmp/memreport-out/`).
Re-run `inspector:memory:report` and check:

- All `[<numeric>]` segments stay unquoted.
- All `[<non-numeric>]` segments become `['<value>']`.
- `summary_path`, Spine drop labels, `bottleneck_path`,
  `choke_point` paths, `Top Arrays` paths all use the same
  rendering (single source of truth in `PathFormatter`).
- No `[<bareword>]` form remains anywhere in the corpus reports
  except in known structural-only renderings outside `PathFormatter`'s
  responsibility.

Quick check:

    grep -hoE '\[[a-zA-Z_][^]]*\]' /tmp/memreport-out/rw*_*.report.txt \
      | grep -v "^\[\(HIGH\|MEDIUM\|LOW\|INFO\)\]" \
      | sort -u | head -30

Pre-fix returns lots of `[data]`, `[users]`, `[notes]`, etc.
Post-fix returns roughly zero (the survivors are non-PathFormatter
labels).

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

---

## Things to avoid — walk-backs collected in the investigation

The investigation session made several confident calls that turned
out to be wrong. Listed here so the implementation session doesn't
re-make them.

### "class_table findings are noise, filter them out"

**Wrong.** class_table legitimately dominates small heaps (CLI script
baselines) and static-property-heavy apps (`rw3_static-cache`). The
finding is factually correct; only the advice wording is off. See
the stocked `class_definition_overhead` proposal in
`memory-report-ux-improvements.md`. Do **not** suppress class_table
findings. The correct fix is context-specific guidance, not filtering.

### "cycle_cluster at 53 MB retained must be HIGH severity"

**Wrong.** `rw3_spreadsheet-xlsx` shows a 53.9 MB retained cycle
(PhpSpreadsheet's `cellXfSupervisor` pattern) flagged as LOW. The
investigation initially called that a bug. Rechecking:

1. PHP has a cycle GC; not every cycle is a leak.
2. `GcPendingPass` already emits `gc_pending_candidate` for SCCs
   reachable only through `objects_store` — the *actual* leak case.
3. Across all 25 captured reports, zero `gc_pending_candidate`
   findings fired. Every observed cycle was reachable from userland
   tree edges; LOW is the right severity.

Don't "upgrade" cycle_cluster severity; the distinction is already
working. Only open issue is that the finding's `impact_bytes` shows
the cycle's retained, which reads like a saving estimate — relabel
to `current_retained_bytes` if possible, or document in the
formatter.

### "retained × count double-counts shared subtrees N times"

**Approximately wrong as a mechanism statement.** The investigation
first explained B2 as "retained × count double-counts". The real
mechanism is more specific:

1. DedupCandidatePass buckets by `(link_name, node_size)` only,
   producing heterogeneous groups (B6).
2. `getRetainedForDedup` averages sampled members' retained sizes;
   outliers in the heterogeneous group skew the mean.
3. Multiplying skewed mean × total count produces the blown-up
   number.

For SCC-heavy graphs (rw4_graph-recursion) the amplification is
different again: every SCC member's retained includes the whole SCC
subtree. `N × retained` here = `N × (entire shared part)`, so the
effect is N² in the worst case.

Both cases share the surface symptom but neither is purely "×N
double-count". Write fixes that match the actual mechanism (B6
bucket fix + SCC-aware clamp), not a generic "divide by something".

### "bottleneck_path should be truncated at the class name"

**Wrong.** Early proposal was to elide everything below the class
name in class_table paths (so that `class_table->Foo->methods->bar->op_array->...`
became just `class_table->Foo`). Counter-argument from the user: a
script with auto-generated code, pathological doc comments, or a
runaway `static $cache` inside a method would need to see the
full descent to diagnose. Don't elide; improve the rendering
(sizes + drops).

### "non-ZendMM memory gap should be a Finding"

**Wrong layer.** An early proposal suggested firing a
`non_zendmm_memory` finding when RSS >> analysed heap. That would
alarm on every DOMDocument/libxml2/opcache scenario. Correct layer
is tool-level documentation (scope section in
getting-started.md / README): "reli analyses ZendMM; C-extension
memory and opcache shared mem are outside scope". Findings are for
in-scope things.

### "give up on a single sort axis — expose --sort-by"

**Tempting but defer.** The investigation entertained a
`--sort-by=current|saving|severity|heap_fraction` flag as escape
from the `impact_bytes` semantics tradeoff. Don't add this in T2.
The (A) resolution (`current_bytes` unified + separate
`saving_estimate_bytes`) is a strict improvement over today and
preserves the single-axis sort. Only consider `--sort-by` if users
ask for it post-ship.

---

## Scope discipline

Each tier is independently shippable. Don't bundle across tiers:

- T1 is purely additive — three small fixes, no JSON schema changes,
  no behaviour changes beyond labels.
- T2 makes number accuracy better; no new schema fields needed for
  the clamp + bucket fix. If you touch `impact_bytes` semantics,
  that's T2.5 / T3 territory.
- T3 affects layout and narrative; may touch `facts.*` conventions
  but avoid breaking JSON consumers.

**Do NOT** rewrite TextReportFormatter wholesale to "clean narrative
format" in one PR. Incremental shipping against the priority list
makes the whole series verifiable against the 25 saved reports.

Good luck. The 25-report corpus is the real test — each claim in
`memory-report-ux-improvements.md` names the specific report it
shows up in, so verification is "diff before/after on that report".

---

## T4 implementation notes — `bottleneck_path` text rendering should use `facts.sizes`

### What's wrong

Current `bottleneck_path` text output collapses three different
sizes into a single confusing line, captured as **N17** in the
fresh-eye review:

```
[HIGH] 186.11 MB impacted
  bottleneck_path: $processedEnvelopes[0]->stamps['RetryStamp'] (186.11 MB)
  Spine: heaviest-child mass drops after $processedEnvelopes[0] (185.86 MB → 4.30 KB); leaf retains only 2.10 KB
         leaf is one of many similar-sized siblings — weight is distributed, no single deep spine
  Heaviest retained branch — the primary chain of memory consumption
```

The reader sees three numbers (`186.11 MB`, `185.86 MB → 4.30 KB`,
`2.10 KB`), each meaning a different thing along the path, with
the first one rendered next to the leaf path so it reads as if
that leaf weighs 186 MB. It actually doesn't —
`[0]->stamps['RetryStamp']` retains 2.10 KB; the 186 MB is the
spine total at the root. Three separate framings of "what number
describes what node" without labels.

This is the canonical case of the **text formatter chartering
principle** added to `memory-report-ux-improvements.md`: the
formatter is collapsing structured per-step retained data
(`facts.sizes[]`) into a single number `(186.11 MB)` and inventing
prose to describe the rest. The data the JSON consumer gets
(`facts.path`, `facts.sizes`, `facts.depth`, `facts.path_types`,
`facts.summary_path`, `facts.leaf_path`) is full-fidelity; the
text rendering throws most of it away.

### What we want to communicate

`bottleneck_path` answers "where in code does the heavy region
live, walked from a root to a leaf, with shape annotations". It is
**not**:

- "what to release for max savings" — that's `choke_point`
- "what the heaviest single node is" — that's `dominant_class` /
  Top Arrays

Four distinct user questions ride the path:

1. **Where to look?** — code path (grep target)
2. **How big is the descent?** — total along the path
3. **Where does mass concentrate or fan out?** — interpret descent shape
4. **What do the leaves look like?** — leaf size + qualifier

The current single-line `(186.11 MB)` next to the path conflates
(2) into the wrong place, leaving (3) and (4) buried in the
hypothesis text.

### Proposed fix — render the descent

Drop the `(X MB)` parenthetical from the summary line. Render the
per-step descent as the primary visual element, sourced from
`facts.path[]` + `facts.sizes[]`. Keep the existing `Spine:`
hypothesis line since it already covers the "where mass drops"
question (just stop pre-pending the misleading total).

Two layout candidates — pick one, or pick by depth threshold:

**A. Vertical (good for ≥4 segments)**:

```
bottleneck_path:
  $processedEnvelopes                 186.11 MB
  └ [0]                               185.86 MB
    └ stamps                            4.30 KB
      └ ['RetryStamp']                  2.10 KB
  Spine: heaviest-child mass drops after $processedEnvelopes[0] (185.86 MB → 4.30 KB)
         leaf retains only 2.10 KB; weight is distributed across siblings
```

**B. Inline with arrows (good for ≤3 segments)**:

```
bottleneck_path: $propertyInfoCache (4.63 MB) → ['App\\Dto\\Dto0::attr_0'] (1.33 KB) → accessors (486 B)
  Spine: heaviest-child mass drops after $propertyInfoCache['App\\Dto\\Dto0::attr_0'] (4.63 MB → 1.33 KB)
```

**Each step gets its own size — including the first.** Don't try to
elide the first step's size on the assumption that the
`[HIGH] X impacted` line carries it. The two numbers are not the
same when the path has any structural prefix (`global_variables` /
`array_elements` / etc.) above the first user-visible step:

- `[HIGH] X impacted` carries `impact_bytes = sizes[0]` — the
  retained size at the path's *true root*, which in most reports is
  `global_variables` (i.e. all globals, not just one).
- The first user-visible step (`$propertyInfoCache`) lives at
  `sizes[i]` for some `i > 0` after `PathFormatter::isStructuralLink`
  skips the structural intermediaries.

When non-trivial state lives in `global_variables` outside the
chosen path, `sizes[0] > sizes[i]` and the user has no honest way
to read the first step's retained from the descent if it's omitted.
Concrete impl15 cases where the gap is meaningful:

| report | impact (sizes[0]) | first user-visible step retained |
|---|---|---|
| `rw3_reflection-heavy` | 7.21 MB (`global_variables`) | 4.63 MB (`$propertyInfoCache`) |
| `rw4_graph-recursion` | 110.97 MB (`global_variables`) | 49.51 MB (`$reachabilityCache`) |
| `rw_laravel-collections` | 7.52 MB | 5.06 MB (`$source`) |
| `rw_parsedown` | 1.77 MB | 758 KB (`$lines`) |

Vertical mode does not have this problem because every step renders
its size. For inline mode parity, render the size at every step
including index 0.

Recommendation: vertical for `depth >= 4`, inline otherwise. The
existing `summary_path` + `leaf_path` strings can stay for JSON
consumers; the text renderer just doesn't echo them as the primary
line.

### Files to touch

- `src/Inspector/Output/MemoryOutput/Report/Formatter/TextReportFormatter.php`
  — special-case `$finding->kind === 'bottleneck_path'` rendering.
  Read `$finding->facts['path']`, `['sizes']`, `['path_types']`,
  walk in step, render with `SizeFormatter::format()`. The existing
  generic finding loop renders `summary` + `hypothesis`; for
  `bottleneck_path` it should render the descent block instead of
  `summary`, while keeping `hypothesis` (Spine line) and `next_checks`
  unchanged.
- `src/Inspector/Output/MemoryOutput/Report/Pass/DrillDownPass.php`
  — leave `summary` as-is (so JSON consumers reading
  `Finding.summary` keep their current behaviour). The text
  formatter just substitutes its own descent block for
  `bottleneck_path` findings instead of echoing `summary`.

### Edge cases

- **Path indentation budget**: with 12-segment max paths and long
  variable names (`$processedEnvelopes`), the right column for
  size starts at column 35–50 depending on prefix indent. Use a
  fixed pad-right column for the size, truncate path component
  display if >40 chars (preserve last 37 with `...`-prefix, mirror
  the existing class-name truncation in Top Classes).
- **Leaf vs summary path divergence**: when `summary_path !=
  leaf_path` (the existing summarisation case), render the descent
  through the summary path's depth, then add a final "Leaf example:
  ..." continuation line for the longer leaf path. Don't try to
  show two parallel descents.
- **`facts.sizes[0] != impact_bytes`**: shouldn't happen, but guard
  with `$sizes[0] ?? $impact_bytes`.
- **Identical adjacent sizes**: when `sizes[i] == sizes[i+1]` for
  many steps (the path is a chain of single-child nodes), don't
  collapse — the steps are individually meaningful for
  understanding which variable name lives at each depth.

### Verification plan

1. Regenerate impl15 against the 25-report corpus.
2. Diff `bottleneck_path` rendering for each report against impl14.
3. Targeted readability check on the cases where impl14 was
   unclear:
   - `rw3_messenger-envelopes` — three-size confusion
   - `rw3_doctrine-uow` — long path with `<obj-hash>` keys
   - `rw3_closure-leak` — total-at-root vs leaf
   - `rw_wordpress-bootstrap` — engine-state path
   - `rw3_graphql-shape` — gentle taper case
4. Confirm JSON output (`-f report-json`) is unchanged — the fix
   is purely text-side.

### Charter principle this exercises

This is the first finding migrated to the
`memory-report-ux-improvements.md` "Text formatter chartering"
section's principle: render the data the JSON exposes; don't
collapse to lower-fidelity prose. After T4 ships, the next
candidates for the same treatment (whenever capacity permits) are:

- `choke_point` per-child distribution (currently just N children
  count; could expose top-K and fanout shape)
- `dedup_candidate.examples[]` (currently truncated to 3)
- `companion_cluster` per-class breakdown for clusters > 3 members

Each of those is a separate, smaller follow-up; T4 establishes the
pattern.

### Out of scope for T4

- N18/N19 engine-type vocabulary — parked separately. The descent
  block will still show `ZendArray*` labels for non-class path
  segments. That's intentional. Glossary / role labels is a
  separate initiative.
- Replacing `Spine:` line wording — the existing line is correct
  once the misleading total is removed from `summary`. Leave it.
- Cross-finding linking ("this drop coincides with choke_point
  #260") — also a separate initiative; keep T4 single-finding.
