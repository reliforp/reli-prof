# Memory Analysis Report

The memory profiler can automatically analyze a snapshot and produce a prioritized report of findings — not raw tables and numbers, but actionable conclusions about what is consuming memory, why, and what to investigate next.

## Quick Start

### From a SQLite snapshot (recommended)

First capture a snapshot to SQLite, then generate the report:

```bash
sudo ./reli inspector:memory -p <pid> -f sqlite3 -o snapshot.db
./reli inspector:memory:report snapshot.db
```

### Inline with live capture

Generate the report directly from a live process (captures to a temp SQLite file internally):

```bash
sudo ./reli inspector:memory -p <pid> -f report
```

## Output Formats

### Human-readable text (`report`)

```bash
./reli inspector:memory:report snapshot.db
# or
sudo ./reli inspector:memory -p <pid> -f report
```

Example output:

```
======================================================================
 reli-prof Memory Analysis Report
======================================================================

=== Overview ===
  Captured: 2026-03-28T17:58:56Z
  Heap: 153.76 MB (99.5% analyzed), VM stack: 256.00 KB, Compiler arena: 32.00 KB

  Call Stack at capture:
    #0 sleep()
    #1 <main>:28

=== Findings ===

  [HIGH]  703.13 KB impacted
    dominant_class: App\Models\User: 10,000 instances x 72 B = 98.2% of object memory (703.13 KB)
    Unbounded accumulation — likely a loop without limit
    Next: Check if count scales with input size; Look for owner path

  [HIGH]  153.76 MB impacted
    bottleneck_path: <main>:28::$users->items (153.76 MB)
    Heaviest memory path — the primary chain of memory consumption

  [MEDIUM]  9.53 MB impacted
    property_scaling: App\Models\User (10,000 instances): 5 per-instance props (0.49 KB/instance retained), 12 shared
    PER-INSTANCE (retained, scales with count):
      App\Models\User::$attributes: 10,000 copies x 599 B = 5.86 MB
      App\Models\User::$casts: 10,000 copies x 376 B = 3.67 MB
    (14 scalar properties per-instance, included in object size)
    SHARED: App\Models\User::$relations (array, CoW), App\Models\User::$fillable (array, CoW)

  [MEDIUM]  42.50 MB impacted
    cycle_cluster: 200 identical cycles (3 classes, 170.31 KB shallow, 42.50 MB retained)
    Per cycle: 1x Webklex\PHPIMAP\Message + 3x Webklex\PHPIMAP\Attachment + 1x Webklex\PHPIMAP\AttachmentCollection
    Back-reference: Webklex\PHPIMAP\Attachment::$oMessage -> Webklex\PHPIMAP\Message
    Example: <main>:28::$messages[0]->attachments->items[0]
    (199 more with same pattern)
    Single entry point — breaking the back-reference likely frees this cycle
    Next: Break Webklex\PHPIMAP\Attachment::$oMessage -> Webklex\PHPIMAP\Message to eliminate all 200 cycles

  [MEDIUM]  —
    companion_cluster: FormBuilder (3,611, 1.74 MB) always paired with Closure (3,619, 1.19 MB) — 2.93 MB

  [LOW]  182.81 KB impacted
    dedup_candidate: Attachment::$part (Part): 600 copies x 312 B = 182.81 KB
    195/600 copies have identical content (32%). Example: "--boundary_mixed..."

=== Root Blame Allocation ===

  Root Branch                Exclusive     Shared      Total   % Heap
  ----------------------------------------------------------------------
  call_frames                  38.50 MB    1.20 MB   39.70 MB   95.2%
  class_table                   1.80 MB  100.00 KB    1.90 MB    4.6%

=== Additional Info ===
  [retained_approximate] 201 cycles (3,015 nodes) — retained size is approximate

======================================================================
```

### Structured JSON (`report-json`)

For programmatic consumption or AI-assisted analysis:

```bash
./reli inspector:memory:report snapshot.db -f report-json
# or
sudo ./reli inspector:memory -p <pid> -f report-json
```

Each finding includes machine-readable fields:

