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

## CLI Options

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
