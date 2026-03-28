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
emit a warning with possible causes.

```
=== ⚠ Unaccounted Memory: 23.3 MB (14.3%) ===
  Possible causes:
    - ZendMM allocation overhead: 4.8 MB (reported in summary)
    - Dynamic property tables from unserialize()
    - Internal engine structures not tracked by reli-prof

  Heuristic: if object_count > 50K and unaccounted > 10%,
  suggest checking __unserialize() definitions on hot classes.
```

This is informed by the Psalm analysis (psalm#10522) where 25% of heap was
unaccounted due to dynamic property table overhead from unserialize().
The report cannot determine the exact cause, but can flag the anomaly and
suggest likely explanations based on the object count and overhead ratio.

Implementation:
```php
$analyzed_pct = $summary['heap_memory_analyzed_percentage'];
$heap_usage = $summary['zend_mm_heap_usage'];
$alloc_overhead = $summary['possible_allocation_overhead_total'];
$unaccounted = $heap_usage * (1 - $analyzed_pct / 100);

if ($analyzed_pct < 95.0) {
    $object_count = array_sum(array_column($class_objects_summary, 'count'));
    $section->addWarning(sprintf(
        'Unaccounted memory: %.1f MB (%.1f%%). '
        . '%d objects exist — if many were created via unserialize(), '
        . 'dynamic property tables may be the cause. '
        . 'Consider defining __unserialize() on frequently-instantiated classes.',
        $unaccounted / 1024 / 1024,
        100 - $analyzed_pct,
        $object_count,
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