```json
{
  "meta": {
    "node_count": 137922,
    "edge_count": 200709,
    "php_version": "v84",
    "heap_memory_analyzed_percentage": 99.52,
    "captured_at": "2026-03-28T17:58:56Z"
  },
  "findings": [
    {
      "kind": "dominant_class",
      "severity": "high",
      "confidence": "high",
      "summary": "App\\Models\\User: 10,000 instances x 72 B = 98.2% of object memory (703.13 KB)",
      "facts": {
        "class_name": "App\\Models\\User",
        "count": 10000,
        "memory_bytes": 720000,
        "percentage_of_object_memory": 98.2,
        "avg_size": 72
      },
      "hypothesis": "Unbounded accumulation — likely a loop without limit",
      "next_checks": [
        "Check if count scales with input size",
        "Look for owner path to find the accumulating container"
      ],
      "impact_bytes": 720000
    }
  ]
}
```

## Command Reference

### `inspector:memory:report`

Generate a report from an existing SQLite database:

```
Usage:
  inspector:memory:report [options] <db-file>

Arguments:
  db-file                                Path to the SQLite database file

Options:
  -f, --output-format=FORMAT             Output format: report (text) or report-json [default: report]
  -o, --output=PATH                      Output file path (default: stdout)
      --run-id=ID                        Run ID to analyze [default: 1]
      --pretty-print|--no-pretty-print   Pretty print JSON output (default: on)
      --full-analysis|--no-full-analysis Run all passes (default: on; --no-full-analysis for large snapshots)
      --memory-limit=LIMIT               Set PHP memory_limit (e.g. 2G, 512M)
```

### `inspector:memory` with report format

Generate a report directly from a live process:

```bash
sudo ./reli inspector:memory -p <pid> -f report         # text
sudo ./reli inspector:memory -p <pid> -f report-json    # JSON
sudo ./reli inspector:memory -p <pid> -f report -o report.txt  # to file
```

## Finding Types

Findings are sorted by severity (HIGH first), then by `impact_bytes` descending within the same severity. Each finding shows its impact on the first line for easy visual scanning:

```
  [HIGH]  153.76 MB impacted
    bottleneck_path: <main>::$messages[0]->structure->parts
  [MEDIUM]  40.21 MB impacted
    expensive_property: Structure::$raw (200 x 211.00 KB)
  [MEDIUM]  —
    companion_cluster: 4 classes x ~200 instances
```

All class names use fully qualified names (FQCN). Paths use PHP syntax: `<main>:28::$messages[0]->structure->raw`.

### High Severity

| Kind | Description | Example |
|---|---|---|
| `dominant_class` | One class > 50% of object memory | "App\Models\User: 10,000 x 72 B = 98.2%" |
| `dominant_type` | One memory type > 80% of heap | "ZendString accounts for 87% of heap" |
| `bottleneck_path` | Heaviest memory path from root (PHP syntax) | "<main>:28::$users->items (153.76 MB)" |
| `choke_point` | Small node retaining large subtree (> 30% heap) | "MarkdownParser (152 B) holds 73.00 MB" |
| `resource_leak_risk` | PDO/Mysqli held by circular reference | "PDO held by cycle (Repository, Service)" |

### Medium Severity

| Kind | Description | Example |
|---|---|---|
| `dominant_type` | One memory type 50-80% of heap | "ZendObject accounts for 66% of heap" |
| `companion_cluster` | Classes with matching instance counts | "FormBuilder (3,611, 1.74 MB) always paired with Closure (3,619, 1.19 MB) — 2.93 MB" |
| `property_scaling` | Per-instance vs shared property breakdown | "User: 5 per-instance (0.49 KB/instance), 12 shared" |
| `ownership_pattern` | 1:1 parent-child class ownership | "DotAccessData (246K) owned 1:1 by 12 classes (100%)" |
| `dynamic_properties_overhead` | Classes with dynamic property tables | "93,315 DateTimeImmutable = 4.98 MB" |
| `expensive_property` | Class-qualified property > 1 MB | "Message::$raw: 200 x 210.00 KB = 41.02 MB" |
| `cycle_cluster` | Circular references with back-ref and retained size | "200 cycles (3 classes, 170 KB shallow, 42.50 MB retained)" |
| `choke_point` | Subtree 10-30% of heap | "Collection (72 B) holds 15.00 MB" |
| `structural_duplicate` | Objects with identical shape | "246K Data x 56 B = 13.40 MB" |
| `empty_object` | Objects with no stored properties | "OrderedHashMap: 1,600 x 88 B" |
| `large_array` | Large arrays (retained size when available) | "15.30 MB retained (table: 160.00 KB), 10,000 elements" |
| `large_string` | Large strings with owner path | "205.88 KB — <main>:67::$messages[0]->structure->raw" |
| `sparse_array` | Arrays with low slot utilization | "256.00 KB table, 5/16,384 slots (0.03%)" |

