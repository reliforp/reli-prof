# Design: Auto-Analysis Report Feature

## Goal

Automatically analyze a memory snapshot and produce structured findings
that tell a human or AI **what is wrong, why, and what to investigate next**.

Not a dump of tables and numbers, but a prioritized list of actionable
conclusions derived from the data.

## Two Layers

### Computation layer (internal): Passes

Passes are internal computation steps. Each pass reads from the SQLite DB
or shared in-memory structures and produces intermediate results.

### Output layer (external): Findings

Findings are the unit of output. Each finding has:

```
kind:               Identifier (e.g., "dominant_class", "cycle_cluster")
severity:           high | medium | low | info
confidence:         high | medium | low
summary:            One-line human description
facts:              Observed data points
hypothesis:         What this likely means
next_checks:        What to investigate further
impact_bytes:       Estimated recoverable memory
evidence_node_ids:  Representative node IDs for drill-down
representative_paths: Owner paths (3-hop or drill-down)
replay_query:       SQL to reproduce this finding
```

## Computation Passes

### Substrate: Graph Load + SCC

Before any analysis pass runs, load the graph once and compute reusable
intermediate structures. All subsequent passes share these.

**Graph load** (from SQLite):
- `$node_sizes[node_id]`: shallow size per node
- `$children[parent_id]`: int-only adjacency list (tree edges)
- `$all_parents[child_id]`: reverse adjacency (all edges)

**Post-order DFS**:
- `$subtree_sizes[node_id]`: tree-based retained size

**Tarjan SCC**:
- `$node_to_scc[node_id]`: SCC membership
- `$scc_profiles[scc_id]`: node count, size, ext in/out, class composition, signature

These are computed once. Cost (measured):

| Edges | Load | DFS | SCC | Total | Memory |
|---|---|---|---|---|---|
| 200K | 0.2s | 0.1s | 0.2s | **0.5s** | ~130 MB |
| 1M | 1s | 0.6s | 0.8s | **2.4s** | ~300 MB |
| 2.5M | 7s | 1.6s | - | **~10s** | ~1.2 GB |
| 6M | 11s | 2.6s | 3s | **~17s** | ~2.2 GB |

For targets > 10M edges, use FFI CSR format (~4 bytes/edge instead of ~100).

SCC results are stored in DB for query access:

```sql
CREATE TABLE scc_components (
    run_id                        INTEGER NOT NULL,
    scc_id                        INTEGER NOT NULL,
    node_count                    INTEGER NOT NULL,
    total_shallow_size            INTEGER NOT NULL,
    internal_edge_count           INTEGER NOT NULL,
    ext_incoming_edges            INTEGER NOT NULL,
    ext_outgoing_edges            INTEGER NOT NULL,
    dominant_class_name           TEXT,
    dominant_class_ratio_by_count REAL,
    dominant_class_ratio_by_size  REAL,
    PRIMARY KEY (run_id, scc_id)
);

CREATE TABLE scc_members (
    run_id  INTEGER NOT NULL,
    scc_id  INTEGER NOT NULL,
    node_id INTEGER NOT NULL,
    PRIMARY KEY (run_id, node_id)
);

CREATE TABLE scc_class_summary (
    run_id     INTEGER NOT NULL,
    scc_id     INTEGER NOT NULL,
    class_name TEXT NOT NULL,
    count      INTEGER NOT NULL,
    total_size INTEGER NOT NULL
);
```

### Pass 1: Overview

Source: `MemoryAnalysisResult::$summary` (no DB needed).

Emits `info` findings:
- `overview`: heap total/usage, VM stack, compiler arena, analyzed %

Emits `warning` finding if `analyzed_percentage < 95%`:
- `coverage_gap`: quantifies unaccounted memory, suggests extension investigation

### Pass 2: Type Breakdown

Source: `MemoryAnalysisResult::$location_types_summary`.

Emits finding when one type dominates (> 50% of heap):
- `dominant_type`: "ZendString accounts for 66% of heap"

### Pass 3: Class Ranking

Source: `MemoryAnalysisResult::$class_objects_summary`.

Emits finding when one class dominates (> 50% of object memory):
- `dominant_class`: "100,203 CrossReferenceEntryInUseObject = 95.3% of objects"

### Pass 4: Companion Detection

Source: `class_objects_summary` (count matching).

Finds class pairs where instance counts are within 5%:
- `companion_pair`: "Cell (200,020) always paired with IgnoredErrors (200,020)"

Validated on: PhpSpreadsheet, CommonMark, PhpWord, Symfony Forms.

### Pass 5: Dynamic Properties

Source: `context_edges WHERE link_name = 'dynamic_properties'` (SQL).

