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

## Round 4: objects_store First (commit `f0f5388`)

Collection order: included_files → interned_strings → **objects_store** →
function_table → class_table → global_variables → global_constants →
global_callbacks → modules → call_frames.

Same as Round 2 but with objects_store moved before global_variables.

| Phase | Heap | RSS | seen | sentinels |
|---|---|---|---|---|
| after_interned_strings | 21 MB | 62 MB | 9,305 | 9,279 |
| **after_objects_store** | **NEVER REACHED** | | | |

**OOM at MemoryDumpReaderFactory.php:84 during `collectObjectsStore()`.**
RSS: ~19 MB/s growth, OOM at ~530s.

Same result as Round 3 — objects_store itself OOMs regardless of inner-loop
streaming, because `collectZendObject` → `collectZval` recursively follows
cross-object references.

## Key Finding

**Whichever phase first walks the full object graph blows up.**

| Scenario | First big phase | OOM phase | Time to OOM |
|---|---|---|---|
| v3 (original order) | call_frames | call_frames | ~540s |
| v4a (reorder + inner-loop) | global_variables | global_variables | ~420s |
| v4b (reorder only) | objects_store | objects_store | ~570s |
| v5 (objects_store first) | objects_store | objects_store | ~530s |
| v6 (shallow objects_store, bug) | class_table | class_table | ~480s |
| v7 (fixed defer scope) | objects_store | objects_store | ~1380s |

The reli self-dump has millions of objects interconnected through the running
reli-prof process's object graph. **Any phase that first touches this graph**
recursively expands it via `collectZval()`, creating millions of Context objects.

## Round 5: Shallow objects_store (commit `fce528f`)

New optimization: `defer_unseen_objects` flag during objects_store collection.
When a property references an unseen object, skip recursion and record a
deferred edge. After all objects collected, resolve deferred edges via sentinels.

| Phase | Heap | RSS | seen | sentinels |
|---|---|---|---|---|
| after_interned_strings | 21 MB | 62 MB | 9,305 | 9,279 |
| **after_objects_store** | **21 MB** | **62 MB** | **9,306** | **9,279** |
| after_function_table | 21 MB | 63 MB | 13,062 | 12,010 |
| after_class_table | 28 MB | 81 MB | 64,580 | 51,411 |
| **after_global_variables** | **NEVER REACHED** → OOM | | | |

**objects_store passes (+0 MB heap, +1 seen).** But only 1 new address seen.

### Bug: top-level bucket objects are also deferred

`collectObjectsStore` calls `collectZendObjectPointer` for each bucket.
But `defer_unseen_objects` causes ALL unseen objects to return null —
including the bucket's own top-level objects, not just property references.

The fix: defer should only apply to property-level cross-references
(inside `collectZendObject` → `collectZval` → `collectZendObjectPointer`),
not at the `collectObjectsStore` bucket level. Either:
- Set `defer_unseen_objects = true` only inside `collectZendObject` (not around the bucket loop)
- Or have `collectObjectsStore` call `collectZendObject` directly (bypassing the pointer check)

## Round 6: Fixed defer scope (commit `bd816b6`)

Defer flag now set inside `collectZendObject` (not `collectObjectsStore`),
so bucket top-level objects are collected but property→object refs are deferred.

| Phase | Heap | RSS | Result |
|---|---|---|---|
| after_interned_strings | 21 MB | 62 MB | seen=9,305 sent=9,279 def=0 |
| **after_objects_store** | **NEVER REACHED** → OOM | | |

**OOM at `ZendArrayMemoryLocation.php:25` during objects_store.** ~10 GB.

RSS progression: ~4.7 GB at 12min → ~8.2 GB at 15min → ~9.9 GB at 22min → OOM.
Slower growth than v3 (~5 MB/s vs 18 MB/s) but still OOMs.

### Root cause: arrays are not deferred

`defer_unseen_objects` only skips object→object references. But:
- Object property → **array** → (collected fully, including all elements)
- Array element → **object** → deferred (good)
- Array element → **array** → collected fully (bad)

A single object with a large `array` property triggers full array collection.
reli-prof objects like `MemoryLocations` hold `$memory_locations` (millions
of entries) and `ContextPools` hold arrays with thousands of objects.

The array collection creates MemoryLocation + Context objects for every element,
even though the element objects themselves are deferred. At millions of array
entries, the MemoryLocation objects alone exhaust memory.

### Needed: defer arrays too, or skip property values entirely

Options:
1. **Defer arrays** the same way objects are deferred (shallow objects_store
   should only record the object's own memory, not its property values)
2. **Don't collect property values at all** during objects_store — just record
   the object header + property table as MemoryLocations, skip collectZval
   entirely for property values. Property→value edges are deferred.
3. **Two-pass**: objects_store first pass collects only addresses/sizes.
   Second pass (or later phases) fills in the property edges.

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
