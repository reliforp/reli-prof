# Memory Analysis Report

The memory profiler can automatically analyze a snapshot and produce a prioritized report of findings — not raw tables and numbers, but actionable conclusions about what is consuming memory, why, and what to investigate next.

## Quick Start

### From a captured snapshot (recommended)

Capture a snapshot once, then run the report against it as many times
as you like. `.rmem` is the fastest format and what every analyser
reads natively; `-f sqlite3` is also supported if you want to query
the same file with SQL tools.

```bash
# .rmem (recommended)
sudo ./reli inspector:memory -p <pid> -f binary -o snapshot.rmem
./reli inspector:memory:report snapshot.rmem

# SQLite (works identically here; useful if you also want SQL access)
sudo ./reli inspector:memory -p <pid> -f sqlite3 -o snapshot.db
./reli inspector:memory:report snapshot.db
```

### Inline with live capture

Generate the report directly from a live process (captures internally):

```bash
sudo ./reli inspector:memory -p <pid> -f report
```

## Output Formats

### Human-readable text (`report`)

```bash
./reli inspector:memory:report snapshot.rmem
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

=== Type Breakdown ===

  Type                            Count       Memory       %
  ----------------------------------------------------------------
  ZendObject                     14,200      20.00 MB    83.3%
  ZendString                      3,500       2.00 MB     8.3%
  ZendArray                       1,200       1.50 MB     6.3%
  ZendReference                     100      12.00 KB     0.0%

=== Top Classes by Memory ===

    #  Class                                      Count   Avg Size       Memory       %
  ---------------------------------------------------------------------------------------
    1  App\Models\User                            10,000      72 B   703.13 KB    98.2%
    2  App\Cache\Item                                100     640 B    62.50 KB     0.9%
    3  Webklex\PHPIMAP\Message                       200     256 B    50.00 KB     0.7%

=== Top Arrays ===

    #      Retained        Table    Elems  Path
  --------------------------------------------------------------------------------
    1     15.30 MB   160.00 KB   10,000  <main>:28::$users
    2      2.00 MB    64.00 KB    1,200  $GLOBALS->container->cache

=== Top Strings ===

    #          Size  Path                            Preview
  ------------------------------------------------------------------------------------------
    1   205.88 KB  ...messages[0]->structure->raw   Content-Type: multipart/mixed; boun...
    2   102.40 KB  App\Logger->$buffer              [2026-01-15 12:00:00] app.INFO: Sta...

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
./reli inspector:memory:report snapshot.rmem -f report-json
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

Generate a report from an existing snapshot file (binary `.rmem` or
SQLite `.db`/`.sqlite`):

```
Usage:
  inspector:memory:report [options] <snapshot-file>

Arguments:
  snapshot-file                          Path to the analysis file (binary .rmem or SQLite .db/.sqlite)

Options:
  -f, --output-format=FORMAT                Output format: report (text) or report-json [default: report]
  -o, --output=PATH                         Output file path (default: stdout)
      --run-id=ID                           Run ID to analyze [default: 1]
      --pretty-print|--no-pretty-print      Pretty print JSON output (default: on)
      --full-analysis|--no-full-analysis    Run all passes (default: on; --no-full-analysis for large snapshots)
      --memory-limit=LIMIT                  Set PHP memory_limit (e.g. 2G, 512M)
      --ffi-csr|--no-ffi-csr                Force FFI CSR graph substrate on/off (default: auto)
      --link-cache=MODE                     Tree-edge link cache: auto (default) | eager | lazy
      --substrate-bulk-fetch-chunk=N        Rows per chunked fetchAll when loading the SQLite substrate [default: 200000]
      --report-workers=N                    Parallel workers for Phase 3 passes (forks via pcntl_fork; falls back to sequential without ext-pcntl) [default: 1]
      --mmap-size=BYTES                     SQLite mmap_size for the read connection; suffix-aware (K/M/G), 0 disables [default: 2G]
      --prefetch|--no-prefetch              posix_fadvise(POSIX_FADV_WILLNEED) the DB file before opening so SQLite hits a warm cache (default: on)
      --no-derived-cache                    Skip the .rmem.derived sidecar cache (subtree sizes, SCC)
      --rebuild-derived-cache               Ignore an existing .rmem.derived sidecar and recompute + rewrite it