Finds classes with many unnecessary dynamic property tables:
- `dynamic_properties_overhead`: "93,315 DateTimeImmutable with dynamic props = 5.1 MB"

Validated on: Monolog.

### Pass 6: Per-Property Memory

Source: `context_edges` + `context_node_locations` (SQL).

Aggregates `link_name × child size` for high-frequency properties:
- `expensive_property`: "message: 100K occurrences × 121B = 11.5 MB"

Filter: `count > max(class_count) / 2` AND not in structural name list.

### Pass 7: Cycle Clusters (SCC-based)

Source: SCC substrate.

Groups identical SCC patterns by class composition signature:
- `cycle_cluster`: "201 identical cycles: Attachment:3, Message:1"
- `micro_cycle`: "1,802 OptionsResolver ↔ Closure micro-cycles"

Per-SCC: dominant class ratio, ext incoming/outgoing, single-owner likelihood.

### Pass 8: Top Arrays with Owners

Source: `v_arrays` + 3-hop ancestor JOIN (SQL).

- `large_array`: "2.62 MB, 65K elements — Font → static_properties → uchrCache"

### Pass 9: Top Strings with Owners

Source: `context_node_locations` + 3-hop ancestor (SQL).

- `large_string`: "210 KB — structure → raw (email body)"

### Pass 10: Non-Tree Edge Classification

Source: `context_edges WHERE is_tree = 0` (SQL).

Classifies property-level shared refs (after filtering noise):
- `shared_singleton`: "formFactory: 3,611 refs → 1 target [normal]"
- `shared_fanin`: "oMessage: 603 refs → 201 targets (3.0 each) [cycle]"
- `dedup_candidate`: "dispatcher: 1,801 copies × 88B ALL SAME SIZE = 155 KB wasted"

### Pass 11: Structural Dedup

Source: `context_node_locations` + `context_edges` (SQL, shape hash).

Finds objects with identical class + size + property set:
- `structural_duplicate`: "246K Data objects with NO properties = 13.4 MB"
- `empty_object`: "OrderedHashMap: 1,600 × 88B, no properties stored"

Theoretical savings: sum of `(count - 1) * size` for each group.

### Pass 12: Drill-Down

Source: `$subtree_sizes` + `$children` (in-memory).

Follows the heaviest branch from root, showing top 3 at each level:
- `bottleneck_path`: "call_frames → frame 1 → $users → Collection → items[] (16 MB)"

### Pass 13: Choke Points

Source: `$subtree_sizes` + `$node_sizes` (in-memory).

Finds nodes where `subtree_size >> shallow_size`:
- `choke_point`: "MarkdownParser (152B shallow) holds 73 MB via closedBlockParsers"

### Pass 14: Blame Allocation

Source: `$node_sizes` + tree edges + all edges (in-memory).

Distributes memory to root owners (call_frames, class_table, objects_store, ...):
- `root_blame`: "call_frames: 154 MB (98%) exclusive, 1 MB shared"

Reports shared memory fraction as confidence indicator.

### Pass 15: Retained Size Confidence

Source: SCC substrate.

- If SCC count = 0: `retained_exact`: "No cycles — retained size is exact"
- If SCCs exist: `retained_approximate`: "201 cycles — collapse to DAG for exact"

## Adaptive Complexity

| Target size | Passes | Strategy |
|---|---|---|
| < 10K objects | All 15 | Full analysis with v_node_paths |
| 10K - 100K | 1-11, 12-15 | 3-hop paths, no recursive CTE |
| > 100K | 1-6, 7 (SCC), 12-13 | Skip SQL-heavy passes |
| > 500K | 1-6 only | Summary + heuristics, no graph load |

For the summary-only tier (> 500K), no SQLite or graph load needed —
uses only `MemoryAnalysisResult` (summary, types, classes).

## Output Formats

### `--output-format=report` (human-readable text)

```
======================================================================
 reli-prof Memory Analysis Report
======================================================================

=== Overview ===
  Heap: 41.70 MB (99.5% analyzed)

=== Findings ===

  [HIGH] dominant_class: CrossReferenceEntryInUseObject
    100,203 instances consuming 95.3% of object memory (7,046 KB).
    Likely unbounded accumulation in a loop.
    Next: check PREV chain traversal in CrossReferenceSourceParser.

  [MEDIUM] cycle_cluster: 201 identical Message ↔ Attachment cycles
    Each: 15 nodes, 0.85 KB. Single entry point per cycle.
    Breaking oMessage back-reference eliminates all 201 cycles.

  [LOW] dedup_candidate: dispatcher (1,801 copies × 88B, ALL SAME SIZE)
    155 KB reclaimable via sharing.

======================================================================
```

### `--output-format=report-json` (structured, for AI consumption)

