# Memory Analysis Report Architecture

## Overview

`inspector:memory:report` analyzes a memory snapshot SQLite database
and produces structured findings. Not a dump of tables and numbers,
but a prioritized list of actionable conclusions derived from the data.

For user-facing documentation, see [memory-report.md](../memory-report.md).

## Two Layers

### Computation layer (internal): Passes

Passes are internal computation steps. Each pass reads from the SQLite DB
or shared in-memory structures and produces intermediate results.

### Output layer (external): Findings

Findings are the unit of output. Each finding has:

```
kind:               Identifier (e.g., "dominant_class", "cycle_cluster")
severity:           high | medium | low | info | warning
confidence:         high | medium | low
summary:            One-line human description
facts:              Observed data points (array<string, mixed>)
hypothesis:         What this likely means
next_checks:        What to investigate further
impact_bytes:       Estimated recoverable memory
evidence_node_ids:  Representative node IDs for drill-down
representative_paths: Owner paths (3-hop or drill-down)
replay_query:       SQL to reproduce this finding
```

Implementation: `Finding.php`, `FindingSeverity.php`, `FindingConfidence.php`.

## Data Flow

```
MemoryReportCommand::execute()
  ├── --memory-limit → ini_set()
  ├── PDO("sqlite:snapshot.db")
  └── ReportGenerator::generateFromDb($db, $run_id, $full_analysis)
        ├── loadMeta() → node_count, edge_count, captured_at
        ├── Phase 1: Summary passes (always)
        │     ├── OverviewPass($summary)
        │     ├── TypeBreakdownPass($location_types)
        │     ├── ClassRankingPass($class_objects)
        │     └── CompanionDetectionPass($class_objects)
        ├── Phase 2: SQL passes (or --no-full-analysis < 500K nodes)
        │     ├── CallStackPass($db)
        │     ├── DynamicPropertiesPass($db)
        │     └── StructuralDedupPass($db)
        │     ┌─ (deferred to Phase 3 when substrate available):
        │     ├── PropertyScalingPass($db, $class_objects)
        │     ├── TopArraysPass($db)
        │     ├── TopStringsPass($db)
        │     └── NonTreeEdgePass($db)
        ├── Phase 3: Graph passes (or --no-full-analysis < 500K edges)
        │     ├── GraphSubstrate::loadFromDb($db)
        │     │     ├── loadNodeSizes()
        │     │     ├── loadEdges() → children, all_children, all_parents
        │     │     ├── computeSubtreeSizes() [post-order DFS]
        │     │     └── computeScc() [Tarjan's, uses all_children]
        │     ├── CycleClusterPass($substrate)
        │     ├── PropertyScalingPass($db, $class_objects, $substrate) [retained]
        │     ├── PerPropertyMemoryPass($substrate, $db) [class-qualified, O(edges)]
        │     ├── OwnershipPatternPass($substrate, $db) [1:1 ownership]
        │     ├── TopArraysPass($db, $substrate) [retained + full path]
        │     ├── TopStringsPass($db, $substrate) [full path]
        │     ├── NonTreeEdgePass($db, $substrate) [retained dedup]
        │     ├── DrillDownPass($substrate, $db) [PHP syntax path]
        │     ├── ChokePointPass($substrate, $db, $heap_usage) [% severity]
        │     ├── BlameAllocationPass($substrate, $db)
        │     └── RetainedSizeConfidencePass($substrate)
        ├── deduplicateFindings() → suppress redundancies
        ├── sortFindings() → severity + impact_bytes order
        └── ReportResult($meta, $findings)
              └── TextReportFormatter / JsonReportFormatter
```

## Inline Mode (via MemoryOutputFactory)

When used with `inspector:memory -f report`, the pipeline is:

```
MemoryCommand::execute()
  └── MemoryOutputFactory::create(settings)
        └── ReportMemoryOutput
              ├── Write to temp SQLite via PdoMemoryOutput
              ├── ReportGenerator::generateFromDb(temp_db)
              ├── Format output
              └── Delete temp file
```

## GraphSubstrate

Before graph analysis passes run, the substrate loads the full
graph from SQLite into PHP arrays and computes reusable structures:

**Graph load** (from SQLite):
- `$node_sizes[node_id]`: shallow size per node
- `$node_classes[node_id]`: class name per node
- `$children[parent_id]`: int-only adjacency list (tree edges)
- `$all_children[parent_id]`: forward adjacency (all edges, for SCC)
- `$all_parents[child_id]`: reverse adjacency (all edges)
- `$roots[]`: nodes with NULL parent

**Post-order DFS**:
- `$subtree_sizes[node_id]`: tree-based retained size

**Tarjan SCC** (iterative, stack-based, uses `$all_children`):
- `$node_to_scc[node_id]`: SCC membership
- `$scc_profiles[scc_id]`: node count, total size, external in/out edges,
  class composition, single-owner likelihood

All computed once, shared by all Phase 3 passes.

Measured performance:

| Edges | Load | DFS | SCC | Total | Memory |
|---|---|---|---|---|---|
| 200K | 0.2s | 0.1s | 0.2s | **0.5s** | ~130 MB |
| 1M | 1s | 0.6s | 0.8s | **2.4s** | ~300 MB |
| 2.5M | 7s | 1.6s | - | **~10s** | ~1.2 GB |