```

The perf-tuning flags below the basics matter when reports either OOM or run slowly on large snapshots; see [§ Tips](#tips) for which knobs to reach for first.

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
| `zendmm_cache_bloat` | ZendMM caches more chunks than it's currently using | "holding 8 cached chunks (16.00 MB) vs 2 in-use" |
| `zendmm_chunks_pinned_by_fragmentation` | ≥4 in-use chunks ≥90% empty but can't be returned to OS | "5 in-use chunks ≥90% empty but cannot be returned to the OS" |
| `zendmm_heap_fragmentation_high` | Scattered free-page space exceeds analyzed heap usage | "10.00 MB free inside in-use chunks vs 3.00 MB analyzed" |

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
| `type_ranking` | Memory breakdown by allocation type (always shown as table) |
| `class_ranking` | Top 20 classes by memory usage (always shown as table) |
| `root_blame` | Memory attributed to each root branch |
| `retained_exact` | No cycles — retained size is exact |
| `retained_approximate` | Cycles exist — retained size is approximate |
| `zendmm_chunk_heuristic_state` | Current ZendMM chunk counters and chunk-delete heuristic state |
| `zendmm_chunk_fragmentation_state` | Scattered free-page bytes and "mostly empty" chunk count |

### Warning

| Kind | Description |
|---|---|
| `coverage_gap` | Less than 95% of heap analyzed — unaccounted memory |
| `near_memory_limit` | Peak usage ≥80% (warning) or ≥95% (high) of `memory_limit` |
| `zendmm_cache_expansion_imminent` | `last_chunks_delete_count` near the threshold — `cached_chunks_max` is about to grow |
| `zendmm_chunks_pinned_by_fragmentation` | 1-3 in-use chunks ≥90% empty but pinned (Medium from 4 chunks) |

### ZendMM Chunk Findings

`zendmm_*` findings surface the two distinct ways a PHP process can hold on to
memory without the userland heap (`memory_get_usage()`) reflecting it:

- **Cache bloat** (`zendmm_cache_bloat`, `zendmm_cache_expansion_imminent`).
  ZendMM keeps a cache of freed 2 MB chunks to avoid thrashing the OS
  allocator, and grows `cached_chunks_max` when `last_chunks_delete_count`
  reaches 4 at the same boundary. Long-lived CLI / worker processes that cycle
  through bursty workloads can accumulate cached chunks that stay resident
  until shutdown. Mitigation: call `gc_mem_caches()` between jobs.
- **Fragmentation pin-up** (`zendmm_chunks_pinned_by_fragmentation`,
  `zendmm_heap_fragmentation_high`). A chunk is only returned to the OS when
  every page inside it is free, so one long-lived allocation (interned string,
  persistent class entry, surviving HashTable bucket) can hold an otherwise
  empty 2 MB hostage. `gc_mem_caches()` does **not** help here because these
  chunks are still in the in-use list. Mitigation: recycle long-lived workers
  after heavy transient allocations.

The `zendmm_chunk_heuristic_state` and `zendmm_chunk_fragmentation_state` info
findings always report the raw counters (`chunks_count`, `peak_chunks_count`,
`cached_chunks_count`, `last_chunks_delete_boundary/count`,
`chunks_total_free_bytes`, `chunks_mostly_empty_count`) so you can see the
underlying numbers even when no warning fires.

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

- **Start with the captured-snapshot workflow**: Capture once, analyze many times. The `inspector:memory:report` command is fast on an existing `.rmem` (or `.db`) file.
- **Use `--memory-limit=2G`** for large snapshots instead of `php -d memory_limit=2G`.
- **Use JSON for CI/automation**: The `report-json` format is designed for programmatic consumption — pipe it to `jq`, feed it to an LLM, or integrate into monitoring.
- **Findings are sorted**: HIGH severity and largest impact appear first. Focus on the top findings.
- **Check `impact_bytes`**: Findings are not all equal. Focus on findings with the largest `impact_bytes` for the biggest memory savings.
- **Class names are FQCN**: All class names use fully qualified names for unambiguous identification.
- **Paths use PHP syntax**: `<main>:28::$messages[0]->structure->raw` instead of raw context tree paths.
- **Companion clusters**: When classes have matching counts, reducing one reduces the others. The `ownership_pattern` finding shows the actual parent-child relationship.
- **Property scaling**: The `property_scaling` finding shows which properties scale with instance count (per-instance) vs which are shared (CoW). Per-instance properties with small values may benefit from lazy initialization.
- **Compare snapshots**: Use `inspector:memory:compare` to diff two snapshots and find regressions or verify fixes.

## Comparing Two Snapshots

The `inspector:memory:compare` command diffs two memory snapshot files
(binary `.rmem` or SQLite `.db`/`.sqlite`) and highlights what changed.

### Quick Start

```bash
# Capture baseline (.rmem is the fastest format and is what every analyser reads natively)
sudo ./reli inspector:memory -p <pid> -f binary -o before.rmem

# ... deploy code change, trigger workload, etc.

# Capture target
sudo ./reli inspector:memory -p <pid> -f binary -o after.rmem

# Compare two files
./reli inspector:memory:compare before.rmem after.rmem

# Compare run IDs within the same file (SQLite only — multi-run support
# requires the relational schema)
./reli inspector:memory:compare snapshot.db --run-id-baseline 1 --run-id-target 2
```

### Example Output

```
==============================================================================
 reli-prof Memory Comparison Report
==============================================================================

  Baseline: 2026-04-01 10:00:00
  Target:   2026-04-01 14:00:00

