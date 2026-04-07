# Memory Analysis Optimization

## Problem

The memory analysis feature (`MemoryLocationsCollector`) had high memory
consumption in the profiler process itself. For large targets (e.g., 5.6 GB
PHP process), the profiler would OOM at ~8 GB before completing collection.

## Implemented Optimizations

### Streaming collection via SQLite intermediate (optimization 8)

**Status: Implemented**

When a `ContextTreeSink` is passed to `collectAll()`, the collector operates
in streaming mode:

1. Each top-level branch (objects_store, function_table, etc.) is emitted to
   a `PdoContextTreeSink` immediately after collection via `analyzeSingleLink()`
2. Within branches, inner loops (array elements, object properties, local
   variables) also emit per-iteration via `emitChildIfStreaming()`
3. After each emission, `flushPoolsIfStreaming()` converts pool entries to
   lightweight node_id placeholders and clears the pools

**Collection order** (streaming mode only):
```
included_files → interned_strings → objects_store → function_table →
class_table → global_variables → global_constants → global_callbacks →
modules → call_frames
```

objects_store is collected first so that subsequent phases hit cached node IDs
for most objects/arrays/strings instead of recursively expanding.

Non-streaming mode preserves the original 0.12.x collection order.

### Shallow object collection with deferred edges

**Status: Implemented**

During objects_store collection, `defer_unseen_objects` is enabled inside
`collectZendObject`. Property references to unseen objects, arrays, and PHP
references return null instead of recursing. A deferred edge is recorded:

```php
$this->deferred_object_edges[] = [parent_node_id, child_address, link_name, pointer_type];
```

After all phases complete, deferred edges are resolved:
1. If the child address already has a node_id → emit reference edge
2. Otherwise → collect the target with defer off and emit normally

Dynamic properties, closures, generators, fibers, and weak references/maps
are also skipped during defer mode to prevent bypassing the defer guard.

### Node-id based deduplication

**Status: Implemented**

`ContextPools` maintains a node-id map (`array<int, int>` address → node_id).
After each branch emission, `convertToSentinels()` extracts node_ids from the
analyzer's WeakMap and stores them. Later cache hits reuse the integer node_id
directly, and parent contexts keep only encoded ints for already-emitted
children rather than full child objects.

`ContextAnalyzer` recognizes these encoded node IDs and either emits a
reference edge or skips edge emission when the parent-child edge was already
materialized earlier.

### Lightweight MemoryLocations

**Status: Implemented**

In streaming mode, `MemoryLocations::createLightweight()` stores only
`array<int, true>` (seen set) instead of full MemoryLocation objects.
Type checks in collector cache-hit paths use direct pool address lookups
(`getContextByAddress()`) instead of `instanceof` on MemoryLocation objects.

### CachingDereferencer

**Status: Implemented (streaming mode only)**

`CachingDereferencer` wraps `RemoteProcessDereferencer` with a
`(type, address) → result` cache (max 4096 entries, LRU eviction).
Avoids repeated FFI allocation for the same class_entry when processing
many objects of the same class.

### Lazy-load memory dump regions

**Status: Implemented**

`MemoryDumpReaderFactory` builds a region index (address, size, file_offset)
without loading region data. The anonymous `MemoryReaderInterface` reads from
the dump file on demand via `fseek` + `fread`.

### Streaming JSON export

**Status: Implemented**

`StreamingJsonFromDbExporter` reads from SQLite and writes JSON via `fwrite`
in a DFS walk. `JsonMemoryOutput` supports `pre_populated_db` to skip the
in-memory tree walk entirely.

### Direct DB output for DB formats

**Status: Implemented**

`MemoryOutputFactory::createStreamingSink()` returns the output DB's driver
directly for sqlite3/mysql/postgresql formats, eliminating the temp file copy.
Non-DB formats (json, report) use a temp SQLite.

## Future Optimizations (not yet implemented)

### 1. Convert `$referencing_contexts` array to typed properties

For context classes with a fixed set of child link names, replace the generic
array with dedicated typed properties. Eliminates array bucket overhead.

### 2. Inline MemoryLocation into Context

For the 25 context classes with exactly one MemoryLocation, inline the fields
directly as context properties. Saves ~56 bytes per instance.

### 3. Truncate/lazy-load string values

`ZendStringMemoryLocation::$value` stores full string content. Could truncate
to first N bytes or omit entirely during collection.

### Deferred edge resolution optimization

The current deferred edge resolution at the end of collection can spike memory
when many unresolved targets need full collection. Could be batched with
intermediate flush cycles.