## Substrate Utilities

### NodeLabeler

Resolves call frame node IDs to `function_name:lineno` labels.
Used by DrillDownPass, ChokePointPass, TopStringsPass, TopArraysPass.

### PathFormatter

Converts raw context tree paths to PHP syntax:
```
call_frames -> 1 -> local_variables -> messages -> array_elements -> 0
  → <main>:28::$messages[0]->structure->raw
```

Structural intermediaries collapsed: `call_frames`, `local_variables`,
`object_properties`, `array_elements`, `value`.

### SizeFormatter

Consistent human-readable byte formatting across all findings:
```
< 1 KB  → "999 B"
< 1 MB  → "15.20 KB"
< 1 GB  → "153.76 MB"
>= 1 GB → "1.23 GB"
```

## Computation Passes

### Phase 1: Summary-Based (always run)

Uses only `summary`, `location_types_summary`, `class_objects_summary`.
Zero additional memory.

| Pass | Source | Emits |
|---|---|---|
| OverviewPass | summary table | `overview`, `coverage_gap` |
| TypeBreakdownPass | location_types_summary | `dominant_type` |
| ClassRankingPass | class_objects_summary | `dominant_class` |
| CompanionDetectionPass | class_objects_summary | `companion_cluster` |

### Phase 2: SQL-Based

SQL queries against context tables. Some passes deferred to Phase 3
when graph substrate is available (for retained sizes and full paths).

| Pass | Source | Emits |
|---|---|---|
| CallStackPass | context_nodes + attributes | `call_stack` |
| DynamicPropertiesPass | context_edges | `dynamic_properties_overhead` |
| StructuralDedupPass | context_node_locations + edges | `structural_duplicate`, `empty_object` |
| PropertyScalingPass* | context_edges + locations | `property_scaling` |
| TopArraysPass* | v_arrays + ancestors | `large_array`, `sparse_array` |
| TopStringsPass* | context_node_locations | `large_string` |
| NonTreeEdgePass* | context_edges (is_tree=0) | `shared_singleton`, `shared_fanin`, `dedup_candidate` |

\* Deferred to Phase 3 when substrate available.

### Phase 3: Graph-Based

In-memory graph traversal using GraphSubstrate.

| Pass | Source | Emits |
|---|---|---|
| CycleClusterPass | SCC profiles | `cycle_cluster`, `micro_cycle`, `di_container_cycle` |
| PropertyScalingPass | substrate + SQL | `property_scaling` (retained) |
| PerPropertyMemoryPass | substrate + link_names | `expensive_property` (class-qualified) |
| OwnershipPatternPass | substrate + link_names | `ownership_pattern` |
| TopArraysPass | v_arrays + substrate | `large_array` (retained), `sparse_array` |
| TopStringsPass | SQL + substrate | `large_string` (full path) |
| NonTreeEdgePass | SQL + substrate | `dedup_candidate` (retained + value comparison) |
| DrillDownPass | subtree_sizes + children | `bottleneck_path` (PHP syntax) |
| ChokePointPass | subtree_sizes + heap_usage | `choke_point` (%-based severity) |
| BlameAllocationPass | node_sizes + edges | `root_blame` |
| RetainedSizeConfidencePass | SCC profiles | `retained_exact`, `retained_approximate` |

## Post-Processing

### deduplicateFindings()

- Limit `shared_fanin` to top 3 when cycles exist
- Suppress `shared_singleton` when `property_scaling` covers it

### sortFindings()

Sort by severity (HIGH → WARNING → MEDIUM → LOW → INFO), then
`impact_bytes` descending within same severity.

## Adaptive Complexity

Default is full analysis (`--full-analysis` on). Use `--no-full-analysis`
to limit for very large snapshots:

| Target size | Phases | Strategy |
|---|---|---|
| Default (full) | All phases | Full analysis with retained sizes |
| --no-full-analysis, < 500K nodes/edges | Phase 1 + 2 + 3 | Same as full |
| --no-full-analysis, >= 500K edges | Phase 1 + 2 | SQL only, no graph |
| --no-full-analysis, >= 500K nodes | Phase 1 only | Summary + heuristics |

## File Layout

