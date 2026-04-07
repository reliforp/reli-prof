# Iterative Collector Design

## Problem

The current `MemoryLocationsCollector` uses recursive DFS via `collect*` methods.
When processing a deep object graph, all ancestor nodes' FFI buffers, PHP wrapper
objects, iterators, Context objects, and Location objects remain on the call stack
until all descendants are processed. For deep graphs (DI containers, nested data
structures), this causes high memory consumption proportional to depth.

## Goal

Replace the recursive `collect*` call chain with an iterative job queue where:
- **Only one node is fully materialized at a time** (FFI buffer + PHP wrapper)
- **Iterators are lightweight state** (base address + current index), not live PHP generators holding parent references
- **Job queue size = graph depth** (not breadth)
- **Context objects are short-lived**: created, emitted, immediately released

## Architecture

### Job Queue

```
while ($queue is not empty) {
    $job = $queue->pop();
    $job->execute($collector, $queue, $sink);
}
```

Each job represents one unit of work. When executed, a job may:
1. Read data from the target process (`deref`)
2. Emit a node to the sink
3. Record address→node_id for dedup
4. Push new jobs for children

### Job Types

```php
interface CollectorJob {
    public function execute(
        CollectorContext $ctx,  // dereferencer, sink, address_map, etc.
        JobQueue $queue,
    ): void;
}
```

#### Leaf jobs (emit immediately, no children)

- `EmitStringJob(address)` → deref ZendString, emit StringContext + location
- `EmitScalarJob(zval)` → emit ValueContext for int/float/bool/null

#### Container jobs (emit self, enqueue iterator for children)

- `EmitObjectJob(address)` → deref ZendObject, emit ObjectContext + location,
  push `ObjectPropertiesIteratorJob` + `ClosureJob` / `GeneratorJob` / etc.
- `EmitArrayJob(address)` → deref ZendArray, emit ArrayHeaderContext + location,
  push `ArrayElementsIteratorJob`

#### Iterator jobs (process one child, re-enqueue self if more remain)

- `ObjectPropertiesIteratorJob(arData_address, index, count, parent_node_id)`
  → read property at current index, push `ResolveZvalJob` for the value,
  advance index, re-push self if more properties remain
- `ArrayElementsIteratorJob(arData_address, index, count, parent_node_id)`
  → same pattern for array elements
- `DynamicPropertiesJob(properties_array_address, parent_node_id)`
  → push `EmitArrayJob` for the properties array

#### Dispatch job (reads zval type, pushes the right emit job)

- `ResolveZvalJob(zval_address, parent_node_id, link_name)`
  → deref the zval, check type, push the appropriate emit job:
  - IS_OBJECT → `EmitObjectJob`
  - IS_ARRAY → `EmitArrayJob`
  - IS_STRING → `EmitStringJob`
  - IS_REFERENCE → deref reference, re-push `ResolveZvalJob` for inner zval
  - IS_LONG/IS_DOUBLE/... → `EmitScalarJob`

### Dedup

Before any Emit job processes, it checks:
```php
if (isset($ctx->address_map[$address])) {
    $ctx->sink->emitReference($ctx->address_map[$address], $parent_node_id, $link_name);
    return;  // Already emitted — just add reference edge
}
```

After emitting:
```php
$ctx->address_map[$address] = $node_id;
```

### Iterator Job Pattern (key design decision)

For a 30000-element array, we do NOT enqueue 30000 jobs upfront.
Instead, one `ArrayElementsIteratorJob` processes **one element per tick**:

```php
class ArrayElementsIteratorJob implements CollectorJob {
    public function __construct(
        private int $arData_address,
        private int $index,
        private int $count,
        private int $parent_node_id,
    ) {}

    public function execute(CollectorContext $ctx, JobQueue $queue): void {
        if ($this->index >= $this->count) {
            return;
        }
        
        // Read one element from arData at current index
        [$key, $zval_address] = $ctx->readArrayElement(
            $this->arData_address, $this->index
        );
        
        // Push job to process this element's value
        $queue->push(new ResolveZvalJob(
            $zval_address, $this->parent_node_id, (string)$key
        ));
        
        // Re-push self for next element
        $queue->push(new self(
            $this->arData_address,
            $this->index + 1,
            $this->count,
            $this->parent_node_id,
        ));
    }
}
```

Queue state for a 30000-element array at depth 2:
```
[ArrayElementsIteratorJob(depth=0, index=5, count=100),    ← 100-element parent array
 ArrayElementsIteratorJob(depth=1, index=3, count=30000)]  ← 30000-element child
```
Only 2 jobs on the queue — not 30000.

### Memory Profile