```json
{
  "meta": {
    "pid": 12345,
    "php_version": "v84",
    "memory_limit": 134217728,
    "analyzed_percentage": 99.52,
    "node_count": 137922,
    "edge_count": 200709,
    "scc_count": 201
  },
  "findings": [
    {
      "kind": "dominant_class",
      "severity": "high",
      "confidence": "high",
      "summary": "CrossReferenceEntryInUseObject: 100,203 instances, 95.3% of object memory",
      "facts": {
        "class_name": "..\\CrossReferenceEntryInUseObject",
        "count": 100203,
        "memory_bytes": 7214612,
        "percentage_of_object_memory": 95.3
      },
      "hypothesis": "Unbounded accumulation — likely a loop without limit",
      "next_checks": [
        "Check if count scales with input size",
        "Look for owner path to find the accumulating container"
      ],
      "impact_bytes": 7214612,
      "representative_paths": [
        "call_frames → 1 → local_variables → $crossReferenceSections"
      ],
      "replay_query": "SELECT class_name, count, memory_usage FROM class_objects_summary ORDER BY memory_usage DESC LIMIT 1"
    }
  ]
}
```

### `inspector:memory:query` (preset queries on existing SQLite)

```
inspector:memory:query --db=out.sqlite3 --query=top-arrays
inspector:memory:query --db=out.sqlite3 --query=top-strings
inspector:memory:query --db=out.sqlite3 --query=circular-refs
inspector:memory:query --db=out.sqlite3 --query=companions
inspector:memory:query --db=out.sqlite3 --query=drill-down
inspector:memory:query --db=out.sqlite3 --query=all
```

## Finding Types Catalog

| Kind | Severity | Source Pass | Validated On |
|---|---|---|---|
| `overview` | info | 1 | All |
| `coverage_gap` | warning | 1 | Guzzle (8.6%), Fiber (pre-fix) |
| `dominant_type` | high/medium | 2 | smalot (arrays 86%), php-imap (strings 87%) |
| `dominant_class` | high | 3 | PrinsFrank (95.3%), PHPStan (213K) |
| `companion_pair` | medium | 4 | PhpSpreadsheet, CommonMark, PhpWord, Forms |
| `dynamic_properties_overhead` | medium | 5 | Monolog (93K DateTimeImmutable) |
| `expensive_property` | medium | 6 | Monolog (message 11.5 MB) |
| `cycle_cluster` | medium | 7 | php-imap (201×), SimplePie (1×2K), Forms (1,802×) |
| `micro_cycle` | low | 7 | Symfony Forms (OptionsResolver ↔ Closure) |
| `large_array` | medium | 8 | smalot (uchrCache 2.6 MB) |
| `large_string` | medium | 9 | php-imap (raw 210 KB × 200) |
| `shared_singleton` | info | 10 | Symfony Forms (formFactory) |
| `shared_fanin` | medium | 10 | php-imap (oMessage) |
| `dedup_candidate` | low | 10 | Symfony Forms (dispatcher 1,801×) |
| `structural_duplicate` | medium | 11 | CommonMark (246K empty Data) |
| `empty_object` | medium | 11 | CommonMark, Symfony Forms |
| `bottleneck_path` | high | 12 | PHP-Parser ($ast 89 MB), CommonMark (71 MB) |
| `choke_point` | high | 13 | Eloquent (Collection 72B → 15 MB) |
| `root_blame` | info | 14 | Eloquent (class_table 48%, call_frames 35%) |
| `retained_exact` | info | 15 | Monolog (DAG, no cycles) |
| `retained_approximate` | info | 15 | php-imap (201 cycles) |

## Implementation Phases

### Phase 1: Summary Report (no graph load)

Passes 1-6. Uses only `MemoryAnalysisResult`.
Zero additional memory. Works for any target size.

### Phase 2: SQL-Based Analysis

Passes 7-11. Uses SQLite queries.
Moderate cost. SCC computed in PHP, stored in DB.

### Phase 3: Graph Analysis

Passes 12-15. Loads full edge graph into PHP.
For targets < 500K edges: ~3 seconds, ~300 MB.
For larger: use FFI CSR (~4 bytes/edge).

## Design Principles

1. **Graph algorithms in PHP, results in DB, queries in SQL.**
   SCC/DFS/BFS are computed in PHP. Results go to SQLite tables.
   Subsequent analysis uses SQL JOINs and aggregations.

2. **Computation once, findings many.**
   Graph load + DFS + SCC happen once. All passes share the results.

3. **Findings over numbers.**
   Don't show "top 10 classes." Show "this class is 95% of objects,
   which is abnormal, and here's the likely cause."

4. **Confidence on everything.**
   Every finding says how sure it is and why it might be wrong.

5. **Replay everything.**
   Every finding includes the SQL or algorithm to reproduce it.