```
src/Inspector/Output/MemoryOutput/Report/
├── Finding.php                    # Value object
├── FindingSeverity.php            # Enum: high|medium|low|info|warning
├── FindingConfidence.php          # Enum: high|medium|low
├── ReportGenerator.php            # Orchestrator + post-processing
├── ReportResult.php               # Meta + findings container
├── Formatter/
│   ├── ReportFormatterInterface.php
│   ├── TextReportFormatter.php    # Human-readable text
│   └── JsonReportFormatter.php    # Structured JSON
├── Pass/
│   ├── PassInterface.php
│   ├── OverviewPass.php
│   ├── TypeBreakdownPass.php
│   ├── ClassRankingPass.php
│   ├── CompanionDetectionPass.php
│   ├── CallStackPass.php
│   ├── DynamicPropertiesPass.php
│   ├── PropertyScalingPass.php     # SQL or graph (retained)
│   ├── PerPropertyMemoryPass.php   # Graph-based, class-qualified
│   ├── OwnershipPatternPass.php    # 1:1 ownership detection
│   ├── TopArraysPass.php           # SQL or graph (retained + path)
│   ├── TopStringsPass.php          # SQL or graph (full path)
│   ├── NonTreeEdgePass.php         # SQL or graph (retained dedup)
│   ├── StructuralDedupPass.php
│   ├── CycleClusterPass.php
│   ├── DrillDownPass.php           # PHP syntax path
│   ├── ChokePointPass.php          # %-based severity
│   ├── BlameAllocationPass.php
│   └── RetainedSizeConfidencePass.php
└── Substrate/
    ├── GraphSubstrate.php          # Graph load + DFS + Tarjan SCC
    ├── NodeLabeler.php             # Frame number → function_name:lineno
    ├── PathFormatter.php           # Raw path → PHP syntax
    └── SizeFormatter.php           # Consistent byte formatting

src/Inspector/Output/MemoryOutput/
├── ReportMemoryOutput.php          # MemoryOutputInterface for inline mode

src/Command/Inspector/
├── MemoryReportCommand.php         # inspector:memory:report command
```

## Design Principles

1. **Graph algorithms in PHP, results shared in-memory.**
   SCC/DFS/BFS are computed once in PHP via GraphSubstrate.
   All Phase 3 passes share the same substrate instance.

2. **Retained over shallow.**
   When graph substrate is available, passes use `subtree_sizes`
   for retained cost (arrays, properties, dedup candidates).

3. **Findings over numbers.**
   Don't show "top 10 classes." Show "this class is 95% of objects,
   which is abnormal, and here's the likely cause."

4. **PHP syntax for paths.**
   `<main>:28::$messages[0]->structure->raw` instead of raw
   context tree paths with structural intermediaries.

5. **FQCN for all class names.**
   `App\Models\User` instead of `User`. Unambiguous identification.

6. **Consistent formatting.**
   All sizes via `SizeFormatter::format()`. All findings sorted
   by severity + impact. No ad-hoc formatting patterns.

7. **Confidence on everything.**
   Every finding says how sure it is (`confidence`). Dedup candidates
   report actual value identity percentage for strings.

## Finding Types Catalog

| Kind | Severity | Source Pass | Validated On |
|---|---|---|---|
| `overview` | info | OverviewPass | All |
| `coverage_gap` | warning | OverviewPass | Guzzle (8.6%), Fiber (pre-fix) |
| `call_stack` | info | CallStackPass | All |
| `dominant_type` | high/medium | TypeBreakdownPass | smalot (arrays 86%), php-imap (strings 87%) |
| `dominant_class` | high | ClassRankingPass | PrinsFrank (95.3%), Eloquent (98.2%) |
| `companion_cluster` | medium | CompanionDetectionPass | PhpSpreadsheet, CommonMark, Symfony Forms |
| `property_scaling` | medium | PropertyScalingPass | Eloquent (per-instance vs shared props) |
| `ownership_pattern` | medium | OwnershipPatternPass | CommonMark (DotAccessData 246K) |
| `dynamic_properties_overhead` | medium/low | DynamicPropertiesPass | Monolog (93K DateTimeImmutable) |
| `expensive_property` | medium | PerPropertyMemoryPass | php-imap (Structure::$raw 40 MB) |
| `large_array` | medium/low | TopArraysPass | smalot (uchrCache 2.6 MB), Eloquent (15 MB retained) |
| `sparse_array` | medium | TopArraysPass | Arrays after mass unset() |
| `large_string` | medium/low | TopStringsPass | php-imap (raw 210 KB x 200) |
| `shared_singleton` | info | NonTreeEdgePass | Symfony Forms (formFactory) |
| `shared_fanin` | info | NonTreeEdgePass | php-imap (Attachment::$oMessage) |
| `dedup_candidate` | low/info | NonTreeEdgePass | Symfony Forms (dispatcher 1,801x) |
| `structural_duplicate` | medium/low | StructuralDedupPass | CommonMark (246K empty Data) |
| `empty_object` | medium/low | StructuralDedupPass | CommonMark, Symfony Forms |
| `cycle_cluster` | medium/low | CycleClusterPass | php-imap (201x), Symfony Forms (1,802x) |
| `micro_cycle` | low | CycleClusterPass | Symfony Forms (OptionsResolver ↔ Closure) |
| `di_container_cycle` | info | CycleClusterPass | Laravel (54 classes, 74 KB) |
| `bottleneck_path` | high | DrillDownPass | PHP-Parser (89 MB), CommonMark (71 MB) |
| `choke_point` | high/medium/low | ChokePointPass | Eloquent (Collection → 15 MB) |
| `root_blame` | info | BlameAllocationPass | Eloquent (class_table 48%, call_frames 35%) |
| `retained_exact` | info | RetainedSizeConfidencePass | Monolog (DAG, no cycles) |
| `retained_approximate` | info | RetainedSizeConfidencePass | php-imap (201 cycles) |