At any point, memory holds:
- Job queue: O(depth) entries, each ~100 bytes → for depth 50: ~5 KB
- Address map: O(nodes) × 16 bytes → 1M nodes: 16 MB
- Currently executing job's FFI buffer + PHP wrapper: ~1 KB
- One Context + Location being emitted: ~500 bytes

Total: ~16 MB for the address map + negligible overhead.
Compare to current recursive version: O(depth) × (FFI buffer + wrapper + iterator + Context + Location) per level.

### Queue Order (DFS vs BFS)

Use LIFO (stack) for DFS behavior. The iterator re-push goes BEFORE child value jobs:

```php
// In ArrayElementsIteratorJob::execute:
$queue->push(new ResolveZvalJob(...));     // child value (pushed first, popped last)
$queue->push(new self(index + 1, ...));    // next sibling (pushed last, popped first)
```

Wait — this gives BFS within siblings. For DFS:
```php
$queue->push(new self(index + 1, ...));    // next sibling (pushed first = processed later)
$queue->push(new ResolveZvalJob(...));     // child value (pushed last = processed next)
```

This ensures depth-first: process child's entire subtree before moving to next sibling.

### Traversal Order (Root Branches)

Initial queue seeding in `collectAll`:
```php
// Push in reverse priority order (LIFO: first pushed = last processed)
$queue->push(new EmitObjectsStoreJob(...));      // last: weak edges
$queue->push(new EmitInternedStringsJob(...));
$queue->push(new EmitIncludedFilesJob(...));
$queue->push(new EmitModulesJob(...));
$queue->push(new EmitGlobalCallbacksJob(...));
$queue->push(new EmitGlobalConstantsJob(...));
$queue->push(new EmitClassTableJob(...));
$queue->push(new EmitFunctionTableJob(...));
$queue->push(new EmitGlobalVariablesJob(...));
$queue->push(new EmitCallFramesJob(...));         // first: app-level paths
```

### CollectorContext

Shared state passed to all jobs:

```php
class CollectorContext {
    public Dereferencer $dereferencer;
    public ZendTypeReader $zend_type_reader;
    public ContextTreeSink $sink;
    public ContextAnalyzer $analyzer;
    public WeakMap $memo;
    public array $address_map;        // int address → int node_id
    public MemoryLocations $memory_locations;  // for region classification
    public int $map_ptr_base;
}
```

### Relation to Existing Code

- `ContextAnalyzer` / `PdoContextTreeSink` / `ArrayContextTreeSink`: unchanged
- `ReferenceContext` classes (`ObjectContext`, etc.): still used as short-lived containers for emit
- `MemoryLocation` classes: still used for location tracking
- Pool classes: can be removed or kept as simple factories (no caching/sentinel)
- `emitChildIfStreaming` / `registerParentIfStreaming` / `flushPoolsIfStreaming`: replaced by job queue
- `collectZval` / `collectZendObject` / etc.: replaced by job classes

### Migration Path

1. Implement `CollectorJob` interface and `JobQueue`
2. Implement `CollectorContext`
3. Convert `collectAll` top-level to job queue loop
4. Convert each `collect*` method to a Job class, one at a time
5. Remove old `collect*` methods as each is converted
6. Remove `ContextPools`, pool classes, `flushPoolsIfStreaming`, etc.

### Special Cases

- **PDO internal tracking**: PDO/PDOStatement memory locations follow extension-internal
  pointers. These can be handled as sub-jobs of `EmitObjectJob` (after emitting the
  ObjectContext, push `PdoDbhJob` or `PdoStmtJob` if class matches).
- **Closures/Generators/Fibers**: After `EmitObjectJob` emits the base ObjectContext,
  push `ClosureJob`/`GeneratorJob`/`FiberJob` to collect internal structures.
- **Memory limit violation**: `collectRealCallStackOnMemoryLimitViolation` can be a
  post-processing job that modifies the call_frames branch.
- **Edge strength**: `objects_store` root uses `EdgeStrength::Weak`. Job carries
  edge strength in its parameters.

### Risks

- **Complexity**: Many job classes instead of a few collect methods.
  Mitigated by keeping jobs small and focused (each ~20-30 lines).
- **Performance**: Job object allocation overhead per node.
  Each job is a small PHP object (~100 bytes). For 2M nodes this is ~200 MB
  of allocations but they're short-lived (GC'd after execute). The address_map
  is the long-lived structure.
  Actually, jobs are processed immediately and only O(depth) exist simultaneously,
  so allocation pressure is low.
- **Property iterator state**: ZendArray's `arData` is a pointer to the target
  process memory. Reading element N requires: base + N * bucket_size. This is a
  simple offset calculation, no need to hold a PHP iterator object.
