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
  ├── PDO("sqlite:snapshot.db")
  └── ReportGenerator::generateFromDb($db, $run_id)
        ├── loadMeta() → node_count, edge_count
        ├── Phase 1: Summary passes (always)
        │     ├── OverviewPass($summary)
        │     ├── TypeBreakdownPass($location_types)
        │     ├── ClassRankingPass($class_objects)
        │     └── CompanionDetectionPass($class_objects)
        ├── Phase 2: SQL passes (< 500K nodes)
        │     ├── DynamicPropertiesPass($db)
        │     ├── PerPropertyMemoryPass($db)
        │     ├── TopArraysPass($db)
        │     ├── TopStringsPass($db)
        │     ├── NonTreeEdgePass($db)
        │     └── StructuralDedupPass($db)
        ├── Phase 3: Graph passes (< 500K edges)
        │     ├── GraphSubstrate::loadFromDb($db)
        │     │     ├── loadNodeSizes()
        │     │     ├── loadEdges()
        │     │     ├── computeSubtreeSizes() [post-order DFS]
        │     │     └── computeScc() [Tarjan's algorithm]
        │     ├── CycleClusterPass($substrate)
        │     ├── DrillDownPass($substrate, $db)
        │     ├── ChokePointPass($substrate, $db)
        │     ├── BlameAllocationPass($substrate, $db)
        │     └── RetainedSizeConfidencePass($substrate)
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
- `$all_parents[child_id]`: reverse adjacency (all edges)
- `$roots[]`: nodes with NULL parent

**Post-order DFS**:
- `$subtree_sizes[node_id]`: tree-based retained size

**Tarjan SCC** (iterative, stack-based):
- `$node_to_scc[node_id]`: SCC membership
- `$scc_profiles[scc_id]`: node count, total size, external in/out edges,
  class composition signature, single-owner likelihood

All computed once, shared by CycleClusterPass, DrillDownPass,
ChokePointPass, BlameAllocationPass, RetainedSizeConfidencePass.

Measured performance:

| Edges | Load | DFS | SCC | Total | Memory |
|---|---|---|---|---|---|
| 200K | 0.2s | 0.1s | 0.2s | **0.5s** | ~130 MB |
| 1M | 1s | 0.6s | 0.8s | **2.4s** | ~300 MB |
| 2.5M | 7s | 1.6s | - | **~10s** | ~1.2 GB |

## Computation Passes

### Phase 1: Summary-Based (always run)

These passes use only data from the `summary`, `location_types_summary`,
and `class_objects_summary` tables. Zero additional memory. Works for
any target size.

| Pass | Source | Emits |
|---|---|---|
| 1. OverviewPass | summary table | `overview`, `coverage_gap` |
| 2. TypeBreakdownPass | location_types_summary | `dominant_type` |
| 3. ClassRankingPass | class_objects_summary | `dominant_class` |
| 4. CompanionDetectionPass | class_objects_summary | `companion_pair` |

### Phase 2: SQL-Based (< 500K nodes)

These passes run SQL queries against the full context tables.

| Pass | Source | Emits |
|---|---|---|
| 5. DynamicPropertiesPass | context_edges (link_name = 'dynamic_properties') | `dynamic_properties_overhead` |
| 6. PerPropertyMemoryPass | context_edges + context_node_locations | `expensive_property` |
| 7. TopArraysPass | v_arrays + 3-hop ancestor | `large_array` |
| 8. TopStringsPass | context_node_locations (ZendString) | `large_string` |
| 9. NonTreeEdgePass | context_edges (is_tree = 0) | `shared_singleton`, `shared_fanin`, `dedup_candidate` |
| 10. StructuralDedupPass | context_node_locations + context_edges (shape hash) | `structural_duplicate`, `empty_object` |

### Phase 3: Graph-Based (< 500K edges)

These passes use the in-memory GraphSubstrate.

| Pass | Source | Emits |
|---|---|---|
| 11. CycleClusterPass | SCC profiles | `cycle_cluster`, `micro_cycle` |
| 12. DrillDownPass | subtree_sizes + children | `bottleneck_path` |
| 13. ChokePointPass | subtree_sizes + node_sizes | `choke_point` |
| 14. BlameAllocationPass | node_sizes + tree/all edges | `root_blame` |
| 15. RetainedSizeConfidencePass | SCC profiles | `retained_exact`, `retained_approximate` |

## Adaptive Complexity

The `ReportGenerator` checks `node_count` and `edge_count` from the
database to decide which phases to run:

| Target size | Phases | Strategy |
|---|---|---|
| < 500K nodes, < 500K edges | All 15 passes | Full analysis |
| < 500K nodes, >= 500K edges | Phase 1 + 2 | SQL analysis, no graph |
| >= 500K nodes | Phase 1 only | Summary + heuristics |

This prevents out-of-memory errors when analyzing very large snapshots.

The `--full-analysis` flag (passed as `$full_analysis = true` to
`generateFromDb()`) bypasses these limits and forces all phases to run.

## File Layout

```
src/Inspector/Output/MemoryOutput/Report/
├── Finding.php                    # Value object
├── FindingSeverity.php            # Enum: high|medium|low|info|warning
├── FindingConfidence.php          # Enum: high|medium|low
├── ReportGenerator.php            # Orchestrator
├── ReportResult.php               # Meta + findings container
├── Formatter/
│   ├── ReportFormatterInterface.php
│   ├── TextReportFormatter.php    # Human-readable text
│   └── JsonReportFormatter.php    # Structured JSON
├── Pass/
│   ├── PassInterface.php          # Common interface
│   ├── OverviewPass.php           # Pass 1
│   ├── TypeBreakdownPass.php      # Pass 2
│   ├── ClassRankingPass.php       # Pass 3
│   ├── CompanionDetectionPass.php # Pass 4
│   ├── DynamicPropertiesPass.php  # Pass 5
│   ├── PerPropertyMemoryPass.php  # Pass 6
│   ├── TopArraysPass.php         # Pass 7 (renumbered from design)
│   ├── TopStringsPass.php        # Pass 8
│   ├── NonTreeEdgePass.php       # Pass 9
│   ├── StructuralDedupPass.php   # Pass 10
│   ├── CycleClusterPass.php      # Pass 11
│   ├── DrillDownPass.php         # Pass 12
│   ├── ChokePointPass.php        # Pass 13
│   ├── BlameAllocationPass.php   # Pass 14
│   └── RetainedSizeConfidencePass.php  # Pass 15
└── Substrate/
    └── GraphSubstrate.php         # Graph load + DFS + Tarjan SCC

src/Inspector/Output/MemoryOutput/
├── ReportMemoryOutput.php         # MemoryOutputInterface for inline mode

src/Command/Inspector/
├── MemoryReportCommand.php        # inspector:memory:report command
```

## Design Principles

1. **Graph algorithms in PHP, results in DB, queries in SQL.**
   SCC/DFS/BFS are computed in PHP. Subsequent analysis uses SQL JOINs
   and aggregations against the existing context tables.

2. **Computation once, findings many.**
   Graph load + DFS + SCC happen once via GraphSubstrate. All graph
   passes share the same substrate instance.

3. **Findings over numbers.**
   Don't show "top 10 classes." Show "this class is 95% of objects,
   which is abnormal, and here's the likely cause."

4. **Confidence on everything.**
   Every finding says how sure it is (`confidence`) and why it might
   be wrong (shared memory fraction for blame, cycle count for
   retained size).

5. **Replay everything.**
   Every finding includes `replay_query` — the SQL to reproduce it
   directly against the SQLite database.

## Finding Types Catalog

| Kind | Severity | Source Pass | Validated On |
|---|---|---|---|
| `overview` | info | OverviewPass | All |
| `coverage_gap` | warning | OverviewPass | Guzzle (8.6%), Fiber (pre-fix) |
| `dominant_type` | high/medium | TypeBreakdownPass | smalot (arrays 86%), php-imap (strings 87%) |
| `dominant_class` | high | ClassRankingPass | PrinsFrank (95.3%), PHPStan (213K) |
| `companion_pair` | medium | CompanionDetectionPass | PhpSpreadsheet, CommonMark, PhpWord, Forms |
| `dynamic_properties_overhead` | medium/low | DynamicPropertiesPass | Monolog (93K DateTimeImmutable) |
| `expensive_property` | medium/low | PerPropertyMemoryPass | Monolog (message 11.5 MB) |
| `large_array` | medium/low | TopArraysPass | smalot (uchrCache 2.6 MB) |
| `large_string` | medium/low | TopStringsPass | php-imap (raw 210 KB x 200) |
| `shared_singleton` | info | NonTreeEdgePass | Symfony Forms (formFactory) |
| `shared_fanin` | medium | NonTreeEdgePass | php-imap (oMessage) |
| `dedup_candidate` | low/info | NonTreeEdgePass | Symfony Forms (dispatcher 1,801x) |
| `structural_duplicate` | medium/low | StructuralDedupPass | CommonMark (246K empty Data) |
| `empty_object` | medium/low | StructuralDedupPass | CommonMark, Symfony Forms |
| `cycle_cluster` | medium/low | CycleClusterPass | php-imap (201x), SimplePie (1x2K), Forms (1,802x) |
| `micro_cycle` | low | CycleClusterPass | Symfony Forms (OptionsResolver <-> Closure) |
| `bottleneck_path` | high | DrillDownPass | PHP-Parser ($ast 89 MB), CommonMark (71 MB) |
| `choke_point` | high | ChokePointPass | Eloquent (Collection 72B -> 15 MB) |
| `root_blame` | info | BlameAllocationPass | Eloquent (class_table 48%, call_frames 35%) |
| `retained_exact` | info | RetainedSizeConfidencePass | Monolog (DAG, no cycles) |
| `retained_approximate` | info | RetainedSizeConfidencePass | php-imap (201 cycles) |
