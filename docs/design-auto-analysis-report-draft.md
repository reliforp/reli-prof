# Design: Auto-Analysis Report Feature for reli-prof

## Overview

Add a new output format `--output-format=report` that automatically runs
preset queries and heuristics against the collected memory data, producing
a human-readable diagnostic report to stdout.

This replaces the current workflow of:
1. `inspector:memory -p PID --output-format=sqlite3 --output=foo.sqlite3`
2. Open sqlite3 manually
3. Write SQL queries by hand

With:
```
inspector:memory -p PID --output-format=report
```

## Architecture

### New Components

```
src/Inspector/Output/MemoryOutput/
├── ReportMemoryOutput.php          # Main output class (implements MemoryOutputInterface)
├── Report/
│   ├── ReportBuilder.php           # Orchestrates analysis passes
│   ├── ReportSection.php           # Data class for a report section
│   ├── AnalysisPass/
│   │   ├── AnalysisPassInterface.php
│   │   ├── OverviewPass.php        # Summary + type breakdown
│   │   ├── TopConsumersPass.php    # Top classes, arrays, strings by size
│   │   ├── RetainedSizePass.php    # Tree-based retained size ranking
│   │   ├── CircularRefsPass.php    # Circular reference detection
│   │   ├── AccumulationPass.php    # "Small owner, huge retained" detection
│   │   ├── StructureDedupPass.php  # Same-shape object groups
│   │   ├── CallFramePass.php       # Call stack with per-frame memory
│   │   └── PathResolverPass.php    # 3-hop ancestor for top consumers
│   └── Formatter/
│       ├── FormatterInterface.php
│       ├── TextFormatter.php       # Terminal output with ANSI
│       └── MarkdownFormatter.php   # Markdown for CI/reports
```

### Integration Point

```php
// MemoryOutputFactory.php - add case:
'report' => new ReportMemoryOutput(
    $memory_profiler_settings,
    $region_boundaries,
    new TextFormatter(),
),
```

`ReportMemoryOutput` internally creates a temporary in-memory SQLite database
(or uses the PdoContextTreeSink directly), then runs analysis passes against it.

## Analysis Passes

### Pass 1: Overview (always runs)

```
=== Memory Overview ===
Heap usage:     41.70 MB (of 43.53 MB allocated)
  Chunks:       14.17 MB
  Huge:         27.53 MB
Analyzed:       99.52%
PHP version:    v84
```

Data source: `MemoryAnalysisResult::$summary` — no DB needed.

### Pass 1b: Unaccounted Memory Warning (always runs)

When `heap_memory_analyzed_percentage` is below a threshold (e.g., 95%),
emit a warning with the gap quantified.

```
=== ⚠ Unaccounted Memory: 23.3 MB (14.3%) ===
  reli-prof tracked 85.7% of ZendMM heap allocations.
  The remaining 14.3% is allocated via ZendMM but does not match
  any known PHP VM structure (zval, string, array, object, op_array, etc.).

  ZendMM allocation overhead (bucket alignment): 4.8 MB
  Remaining unexplained: ~18.5 MB

  This typically indicates:
    - Extension-internal non-object allocations not yet covered by reli-prof
    - Extension-specific emalloc() buffers, caches, or data structures

  Note: reli-prof tracks ALL objects (including extension-defined ones)
  via the object store. The unaccounted portion is non-object allocations
  from extensions that reli does not yet parse — not a fundamental
  limitation, just not yet implemented for the specific extension(s)
  involved.

  To investigate further:
    - Check which extensions are loaded (php -m)
    - Try disabling extensions one by one to isolate the source
    - Use php-memprof for C-level allocation tracking
```

