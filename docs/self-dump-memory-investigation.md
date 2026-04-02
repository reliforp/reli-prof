# Self-Dump Memory Investigation

reli-prof analyzing its own 5.6 GB memory dump OOMs at ~10 GB.
This document records the instrumented measurement results.

## Setup

- Dump file: 5.6 GB reli self-dump (`/tmp/reli_self.dump`)
- Branch: `claude/optimize-memory-analysis-GchbQ` (commit `716445c`)
- PHP memory_limit: 10 GB
- Output format: sqlite3 (streaming mode with sink)
- Optimizations active: lazy dump loading, streaming emission, sentinel contexts, lightweight MemoryLocations (address-only seen set), pool flush after sub-tree emission

## Instrumentation

Added `memory_get_usage(true)`, `memory_get_usage(false)`, and `/proc/self/status` VmRSS logging at each phase boundary in `collectAll()`.

Also tracked `$seen` count (lightweight MemoryLocations), `memory_locations` count, and sentinel count (ContextPools).

## Results

### Phase-by-Phase Memory (collectAll)

| Phase | Heap | Used | RSS | seen | sentinels |
|---|---|---|---|---|---|
| start | 10 MB | 10 MB | 49 MB | - | - |
| after_setup_chunks_vm | 19 MB | 17 MB | 56 MB | - | - |
| after_included_files | 19 MB | 17 MB | 57 MB | 303 | 280 |
| after_interned_strings | 31 MB | 21 MB | 69 MB | 9,305 | 8,657 |
| after_global_variables | 31 MB | 22 MB | 71 MB | 11,082 | 10,104 |
| **after_call_frames** | **NEVER REACHED** | | | | |

OOM at Zval.php:49 during `collectCallFrames()`.

### RSS Progression During call_frames

Monitored via `/proc/PID/status` VmRSS while call_frames was collecting:

| Time (from start) | RSS |
|---|---|
| ~30s | 71 MB (just entered call_frames) |
| ~90s | ~3,022 MB |
| ~210s | ~5,333 MB |
| ~330s | ~7,020 MB |
| ~420s | ~8,323 MB |
| ~480s | ~9,279 MB |
| ~540s | 10,737 MB → **OOM** (Allowed memory size exhausted) |

Growth rate: ~18 MB/s sustained over 8+ minutes.

### Fatal Error

```
PHP Fatal error: Allowed memory size of 10737418240 bytes exhausted
  (tried to allocate 20480 bytes) in
  .../src/Lib/PhpInternals/Types/Zend/Zval.php on line 49
```

## Analysis

### Why call_frames is the bottleneck

1. **Deferred emission**: call_frames emission is deferred to after objects_store (to handle memory_limit_error replacement). This means the entire call_frames context tree stays in memory until the end.

2. **Recursive object graph**: Each call frame's `collectCallFrame()` calls `collectZval()` recursively, which follows object properties, array elements, etc. In the reli self-dump, the call stack variables reference the **entire** running reli-prof object graph (the dump reader, memory locations, context pools, etc.).

3. **Sub-tree emission works but not enough**: The streaming mode does emit each frame as a sub-tree and converts it to a SentinelContext. However, the recursive `collectZval` chain within a single frame creates many intermediate Context objects and MemoryLocation entries before the frame can be emitted.

4. **$seen array growth**: The lightweight MemoryLocations uses `array<int, true>` which at millions of entries (~64 bytes/entry in PHP HashTable) consumes hundreds of MB. After interned_strings there are ~9K entries, after global_variables ~11K. During call_frames this grows to millions as the entire object graph is traversed.

### No other phase comes close

| Phase | Memory delta (heap) | Notes |
|---|---|---|
| setup_chunks_vm | +9 MB | Chunk iteration |
| included_files | +0 MB | 303 entries, negligible |
| interned_strings | +12 MB | 9K strings |
| global_variables | +0 MB | Small symbol table |
| call_frames | **+9,700+ MB → OOM** | The entire problem |
| function_table | not reached | |
| class_table | not reached | |
| objects_store | not reached | |

call_frames accounts for **99.3%+** of memory consumption.

### Objects not yet reached

Notably, `objects_store` and `class_table` — which for a normal target would be significant — are never even reached. The call_frames phase alone exhausts 10 GB.

## Potential Solutions

### 1. Don't defer call_frames emission
If call_frames were emitted immediately (like other phases), the memory_limit_error replacement logic would need an alternative approach. The deferred emission forces the entire call_frames tree to stay in memory.

### 2. Finer-grained sub-tree emission within frames
Currently, each call frame is emitted as a sub-tree. But a single frame can reference millions of objects through its local variables. Breaking down variable-level emission (each local variable as a sub-tree) would bound memory per-variable instead of per-frame.

### 3. Limit recursion depth during collection
For self-analysis scenarios, the object graph reachable from the call stack is the dump reader itself. A depth limit or "already in objects_store" cutoff could prevent the recursive traversal from walking the entire graph.

### 4. Two-pass collection for call_frames
First pass: record addresses only (no Context objects). Second pass: collect and emit in chunks. This would separate the "what exists" question from the "build context tree" question.

### 5. Process call_frames after objects_store
If objects_store were collected first, most objects would already be in the sentinel map. call_frames collection would then get sentinel hits for most objects, avoiding the recursive expansion. This would require restructuring the memory_limit_error handling.
