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
  Heap: 41.70 MB (99.5% analyzed), VM stack: 0.02 MB, Compiler arena: 0.01 MB

=== Findings ===

  [HIGH] dominant_class: CrossReferenceEntryInUseObject: 100,203 instances, 95.3% of object memory (6.88 MB)
    Unbounded accumulation — likely a loop without limit
    Next: Check if count scales with input size; Look for owner path to find the accumulating container

  [HIGH] bottleneck_path: call_frames -> 1 -> local_variables -> $crossReferenceSections (41.50 MB)
    Heaviest memory path — the primary chain of memory consumption
    Next: Examine the leaf of this path for the actual data consuming memory

  [MEDIUM] cycle_cluster: 201 identical cycles: Attachment:3, Message:1 (15 nodes each, 170.89 KB total)
    Single entry point — breaking the owner reference likely frees this cycle
    Next: Identify the back-reference causing the cycle; Consider using WeakReference or explicit cleanup

  [LOW] dedup_candidate: dispatcher: 1,801 copies x 88B ALL SAME SIZE = 154.77 KB wasted
    Multiple copies of same-size objects via shared references; may be shareable

=== Root Blame Allocation ===

  Root Branch                Exclusive    Shared     Total   % Heap
  ----------------------------------------------------------------------
  call_frames                  38.50M     1.20M    39.70M    95.2%
  class_table                   1.80M     0.10M     1.90M     4.6%

=== Additional Info ===
  [retained_approximate] 201 cycles (3,015 nodes) — retained size is approximate; collapse to DAG for exact

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
    "heap_memory_analyzed_percentage": 99.52
  },
  "findings": [
    {
      "kind": "dominant_class",
      "severity": "high",
      "confidence": "high",
      "summary": "CrossReferenceEntryInUseObject: 100,203 instances, 95.3% of object memory (6.88 MB)",
      "facts": {
        "class_name": "..\\CrossReferenceEntryInUseObject",
        "count": 100203,
        "memory_bytes": 7214612,
        "percentage_of_object_memory": 95.3,
        "avg_size": 72
      },
      "hypothesis": "Unbounded accumulation — likely a loop without limit",
      "next_checks": [
        "Check if count scales with input size",
        "Look for owner path to find the accumulating container"
      ],
      "impact_bytes": 7214612,
      "replay_query": "SELECT class_name, count, memory_usage FROM class_objects_summary ORDER BY memory_usage DESC LIMIT 1"
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
  db-file                     Path to the SQLite database file

Options:
  -f, --output-format=FORMAT  Output format: report (text) or report-json [default: report]
  -o, --output=PATH           Output file path (default: stdout)
      --run-id=ID             Run ID to analyze [default: 1]
      --pretty-print          Pretty print JSON output (default: on)
```

### `inspector:memory` with report format

Generate a report directly from a live process:

```bash
sudo ./reli inspector:memory -p <pid> -f report         # text
sudo ./reli inspector:memory -p <pid> -f report-json    # JSON
sudo ./reli inspector:memory -p <pid> -f report -o report.txt  # to file
```

## Finding Types

Each finding has a `kind`, `severity` (high/medium/low/info/warning), and `confidence` (high/medium/low).

### High Severity

| Kind | Description | Example |
|---|---|---|
| `dominant_class` | One class > 50% of object memory | "100,203 CrossReferenceEntryInUseObject = 95.3%" |
| `dominant_type` | One memory type > 80% of heap | "ZendString accounts for 87% of heap" |
| `bottleneck_path` | Heaviest memory path from root | "call_frames -> $users -> Collection -> items[] (16 MB)" |
| `choke_point` | Small node retaining large subtree | "MarkdownParser (152B) holds 73 MB via closedBlockParsers" |

### Medium Severity

| Kind | Description | Example |
|---|---|---|
| `dominant_type` | One memory type > 50% of heap | "ZendObject accounts for 66% of heap" |
| `companion_pair` | Two classes with matching instance counts | "Cell (200,020) always paired with IgnoredErrors (200,020)" |
| `companion_cluster` | N classes with matching instance counts | "6 classes with ~1,800 instances each: A, B, C, D, E, F" |
| `dynamic_properties_overhead` | Classes with dynamic property tables (> 1 MB) | "93,315 DateTimeImmutable with dynamic props = 5.1 MB" |
| `expensive_property` | Class-qualified property consuming memory (> 100 KB) | "Message::$raw: 200 occurrences x 210KB = 41 MB" |
| `cycle_cluster` | Group of identical circular reference patterns | "201 identical Message <-> Attachment cycles" |
| `shared_fanin` | Multiple references to shared objects | "oMessage: 603 refs -> 201 targets (3.0 each)" |
| `structural_duplicate` | Objects with identical shape (class + size + properties) | "246K Data objects with NO properties = 13.4 MB" |
| `empty_object` | Objects with no stored properties | "OrderedHashMap: 1,600 x 88B, no properties stored" |
| `large_array` | Individual large arrays (> 1 MB) | "2.62 MB, 65K elements — Font -> uchrCache" |
| `large_string` | Individual large strings (> 100 KB) | "210 KB — structure -> raw (email body)" |

### Low Severity

| Kind | Description | Example |
|---|---|---|
| `micro_cycle` | Two-node circular references | "1,802 OptionsResolver <-> Closure micro-cycles" |
| `dedup_candidate` | Same-size objects that may be shareable | "dispatcher: 1,801 copies x 88B = 155 KB wasted" |

### Info

| Kind | Description |
|---|---|
| `overview` | Heap total/usage, VM stack, compiler arena, analyzed percentage |
| `shared_singleton` | Many references to one target (normal singleton pattern) |
| `root_blame` | Memory attributed to each root branch (call_frames, class_table, etc.) |
| `retained_exact` | No cycles — retained size computation is exact |
| `retained_approximate` | Cycles exist — retained size is approximate |

### Warning

| Kind | Description |
|---|---|
| `coverage_gap` | Less than 95% of heap analyzed — unaccounted memory |

## Analysis Phases

The report generator adapts its analysis depth based on snapshot size:

| Snapshot size | Phases run | Strategy |
|---|---|---|
| Any size | Phase 1 (Passes 1-6) | Summary + SQL: overview, types, classes, companions, dynamic properties, per-property |
| < 500K nodes | Phase 2 (Passes 7-11) | SQL-heavy: cycles (SCC), top arrays/strings, shared refs, structural dedup |
| < 500K edges | Phase 3 (Passes 12-15) | Full graph: drill-down, choke points, blame allocation, retained size confidence |

For very large snapshots (> 500K nodes), only the lightweight summary-based passes run, avoiding graph loading that would consume too much memory.

## Tips

- **Start with the SQLite workflow**: Capture once, analyze many times. The `inspector:memory:report` command is fast on an existing database.
- **Use JSON for CI/automation**: The `report-json` format is designed for programmatic consumption — pipe it to `jq`, feed it to an LLM, or integrate into monitoring.
- **Check `replay_query`**: Many findings include the SQL query that produced them. Run it directly on the SQLite database to explore further.
- **Look at `impact_bytes`**: Findings are not all equal. Focus on findings with the largest `impact_bytes` for the biggest memory savings.
- **Companion pairs**: When two classes have nearly identical instance counts, reducing one will likely reduce the other. Find the owner relationship to understand which one drives the allocation.