This is informed by the Psalm analysis (psalm#10522) where 25% of heap was
unaccounted — likely extension-level non-object emalloc() allocations.

reli-prof tracks all standard PHP VM structures AND all objects regardless
of origin (via object_store). Extension-internal non-object allocations
are a coverage gap that can be closed by adding extension-specific parsers,
not a fundamental architectural limitation.

Implementation:
```php
$analyzed_pct = $summary['heap_memory_analyzed_percentage'];
$heap_usage = $summary['zend_mm_heap_usage'];
$alloc_overhead = $summary['possible_allocation_overhead_total'];
$unaccounted = $heap_usage * (1 - $analyzed_pct / 100);

if ($analyzed_pct < 95.0) {
    $unexplained = $unaccounted - $alloc_overhead;
    $section->addWarning(sprintf(
        'Unaccounted memory: %.1f MB (%.1f%% of heap). '
        . 'ZendMM alignment overhead explains %.1f MB. '
        . 'Remaining %.1f MB may be from extension-level emalloc() '
        . 'allocations not tracked by reli-prof.',
        $unaccounted / 1024 / 1024,
        100 - $analyzed_pct,
        $alloc_overhead / 1024 / 1024,
        max(0, $unexplained) / 1024 / 1024,
    ));
}
```

### Pass 2: Top Consumers by Type (always runs)

```
=== Memory by Type ===
  ZendString              27.66 MB (66.3%)  ×  2,474
  ZendObject               7.22 MB (17.3%)  × 105,373
  ZendArrayTable           1.69 MB ( 4.0%)  ×  1,594
  ...
```

Data source: `MemoryAnalysisResult::$location_types_summary` — no DB needed.

### Pass 3: Top Consumers by Class (always runs)

```
=== Top Classes ===
  CrossReferenceEntryInUseObject   7,046 KB (95.3% of objects)  × 100,203
  DictionaryEntry                    106 KB ( 1.4%)             ×   1,502
  IntegerValue                        55 KB ( 0.7%)             ×   1,001
  ...
```

Data source: `MemoryAnalysisResult::$class_objects_summary` — no DB needed.

### Pass 4: Top Arrays with Owners (needs context tree)

```
=== Largest Arrays ===
  2.62 MB  65,500 elements  class_table → Font → static_properties → uchrCache
  1.05 MB  65,500 elements  ... → fonts → F1 → ... → table
  1.05 MB  65,500 elements  ... → fonts → F2 → ... → table
  ...
```

Implementation: Uses in-memory SQLite + `v_arrays` + 3-hop ancestor JOIN.

```php
// In TopArraysPass::analyze()
$stmt = $db->query("
    SELECT
        a.total_size, a.element_count,
        e1.link_name as l1, e2.link_name as l2,
        e3.link_name as l3, e4.link_name as l4
    FROM v_arrays a
    LEFT JOIN context_edges e1 ON e1.child_node_id = a.node_id AND e1.is_tree = 1
    LEFT JOIN context_edges e2 ON e2.child_node_id = e1.parent_node_id AND e2.is_tree = 1
    LEFT JOIN context_edges e3 ON e3.child_node_id = e2.parent_node_id AND e3.is_tree = 1
    LEFT JOIN context_edges e4 ON e4.child_node_id = e3.parent_node_id AND e4.is_tree = 1
    WHERE a.run_id = :run_id
    ORDER BY a.total_size DESC LIMIT 10
");
```

### Pass 5: Top Strings with Owners (needs context tree)

```
=== Largest Strings ===
  210 KB  "From: sender@example.com\r\nTo: re..."
          ... → structure → object_properties → raw
  210 KB  "--boundary_1de25091e6137b81\r\nCont..."
          ... → messages[0] → structure → raw
  ...
```

### Pass 6: Circular Reference Detection (needs context tree)

```
=== Circular References ===
  ⚠ Message → attachments → items[0] → oMessage → ↻ Message  (×603 instances)
  ⚠ Item → feed → data → items → ↻ Item  (×500 instances)
```

Implementation: Query `context_edges WHERE is_tree = 0`, group by link_name pattern.

```php
$stmt = $db->query("
    SELECT
        e.link_name,
        COUNT(*) as count,
        substr(pnp.path, 1, 80) as from_path
    FROM context_edges e
    JOIN v_node_paths pnp ON pnp.node_id = e.parent_node_id
    WHERE e.is_tree = 0
    GROUP BY e.link_name
    HAVING count > 10
    ORDER BY count DESC LIMIT 10
");
```

Note: `v_node_paths` may be too heavy for large targets. Use 3-hop fallback:

```php
// Fallback for large targets: skip v_node_paths, just show link_name + count
$stmt = $db->query("
    SELECT e.link_name, COUNT(*) as count
    FROM context_edges e
    WHERE e.is_tree = 0 AND e.run_id = :run_id
    GROUP BY e.link_name
    HAVING count > 10
    ORDER BY count DESC LIMIT 10
");
```

### Pass 7: Accumulation Point Detection (heuristic)

Finds "small owner with disproportionately many children of the same type."

```
=== Accumulation Points ===
  ⚠ Cell (200,020 instances) always paired with IgnoredErrors (200,020 instances)
    → IgnoredErrors accounts for 23,440 KB — consider lazy initialization
  ⚠ Every AST Node creates a DotAccessData companion (246,007 instances, 13,454 KB)
```

Heuristic:
```sql
-- Find class pairs where count is identical or near-identical
WITH class_counts AS (
    SELECT class_name, count, memory_usage
    FROM class_objects_summary
    WHERE run_id = :run_id AND count > 100
)
SELECT
    a.class_name AS owner_class,
    b.class_name AS companion_class,
    a.count AS owner_count,
    b.count AS companion_count,
    b.memory_usage AS companion_memory
FROM class_counts a
JOIN class_counts b ON abs(a.count - b.count) < a.count * 0.05  -- within 5%
    AND a.class_name != b.class_name
    AND a.memory_usage > b.memory_usage  -- owner is "bigger"
ORDER BY b.memory_usage DESC
LIMIT 5;
```

### Pass 7b: Dynamic Properties Detection (needs context tree)

Detects the "Psalm pattern": classes with unnecessary dynamic property tables.
reli-prof explicitly tracks `dynamic_properties` as a named child of ObjectContext
(MemoryLocationsCollector.php L1009-1023). When `$object->properties` is non-null
and not an enum, the HashTable is collected and linked.

```
=== ⚠ Unnecessary Dynamic Property Tables ===
  Monolog\JsonSerializableDateTimeImmutable: 93,315 objects with dynamic_properties
    Total overhead: 5,103 KB
    This class extends a C extension class (DateTimeImmutable).
    The dynamic property table may be avoidable.
```

Query:
```sql
SELECT
    cnl.class_name,
    count(*) as objects_with_dynprops,
    round(sum(dyn_loc.size) / 1024.0, 2) as dynprops_total_kb
FROM context_edges e_dyn
JOIN context_edges e_obj
    ON e_obj.parent_node_id = e_dyn.parent_node_id
    AND e_obj.link_name = 'object_properties' AND e_obj.is_tree = 1
JOIN context_node_locations cnl
    ON cnl.node_id = e_dyn.parent_node_id
    AND cnl.location_type = 'ZendObjectMemoryLocation'
LEFT JOIN context_node_locations dyn_loc
    ON dyn_loc.node_id = e_dyn.child_node_id
    AND dyn_loc.location_type IN ('ZendArrayMemoryLocation', 'ZendArrayTableMemoryLocation')
WHERE e_dyn.link_name = 'dynamic_properties' AND e_dyn.is_tree = 1
GROUP BY cnl.class_name
HAVING count(*) > 100
ORDER BY count(*) DESC;
```

This was validated against the Monolog dataset where 93,315
JsonSerializableDateTimeImmutable objects each carry a dynamic property table
(5.1 MB total). The class extends C-extension DateTimeImmutable and adds one
PHP property, which triggers the dynamic property table creation.

Cause determination is harder — possible reasons include:
- PHP class extending a C extension class (ext properties layout mismatch)
- unserialize() without __unserialize() on the class
- Explicit dynamic property assignment at runtime

The report flags the anomaly; the user investigates the specific cause.

### Pass 7c: Per-Property Memory Contribution (needs context tree)

link_name frequency × child node size = per-property memory cost across all instances.
Filters out structural noise (value/key/name/array_elements/object_properties/etc.)
to surface domain-specific properties that dominate memory.

```
=== Per-Property Memory Contribution ===
  message           100,206 × 121 B =  11.53 MB  (LogRecord::$message strings)
  datetime          100,004 ×  56 B =   5.34 MB  (DateTimeImmutable objects)
  context           100,024 ×  56 B =   5.34 MB  (context array headers)
  dynamic_properties 93,316 × 56 B =   4.98 MB  ⚠ (unnecessary HashTables)
```

Query:
```sql
SELECT
    e.link_name,
    count(*) as occurrences,
    round(sum(coalesce(cnl.size, 0)) / 1024.0 / 1024.0, 2) as total_mb,
    round(avg(coalesce(cnl.size, 0)), 0) as avg_bytes
FROM context_edges e
LEFT JOIN context_node_locations cnl ON cnl.node_id = e.child_node_id
WHERE e.is_tree = 1
    AND e.link_name NOT IN (
        'value', 'key', 'name', 'array_elements', 'object_properties',
        'possible_unused_area', 'object_handlers', 'referenced', 'closure'
    )
    AND e.link_name NOT GLOB '[0-9]*'
GROUP BY e.link_name
HAVING count(*) > (SELECT max(count) / 2 FROM class_objects_summary)
ORDER BY sum(coalesce(cnl.size, 0)) DESC
LIMIT 15;
```

The `HAVING count(*) > max_class_count / 2` heuristic ensures we only show
properties that exist on most instances of the dominant class, filtering out
rare/structural names without a hardcoded exclusion list.

This pass surfaces:
- Which properties cost the most memory in aggregate
- `dynamic_properties` overhead (ties into Pass 7b)
- Scalar vs object vs array properties (avg_bytes = 0 means inline zval)

### Pass 8: Retained Size Estimate (needs context tree, optional)

```
=== Top Retained Size (tree-based estimate) ===
  $crossReferenceSections (local var, frame 1)
    Retained: ~41.70 MB  Shared refs: 0  → HIGH CONFIDENCE
  Font::$uchrCache (static property)
    Retained: ~2.62 MB   Shared refs: 0  → HIGH CONFIDENCE
  Message#1 → structure → raw
    Retained: ~0.21 MB   Shared refs: 3  → LOW CONFIDENCE (shared)
```

This is expensive for large targets. Skip if node count > threshold (e.g., 100K).

### Pass 9: Call Stack Context (needs context tree, optional)

```
=== Call Stack ===
  Frame 0: {main}                    at parse_with_memory_issue.php:67
  Frame 1: PdfParser::parseFile()    at PdfParser.php:42
    Local variables using: 38.2 MB
      $document: 35.1 MB
      $parser:    3.1 MB
```

### Pass 10: Recommendations (heuristic synthesis)

```
=== Recommendations ===
  1. [HIGH] 100,203 CrossReferenceEntryInUseObject instances consume 7,046 KB
     Consider adding a limit to the PREV chain loop in CrossReferenceSourceParser
  2. [MEDIUM] 603 circular references via oMessage (Message ↔ Attachment)
     Consider using WeakReference or breaking the cycle in __destruct()
  3. [LOW] 1,402 identical Closures for emptyData option
     Consider replacing with a static string default
```

Heuristic rules:
- HIGH: Single class > 50% of object memory, or retained size > 30% of heap
- MEDIUM: Circular references with > 100 instances, or companion objects > 10% of heap
- LOW: Identical structure groups > 1000 instances, or Closure accumulation > 500

## Output Format Selection

```
--output-format=report          # Full report to stdout (default if no -o)
--output-format=report-md       # Markdown format
--output-format=report-json     # Structured JSON (for CI integration)
--output-format=json            # Current full JSON (renamed from default)
--output-format=sqlite3         # Current SQLite
```

## Adaptive Complexity

The report adapts to target size:

| Target Size | Passes Run | Strategy |
|---|---|---|
| < 10K objects | All 10 passes | Full analysis including v_node_paths |
| 10K - 100K objects | Passes 1-7, 9 | Skip retained size, use 3-hop paths |
| > 100K objects | Passes 1-3, 6-7 | Summary + heuristics only, skip context tree |

For the "summary only" case (> 100K objects or `--report-level=summary`),
no SQLite database is created at all — analysis uses only the pre-computed
`MemoryAnalysisResult` (summary, types, classes).

## Implementation Plan

### Phase 1: Summary Report (no context tree needed)
- Passes 1-3 + Pass 7 (accumulation heuristic) + Pass 10 (recommendations)
- Uses only `MemoryAnalysisResult` data
- **Zero additional memory overhead**
- Estimated: 2-3 days

### Phase 2: Context-Aware Report (uses in-memory SQLite)
- Passes 4-6, 8-9
- Creates temporary in-memory SQLite, runs PdoContextTreeSink
- Falls back to Phase 1 output if target is too large
- Estimated: 1-2 weeks

### Phase 3: Retained Size and Frontier Analysis
- Full retained size computation (tree-based + sharedness)
- Incoming frontier for shared subgraphs
- Estimated: 2-3 weeks

## Example Full Output

```
======================================================================
 reli-prof Memory Analysis Report
 Target: PID 12345 (PHP 8.4 NTS)
 Captured: 2026-03-28 12:00:00
======================================================================

=== Memory Overview ===
  Heap usage:     41.70 MB (of 43.53 MB allocated)
    Chunks:       14.17 MB
    Huge:         27.53 MB
  VM Stack:        0.00 MB
  Analyzed:       99.52%

=== Memory by Type ===
  ZendString              27.66 MB (66.3%)  ×  2,474
  ZendObject               7.22 MB (17.3%)  × 105,373
  ZendArrayTable           1.69 MB ( 4.0%)  ×  1,594
  ObjectsStore             1.00 MB ( 2.4%)  ×      1
  ZendArrayOverhead        0.56 MB ( 1.4%)  ×  1,564
  ZendOpArrayBody          0.28 MB ( 0.7%)  ×    138

=== Top Classes ===
  CrossReferenceEntryInUseObject   7,046 KB  × 100,203  (95.3%)
  DictionaryEntry                    106 KB  ×   1,502  ( 1.4%)
  IntegerValue                        55 KB  ×   1,001  ( 0.7%)
  CrossReferenceSubSection            43 KB  ×     501  ( 0.6%)
  DictionaryKey                       42 KB  ×     603  ( 0.6%)

=== Largest Arrays ===
  2.62 MB  65,500 elems  Font → static_properties → uchrCache
  1.05 MB  65,500 elems  fonts → F1 → table
  1.05 MB  65,500 elems  fonts → F2 → table

=== Largest Strings ===
  210 KB  "From: sender@example.com..."  structure → raw
  210 KB  "--boundary_1de250..."          messages[0] → structure → raw

=== Circular References ===
  ⚠ oMessage: 603 back-references (Message ↔ Attachment cycle)

=== Accumulation Points ===
  ⚠ Cell (200,020) always paired with IgnoredErrors (200,020) — 23,440 KB
    Consider lazy initialization of IgnoredErrors

=== Recommendations ===
  1. [HIGH] CrossReferenceEntryInUseObject: 100,203 instances, 7,046 KB
     Single class consuming 95.3% of all object memory.
     Likely cause: unbounded accumulation in a loop.

  2. [MEDIUM] 603 circular references via oMessage
     Message ↔ Attachment cycle prevents GC without explicit cleanup.
     Consider WeakReference or __destruct() cleanup.

  3. [LOW] 1,402 identical emptyData Closures
     Replaceable with static string default.

======================================================================
```

## Non-Tree Edge Analysis (Shared Reference Diagnostics)

### Background

Non-tree edges (`is_tree=0` in `context_edges`) represent shared references —
cases where the same value/object is reachable from multiple paths in the graph.
Investigation across 17 SQLite datasets shows non-tree edges account for **30-44%**
of all edges, but the majority is noise.

### Edge Category Breakdown (observed across datasets)

| Category | % of non-tree edges | Signal? | Filter |
|---|---|---|---|
| `object_handlers` | ~14% | Noise | Every object shares a handler table. Always filter out. |
| `name`/`key`/`value` | ~54% | Noise | Interned strings shared across definitions. Filter out. |
| **Property-level shared refs** | **~12%** | **Signal** | Same object referenced by multiple property paths |
| Other (local vars, objects_store) | ~20% | Mixed | Keep for advanced analysis |

### Three Patterns in Property-Level Shared Refs

After filtering noise, remaining non-tree property edges fall into 3 clear patterns:

**Pattern 1: SINGLETON** (1 target, many referrers)
```
refs_per_target >>> 1, distinct_targets = 1
```
Examples: `formFactory` (3,611 refs → 1 target), `method`, `action`, `required`
Meaning: One shared instance used everywhere. **Normal — not a problem.**

**Pattern 2: FAN-IN / CIRCULAR** (few targets, many refs each)
```
refs_per_target > 2, distinct_targets < total_refs / 2
```
Examples: `oMessage` (603 refs → 201 targets, 3 refs each), `parent` (tree nav)
Meaning: Multiple objects point to the same target — cycles or shared ownership.
**Potentially problematic — investigate for cycles and GC prevention.**

**Pattern 3: SCATTERED** (many targets, ~1 ref each)
```
refs_per_target ≈ 1.0, distinct_targets ≈ total_refs
```
Examples: `options` (1,809 copies), `dispatcher` (1,801 copies, ALL SAME SIZE)
Meaning: Every object has its own copy of the same thing.
**Sharing opportunity — if ALL SAME SIZE, these could be deduplicated.**

### Implementation: Non-Tree Edge Analysis Pass

```php
// New analysis pass for the report
class SharedRefAnalysisPass implements AnalysisPassInterface
{
    public function analyze(PDO $db, int $runId): ReportSection
    {
        // Step 1: Filter noise (object_handlers, name/key/value)
        // Step 2: Classify remaining into SINGLETON / FAN-IN / SCATTERED
        // Step 3: For SCATTERED with ALL SAME SIZE → flag as dedup candidate
        // Step 4: For FAN-IN → check if cycle (target is ancestor of referrer)
    }
}
```

Query for classification:

```sql
SELECT
    e.link_name,
    count(*) as total_refs,
    count(DISTINCT e.child_node_id) as distinct_targets,
    round(cast(count(*) as real) / count(DISTINCT e.child_node_id), 1)
        as refs_per_target,
    CASE
        WHEN count(DISTINCT e.child_node_id) = 1 THEN 'SINGLETON'
        WHEN cast(count(*) as real) / count(DISTINCT e.child_node_id) > 2.0
            THEN 'FAN-IN'
        ELSE 'SCATTERED'
    END as pattern
FROM context_edges e
JOIN context_nodes cn ON cn.node_id = e.parent_node_id
WHERE e.is_tree = 0
    AND cn.type = 'ObjectPropertiesContext'
    AND e.link_name NOT IN ('name', 'key', 'value', 'object_handlers')
GROUP BY e.link_name
HAVING count(*) > 50
ORDER BY total_refs DESC;
```

Query for SCATTERED dedup candidates (waste quantification):

```sql
SELECT
    e.link_name,
    count(DISTINCT e.child_node_id) as copies,
    round(sum(cnl.size) / 1024.0, 2) as total_kb,
    round(avg(cnl.size), 0) as avg_size,
    CASE
        WHEN count(DISTINCT cnl.size) = 1 THEN 'ALL SAME SIZE → dedup candidate'
        ELSE count(DISTINCT cnl.size) || ' different sizes'
    END as recommendation
FROM context_edges e
JOIN context_nodes cn ON cn.node_id = e.parent_node_id
LEFT JOIN context_node_locations cnl ON cnl.node_id = e.child_node_id
WHERE e.is_tree = 0
    AND cn.type = 'ObjectPropertiesContext'
    AND e.link_name NOT IN ('name', 'key', 'value', 'object_handlers')
GROUP BY e.link_name
HAVING count(DISTINCT e.child_node_id) > 100
    AND cast(count(*) as real) / count(DISTINCT e.child_node_id) < 2.0
ORDER BY sum(cnl.size) DESC;
```

### Example Report Output

```
=== Shared Reference Analysis ===
  Property-level shared refs: 7,636 (filtered from 62,787 total non-tree edges)

  Singletons (normal sharing):
    formFactory:     3,611 refs → 1 shared instance       [OK]
    method:          3,611 refs → 1 shared instance       [OK]
    required:        1,806 refs → 1 shared instance       [OK]

  Fan-in / Cycles:
    ⚠ oMessage:      603 refs → 201 targets (3.0 refs each) — circular ref
    ⚠ parent:        246K refs → 118K targets (2.1 refs each) — tree navigation

  Dedup Candidates:
    ⚠ dispatcher:    1,801 copies × 88 bytes (ALL SAME SIZE) = 155 KB wasted
    ⚠ options:       1,809 copies × 56 bytes (ALL SAME SIZE) =  99 KB wasted
    ⚠ emptyData:     1,402 copies (2 sizes)                  = 491 KB wasted
```

### Retained Size Adjustment Using Non-Tree Edges

For the retained size estimate (Pass 8), non-tree edge patterns directly affect
confidence:

| Pattern | retained size confidence |
|---|---|
| No non-tree incoming | HIGH — cutting owner frees the target |
| SINGLETON incoming | HIGH — the singleton is shared, but target belongs to tree parent |
| FAN-IN incoming | LOW — target has multiple owners, cutting one doesn't free it |
| SCATTERED (is target) | N/A — target is the one with non-tree outgoing |

```
adjusted_confidence = CASE
    WHEN non_tree_incoming = 0 THEN 'HIGH'
    WHEN non_tree_incoming = 1 AND pattern = 'SINGLETON' THEN 'HIGH'
    WHEN non_tree_incoming > 0 AND pattern = 'FAN-IN' THEN 'LOW'
    ELSE 'MEDIUM'
END
```



```
inspector:memory -p PID                          # report format (default)
inspector:memory -p PID -f report                # explicit report
inspector:memory -p PID -f report-md             # markdown
inspector:memory -p PID -f report --report-level=summary  # no context tree
inspector:memory -p PID -f report --top-n=20     # top 20 instead of 10
inspector:memory -p PID -f json                  # legacy full JSON
inspector:memory -p PID -f sqlite3 -o out.db     # legacy SQLite
```

## Preset Query Subcommand (Alternative / Complementary)

For users who already have a SQLite file:

```
inspector:memory:query --db=out.sqlite3 --query=top-arrays
inspector:memory:query --db=out.sqlite3 --query=top-strings
inspector:memory:query --db=out.sqlite3 --query=circular-refs
inspector:memory:query --db=out.sqlite3 --query=accumulators
inspector:memory:query --db=out.sqlite3 --query=call-stack
inspector:memory:query --db=out.sqlite3 --query=retained-size
inspector:memory:query --db=out.sqlite3 --query=all  # full report
```

Implementation: Opens existing SQLite, runs the same Pass queries.
Much simpler than the full report — just SQL + formatting.

## Automatic Bottleneck Drill-Down (Tree-Based)

### Concept

Instead of listing "what's big" and letting humans interpret, automatically
follow the heaviest branch from root to leaf, showing the dominator path:

```
→ call_frames                                141.23 MB
  → frame 1                                  141.23 MB
    → local_variables                         141.20 MB
      → $ast                                   89.24 MB   ← 63% of heap
        → parser                               50.76 MB
```

This replaces the manual process of: "class ranking → which class is big →
where is it held → trace from root → find the dominator path."

### Implementation: PHP Post-Order DFS (not SQL)

Recursive SQL CTEs are too slow for large graphs (200K+ edges). Instead,
load the graph into PHP and run iterative post-order DFS.

**Algorithm:**
1. Load `context_node_locations` grouped by node_id → `$node_sizes[]`
2. Load `context_edges WHERE is_tree=1` → `$children[]` adjacency list
3. Iterative post-order DFS to compute `$subtree_sizes[]`
4. Greedy drill-down: at each level, pick the heaviest child, show top 3

### Scaling to Multi-GB Targets (PHPStan 4GB scenario)

Edge density varies by target: 800-38,000 tree edges per MB of heap.
A 4GB PHPStan process could have 3-150M edges.

**PHP array approach does NOT scale beyond ~500 MB targets:**

| Target | Edges (est.) | PHP array memory | FFI CSR memory |
|---|---|---|---|
| 160 MB | 6M | 2.2 GB | ~50 MB |
| 1 GB | 25M | ~9 GB | ~120 MB |
| 4 GB | 80M | ~30 GB ❌ | **~350 MB** ✅ |
| 10 GB | 200M | ~75 GB ❌ | **~900 MB** ✅ |

**FFI CSR (Compressed Sparse Row)** stores the graph as:
- `offsets[n_nodes]`: FFI int32 — byte offset into edges[] per node
- `edges[n_edges]`: FFI int32 — flat child node IDs
- `node_sizes[n_nodes]`: FFI int64 — shallow size per node
- `subtree_sizes[n_nodes]`: FFI int64 — computed via post-order DFS

Measured: FFI int32[6M] = 23 MB, sequential read 0.12s, 1M random reads 0.16s.
reli-prof already uses FFI extensively, so this is a natural fit.

Time for 4GB target (80M edges): edge loading ~3 min + DFS ~30 sec = **~4 min**.

Implementation: auto-switch to FFI CSR when edge count exceeds threshold (e.g., 10M).
Same DFS algorithm, different backing data structure.

**Measured Performance (current PHP array approach):**

| Dataset | Edges | Load edges | DFS compute | Total | PHP Memory |
|---|---|---|---|---|---|
| Eloquent | 1.0M | 3.9s | 0.6s | **5s** | 288 MB |
| CommonMark | 2.5M | 7.0s | 1.6s | **10s** | 1.2 GB |
| PHP-Parser | 6.1M | 48.7s | 8.3s | **60s** | 3.0 GB |
| Monolog | 4.5M | ~30s (est) | ~5s (est) | **~35s** | ~2 GB (est) |

DFS itself is fast (O(edges)). The bottleneck is edge loading from SQLite.
For very large datasets (10M+ edges), edge loading could be optimized by:
- Binary dump format instead of SQLite row iteration
- Memory-mapped file
- Streaming edge reader with fixed-size records

**For typical targets (< 2M edges): under 10 seconds.**
**For large targets (2-10M edges): 10-60 seconds.**
**For very large targets (10M+): minutes, but still feasible offline.**

### Example Output (Eloquent, automatic)

```
=== Bottleneck Drill-Down ===
→ class_table                                 18.86 MB (44%)
  call_frames                                 16.59 MB (39%)
  interned_strings                             2.09 MB ( 5%)
  ... +5 more branches
    → ComposerAutoloader → static_properties → loader
      → classMap → array_elements (1.46 MB, 6547 entries)
```

```
=== Bottleneck Drill-Down (from call_frames) ===
→ call_frames → frame 1 → local_variables    16.56 MB
  → $users (Collection)                       16.50 MB
    → items[] (10,000 User objects)           16.45 MB
      → User[0] → $attributes (array, 7 keys)
      → User[0] → $original (array, 7 keys)
      → User[0] → $casts (empty array, 56B overhead)
```

### Integration with Report Feature

This becomes **Pass 11: Automatic Bottleneck Drill-Down**.

- For `--output-format=report`: runs the PHP DFS and prints the tree
- For `inspector:memory:query --db=foo.sqlite3 --query=drill-down`: same logic
- Adaptive: if edges > 5M, warn about memory/time and offer `--max-depth`

### Phase 2: Multi-Root Drill-Down

Instead of always starting from the single heaviest root branch, start from
multiple entry points:

1. **Heaviest root branch** (call_frames vs class_table vs objects_store)
2. **Heaviest class** (drill from objects_store → class → instances)
3. **Heaviest array** (drill from the largest v_arrays entry upward)

This gives 3 perspectives on the same data, catching cases where the heaviest
### Phase 3: SCC (Strongly Connected Components) — Analysis Substrate

SCC is not just a "cycle detection pass" — it produces a **reusable intermediate
representation** that multiple subsequent analyses can build on. Computed once
via Tarjan's O(V+E), the results are stored in DB and referenced by other passes.

**What depends on SCC results:**

| Pass | How it uses SCC |
|---|---|
| Retained size | SCC count = 0 → exact on DAG. Otherwise collapse cycles to super-nodes. |
| Circular ref reporting | SCC replaces the simple `is_tree=0` count with structured cycle profiles. |
| Blame allocation | SCC members are grouped — blame the SCC's external entry points, not internals. |
| Choke point | Gateway SCCs (small but high ext_out) are a specific choke point pattern. |
| Drill-down | Can skip into/through SCCs in the condensed DAG. |

**What SCC reveals (validated on real data):**

| Dataset | SCCs | Insight |
|---|---|---|
| php-imap | 201 × 15 nodes | 201 identical Message↔Attachment cycles |
| SimplePie | 1 × 2,011 nodes | All Items form one giant cycle |
| Symfony Forms | 1,803 SCCs | OptionsResolver↔Closure cycles × 1,800 |
| Monolog | 0 | No cycles — retained size fully reliable |

**Per-SCC metrics:**
- Node count, total shallow size
- External incoming/outgoing edge counts
- Dominant class name + ratio (count-based and size-based)
- Class composition signature (for pattern grouping)

**Dominant class ratio** indicates structural homogeneity:

| Ratio | Interpretation |
|---|---|
| > 90% | Homogeneous — single data structure repeated at scale |
| 60-90% | One main class with helpers |
| 30-60% | Composite structure |
| < 30% | Mixed graph — no single class explanation |

Two variants: `dominant_class_ratio_by_count` and `dominant_class_ratio_by_size`.
When they diverge (e.g., count=95% but size=40%), the dominant class is numerous
but lightweight, while a minority class holds the actual bytes.

**Report output example:**
```
=== Cycle Analysis (SCC) ===
  201 identical cycles detected (Message ↔ Attachment pattern):
    Each: 1 Message + 3 Attachments + 1 AttachmentCollection
    Each: 0.85 KB shallow, 35 outgoing refs
    Dominant class ratio: 99.8% (count), 99.4% (size) — homogeneous
    External entry points: 1 per cycle (from $messages array)
    → Breaking oMessage back-reference would eliminate all 201 cycles

  1,800 OptionsResolver ↔ Closure micro-cycles:
    Each: 1 Closure + 1 OptionsResolver
    Dominant class ratio: 50% — balanced pair
    → Replace Closure defaults with static values to break cycles

  Graph is a DAG after SCC condensation → retained size is EXACT on condensed graph.
```

**Performance (measured):**

| Dataset | Edges | SCC Time | Memory |
|---|---|---|---|
| php-imap | 200K | 0.15s | 111 MB |
| Symfony Forms | 1.1M | 0.75s | 516 MB |
| Monolog | 4.5M | 2.88s | 2.1 GB |

Tarjan's is O(V+E), same as DFS. Shares loaded edge data with drill-down.

**Design principle:** SCC computation happens in PHP (not SQL — graph algorithms
don't fit recursive CTEs well). Results are flushed to DB tables, then subsequent
passes query them via SQL. This keeps the boundary clean:
- **reli PHP side**: graph algorithms (Tarjan, DFS, subtree sums)
- **DB side**: storage, filtering, ranking, JOINs, preset queries

**DB schema (v1 — core tables):**

```sql
CREATE TABLE scc_components (
    run_id              INTEGER NOT NULL,
    scc_id              INTEGER NOT NULL,
    node_count          INTEGER NOT NULL,
    total_shallow_size  INTEGER NOT NULL,
    internal_edge_count INTEGER NOT NULL,
    ext_incoming_edges  INTEGER NOT NULL,
    ext_outgoing_edges  INTEGER NOT NULL,
    dominant_class_name TEXT,
    dominant_class_ratio_by_count REAL,
    dominant_class_ratio_by_size  REAL,
    PRIMARY KEY (run_id, scc_id)
);

CREATE TABLE scc_members (
    run_id   INTEGER NOT NULL,
    scc_id   INTEGER NOT NULL,
    node_id  INTEGER NOT NULL,
    PRIMARY KEY (run_id, node_id)
);
CREATE INDEX idx_scc_members_scc ON scc_members(run_id, scc_id);

CREATE TABLE scc_class_summary (
    run_id      INTEGER NOT NULL,
    scc_id      INTEGER NOT NULL,
    class_name  TEXT NOT NULL,
    count       INTEGER NOT NULL,
    total_size  INTEGER NOT NULL
);
CREATE INDEX idx_scc_class ON scc_class_summary(run_id, scc_id);
```

**DB schema (v2 — enrichment):**

```sql
-- Condensed DAG edges between SCCs (and singleton pseudo-SCCs)
CREATE TABLE scc_edges (
    run_id       INTEGER NOT NULL,
    from_scc_id  INTEGER NOT NULL,
    to_scc_id    INTEGER NOT NULL,
    edge_count   INTEGER NOT NULL,
    PRIMARY KEY (run_id, from_scc_id, to_scc_id)
);

-- Representative entry paths into each SCC
CREATE TABLE scc_entry_paths (
    run_id              INTEGER NOT NULL,
    scc_id              INTEGER NOT NULL,
    rank                INTEGER NOT NULL,
    representative_path TEXT NOT NULL,
    from_root_kind      TEXT,  -- 'call_frames', 'class_table', 'objects_store', etc.
    PRIMARY KEY (run_id, scc_id, rank)
);

-- Downstream reachable size from each SCC in condensed DAG
ALTER TABLE scc_components ADD COLUMN downstream_reachable_size INTEGER;
```

Data volume is tiny: php-imap 201 SCCs = 3,015 member rows + 201 component rows.
Even Symfony Forms with 1,803 SCCs = ~22K member rows. Negligible vs millions of edges.

**Useful queries on the SCC tables:**

```sql
-- Is this node in a cycle?
SELECT scc_id FROM scc_members WHERE node_id = :id;

-- Largest cycles
SELECT * FROM scc_components ORDER BY total_shallow_size DESC LIMIT 10;

-- Homogeneous vs mixed SCCs
SELECT scc_id, node_count, dominant_class_name, dominant_class_ratio_by_count,
    CASE WHEN dominant_class_ratio_by_count > 0.9 THEN 'homogeneous'
         WHEN dominant_class_ratio_by_count > 0.6 THEN 'dominated'
         ELSE 'mixed' END as structure_type
FROM scc_components ORDER BY total_shallow_size DESC;

-- Group identical cycle patterns (php-imap: "201× Message:1,Attachment:3")
SELECT composition, count(*) as pattern_count,
       sum(total_shallow_size) as total_bytes
FROM (
    SELECT scc_id,
        group_concat(class_name || ':' || count, ', ') as composition
    FROM scc_class_summary GROUP BY run_id, scc_id
)
GROUP BY composition ORDER BY total_bytes DESC;

-- Retained size confidence
SELECT CASE WHEN count(*) = 0 THEN 'EXACT (DAG)' ELSE 'APPROXIMATE (cycles exist)' END
FROM scc_components WHERE run_id = :run_id;

-- Single-owner cycle clusters (most actionable)
SELECT * FROM scc_components
WHERE ext_incoming_edges <= 2
ORDER BY total_shallow_size DESC LIMIT 10;
```