### Low Severity

| Kind | Description | Example |
|---|---|---|
| `micro_cycle` | Two-node circular references | "1,802 micro-cycles: 1x OptionsResolver + 1x Closure" |
| `choke_point` | Subtree > 1 MB but < 10% of heap | "classMap (1.51 MB)" |
| `dedup_candidate` | Same-size shared objects with value comparison | "600 copies x 312 B — 32% identical" |

### Info

| Kind | Description |
|---|---|
| `overview` | Heap total/usage, VM stack, compiler arena, analyzed percentage |
| `call_stack` | Call stack at the time of snapshot capture |
| `shared_singleton` | Many references to one target (normal singleton pattern) |
| `shared_fanin` | Multiple references to shared objects (supplementary) |
| `di_container_cycle` | Large DI container cycle (structural cost, >15 classes) |
| `root_blame` | Memory attributed to each root branch |
| `retained_exact` | No cycles — retained size is exact |
| `retained_approximate` | Cycles exist — retained size is approximate |

### Warning

| Kind | Description |
|---|---|
| `coverage_gap` | Less than 95% of heap analyzed — unaccounted memory |

## Analysis Phases

By default, full analysis runs all phases (`--full-analysis` is on). Use `--no-full-analysis` to limit analysis for very large snapshots.

| Snapshot size | Phases run | Strategy |
|---|---|---|
| Any size | Phase 1 | Summary: overview, call stack, types, classes, companions |
| < 500K nodes | Phase 2 | SQL: dynamic properties, structural dedup |
| < 500K edges | Phase 3 | Graph: SCC, drill-down, choke points, blame, property scaling, expensive property, ownership, top arrays/strings, non-tree edges, retained size. SCC and retained size use only strong edges (weak/structural edges excluded) |

When `--no-full-analysis` is set, Phase 2 skips at >= 500K nodes and Phase 3 skips at >= 500K edges.

Several passes are deferred from Phase 2 to Phase 3 when graph substrate is available, to benefit from retained sizes and full-path resolution:
- PropertyScalingPass (retained per-property cost)
- PerPropertyMemoryPass (class-qualified, O(edges) in-memory)
- TopArraysPass / TopStringsPass (full PHP-syntax paths)
- NonTreeEdgePass (retained size for dedup candidates)

Memory usage for graph load: ~300 MB for 1M edges, ~2 GB for 6M edges.

## Tips

- **Start with the SQLite workflow**: Capture once, analyze many times. The `inspector:memory:report` command is fast on an existing database.
- **Use `--memory-limit=2G`** for large snapshots instead of `php -d memory_limit=2G`.
- **Use JSON for CI/automation**: The `report-json` format is designed for programmatic consumption — pipe it to `jq`, feed it to an LLM, or integrate into monitoring.
- **Findings are sorted**: HIGH severity and largest impact appear first. Focus on the top findings.
- **Check `impact_bytes`**: Findings are not all equal. Focus on findings with the largest `impact_bytes` for the biggest memory savings.
- **Class names are FQCN**: All class names use fully qualified names for unambiguous identification.
- **Paths use PHP syntax**: `<main>:28::$messages[0]->structure->raw` instead of raw context tree paths.
- **Companion clusters**: When classes have matching counts, reducing one reduces the others. The `ownership_pattern` finding shows the actual parent-child relationship.
- **Property scaling**: The `property_scaling` finding shows which properties scale with instance count (per-instance) vs which are shared (CoW). Per-instance properties with small values may benefit from lazy initialization.