=== Summary Delta ===

  Metric                              Baseline         Target          Delta
  ---------------------------------------------------------------------------------------
  memory_get_usage()                 24.50 MB         31.20 MB       +6.70 MB (+27.3%)
  memory_get_usage(true)             28.00 MB         34.00 MB       +6.00 MB (+21.4%)
  Heap usage                         23.80 MB         30.50 MB       +6.70 MB (+28.2%)
  Analyzed                             98.2%            97.5%          -0.7pt

=== Type Breakdown Delta ===

  Type                           Count Δ     Baseline       Target      Memory Δ
  ----------------------------------------------------------------------------------
  ZendObject                      +4050    20.00 MB     26.40 MB      +6.40 MB
  ZendString                       +100   800.00 KB    810.00 KB     +10.00 KB
  ZendArray                         +20     2.00 MB      2.03 MB     +32.00 KB

=== Class Memory Changes ===

  Class                                     Count Δ      Memory Δ  Target Memory
  ----------------------------------------------------------------------------------
  App\Entity\User                            +4000       +6.40 MB       6.91 MB
  App\Cache\Item                               +20      +32.00 KB      64.00 KB

  New classes (target only):
    App\NewFeature\Handler                         50 instances  800.00 KB

  Removed classes (baseline only):
    App\Legacy\OldHandler                          10 instances  160.00 KB

=== Findings Diff ===

  New findings:
    + [HIGH] 6.40 MB: property_scaling: App\Entity\User — 4000 instances

  Resolved findings:
    - [MEDIUM] 320.00 KB: cycle_cluster: App\Legacy\OldHandler cycle

  Changed findings:
    near_memory_limit: severity warning → high, impact 8.00 MB → 2.00 MB

==============================================================================
```

### Command Reference

```
Usage:
  inspector:memory:compare [options] <baseline> [<target>]

Arguments:
  baseline                               Path to the baseline snapshot (binary .rmem or SQLite .db/.sqlite)
  target                                 Path to the target snapshot (binary .rmem or SQLite .db/.sqlite)
                                         (omit to compare run IDs within the same SQLite file)

Options:
  -f, --output-format=FORMAT             Output format: report (text) or report-json [default: report]
  -o, --output=PATH                      Output file path (default: stdout)
      --run-id-baseline=ID               Run ID for baseline [default: 1]
      --run-id-target=ID                 Run ID for target [default: 1]
      --threshold=PERCENT                Minimum change percentage to report [default: 0]
      --pretty-print|--no-pretty-print   Pretty print JSON output (default: on)
      --full-analysis|--no-full-analysis Run all analysis passes for both snapshots (default: off)
      --diff-nodes=SIDE                  Show sample nodes present only in one side: baseline-only | target-only (binary .rmem only)
      --diff-limit=N                     Max samples to show for --diff-nodes [default: 20]
      --memory-limit=LIMIT               Set PHP memory_limit (e.g. 2G, 512M)
```

### JSON Output

```bash
./reli inspector:memory:compare before.rmem after.rmem -f report-json -o diff.json
```

```json
{
  "baseline": { "captured_at": "2026-04-01T10:00:00Z", "node_count": 50000 },
  "target": { "captured_at": "2026-04-01T14:00:00Z", "node_count": 80000 },
  "summary_deltas": [
    { "metric": "memory_get_usage", "baseline": 25690112, "target": 32717824, "delta": 7027712, "delta_percent": 27.3 }
  ],
  "type_deltas": [
    { "type": "ZendObjectMemoryLocation", "baseline_count": 10000, "target_count": 14050, "baseline_memory": 20971520, "target_memory": 27682816, "count_delta": 4050, "memory_delta": 6711296 }
  ],
  "class_deltas": {
    "changed": [
      { "class_name": "App\\Entity\\User", "baseline_count": 100, "target_count": 4100, "baseline_memory": 25600, "target_memory": 6578200, "count_delta": 4000, "memory_delta": 6552600 }
    ],
    "added": [],
    "removed": []
  },
  "findings_diff": {
    "new": [ { "kind": "property_scaling", "severity": "high", "..." : "..." } ],
    "resolved": [ { "kind": "cycle_cluster", "severity": "medium", "..." : "..." } ],
    "changed": [
      { "baseline": { "kind": "near_memory_limit", "severity": "warning" }, "target": { "kind": "near_memory_limit", "severity": "high" } }
    ]
  }
}
```

### Threshold Filtering

Use `--threshold` to suppress small changes (useful for noisy environments):

```bash
# Only show changes >= 5%
./reli inspector:memory:compare before.rmem after.rmem --threshold 5
```

This filters summary metric deltas, type breakdown deltas, and class memory changes below the given percentage.

### What Gets Compared

| Dimension | Source | Scope |
|---|---|---|
| Summary metrics | `summary` table | All metrics (memory_get_usage, heap, VM stack, etc.) |
| Type breakdown | `location_types_summary` table | All location types |
| Class memory | `class_objects_summary` table | All classes (not limited to top 20) |
| Findings | `ReportGenerator` output | Actionable findings (large arrays/strings, cycles, dominant classes, etc.) |

Large arrays and strings are compared via their findings (matched by `owner_path`). If the same array at the same path grows or shrinks, it appears in the "Changed findings" section.
