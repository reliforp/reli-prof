# Self-Dump Memory Investigation

reli-prof analyzing its own 5.6 GB memory dump OOMs at ~10 GB.
This document records the instrumented measurement results.

## Setup

- Dump file: 5.6 GB reli self-dump (`/tmp/reli_self.dump`)
- PHP memory_limit: 10 GB
- Output format: sqlite3 (streaming mode with sink)

## Round 1: Baseline (commit `716445c`)

Optimizations active: lazy dump loading, streaming emission, sentinel contexts,
lightweight MemoryLocations (address-only seen set), pool flush after sub-tree emission.

Collection order: included_files → interned_strings → global_variables →
call_frames → function_table → class_table → global_constants → global_callbacks →
modules → objects_store.

### Phase-by-Phase Memory

| Phase | Heap | RSS | seen | sentinels |
|---|---|---|---|---|
| start | 10 MB | 49 MB | - | - |
| after_setup_chunks_vm | 19 MB | 56 MB | - | - |
| after_included_files | 19 MB | 57 MB | 303 | 280 |
| after_interned_strings | 31 MB | 69 MB | 9,305 | 8,657 |
| after_global_variables | 31 MB | 71 MB | 11,082 | 10,104 |
| **after_call_frames** | **NEVER REACHED** | | | |

**OOM at Zval.php:49 during `collectCallFrames()`.**

### RSS Progression During call_frames

| Time (from start) | RSS |
|---|---|
| ~30s | 71 MB (just entered call_frames) |
| ~90s | ~3,022 MB |
| ~210s | ~5,333 MB |
| ~330s | ~7,020 MB |
| ~480s | ~9,279 MB |
| ~540s | 10,737 MB → **OOM** |

Growth rate: ~18 MB/s sustained.
call_frames accounts for **99.3%+** of memory consumption.

## Round 2: Reorder + Inner-Loop Streaming (commit `a129ea1`)

New optimizations:
- Inner-loop streaming (`0260fae`): each array element, object property,
  and call frame variable emitted individually as sub-tree
- Collection reorder (`a129ea1`): objects_store, function_table, class_table
  moved before call_frames (but global_variables stays before objects_store)

Collection order: included_files → interned_strings → **global_variables** →
objects_store → function_table → class_table → global_constants → global_callbacks →
modules → call_frames.

### Phase-by-Phase Memory

| Phase | Heap | RSS | seen | sentinels |
|---|---|---|---|---|
| start | 10 MB | 49 MB | - | - |
| before_global_variables | 21 MB | 62 MB | 9,305 | 9,279 |
| **after_global_variables** | **NEVER REACHED** | | | |

**OOM at MemoryDumpReaderFactory.php:84 during `collectGlobalVariables()`.**

### RSS Progression During global_variables

| Time (from start) | RSS |
|---|---|
| ~30s | 748 MB |
| ~120s | 2,945 MB |
| ~240s | 5,758 MB |
| ~360s | 8,381 MB |
| ~420s | 9,701 MB → OOM |

Growth rate: ~22 MB/s sustained.

## Round 3: Reorder Only, No Inner-Loop (manual test)

Collection order: included_files → interned_strings → **objects_store** →
function_table → class_table → global_constants → global_callbacks → modules →
global_variables → call_frames.

| Phase | Heap | RSS |
|---|---|---|
| after_interned_strings | 31 MB | 70 MB |
| **after_objects_store** | **NEVER REACHED** | |

**OOM at Zval.php:49 during `collectObjectsStore()`.**
RSS: ~18 MB/s growth, OOM at 570s.

## Key Finding

**Whichever phase first walks the full object graph blows up.**

| Scenario | First big phase | OOM phase | Time to OOM |
|---|---|---|---|
| v3 (original order) | call_frames | call_frames | ~540s |
| v4a (reorder + inner-loop) | global_variables | global_variables | ~420s |
| v4b (reorder only) | objects_store | objects_store | ~570s |

The reli self-dump has millions of objects interconnected through the running
reli-prof process's object graph. **Any phase that first touches this graph**
recursively expands it via `collectZval()`, creating millions of Context objects.

Inner-loop streaming helps for breadth (emitting each array element / object
property individually), but **a single `collectZval()` call for one variable
that references a massive object** still recursively builds the entire subtree
before the element can be emitted.

## Root Cause: Recursive collectZval Expansion

```
$GLOBALS['container'] →
  collectZval($container) →
    collectZendObject(Container) →
      property 'services' →
        collectZval($services) →
          collectZendArray([1000 services]) →
            element[0] →
              collectZval(Service0) →
                collectZendObject(Service0) →
                  property 'dependency' → ... (millions of objects deep)
```

Even with inner-loop streaming at each level, the recursive call stack keeps
all intermediate Context objects alive until the deepest leaf returns.
The PHP call stack holds references to all ancestor frames' local variables.

## Potential Solutions

### 1. Iterative (stack-based) collection instead of recursive
Replace recursive `collectZval()` with an explicit stack/queue. Emit sub-trees
breadth-first or in bounded batches. This decouples memory lifetime from
PHP call stack depth.

### 2. Objects_store first + sentinel-aware collectZval
Move objects_store before everything else, AND make `collectObjectsStore` use
the iterative approach (since objects_store is flat: just iterate all buckets).
Each object is collected in isolation, emitted, sentinel-ized. Then all
subsequent phases (global_variables, call_frames) only hit sentinels.

The key insight: `collectObjectsStore` iterates a flat bucket array — it
doesn't need recursion. Each object can be collected independently with
inner-loop streaming on its properties. The problem is that `collectZendObject`
calls `collectZval` on each property, which recursively follows references
to OTHER objects — but those other objects are also in objects_store and
should be collected when their bucket is reached.

If `collectObjectsStore` processed objects WITHOUT following cross-object
references (just recording the object's own properties as addresses/sentinels),
each object would be O(properties) instead of O(reachable graph).

### 3. Depth limit on recursive traversal
Stop recursion at a configurable depth. Deeper objects get a placeholder
context. This trades accuracy for bounded memory.

### 4. Two-phase collection
Phase 1: Walk the entire graph, record only addresses (no Context objects).
Phase 2: For each address, create Context and emit immediately.
This separates traversal from Context construction.
