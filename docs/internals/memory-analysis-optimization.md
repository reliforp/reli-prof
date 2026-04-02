# Memory Analysis Optimization Ideas

## Problem

The memory analysis feature (collecting and analyzing PHP process memory via
`MemoryLocationsCollector`) has high memory consumption in the profiler process
itself. The peak occurs at the end of the collection phase, when the full
`ReferenceContext` tree and all `MemoryLocations` are held in memory
simultaneously.

The memory footprint scales with the target process's complexity (number of
objects, arrays, strings, functions, classes, etc.), and the composition varies
per target, so optimizations must be broadly effective rather than targeting
specific context types.

## Architecture Overview

Key data structures at peak:

- **ReferenceContext tree**: 48 concrete context classes, each holding child
  references in `$referencing_contexts` (`array<string, ReferenceContext>`) via
  the `ReferenceContextDefault` trait.
- **MemoryLocations**: `array<int => MemoryLocation>` collecting all memory
  regions. 32 MemoryLocation subclasses.
- **ContextPools**: 6 pools (String, Array, Object, PhpReference, Resource,
  UserFunctionDefinition) holding strong references keyed by address for
  deduplication.

## Proposed Optimizations

### 1. Convert `$referencing_contexts` array to typed properties (high impact)

Many context classes have a fixed, statically known set of child link names.
For example, `ArrayHeaderContext` only ever has an `array_elements` link. The
current design stores these in a generic `array<string, ReferenceContext>`,
paying PHP array bucket overhead (~36 bytes) plus string key cost per entry.

**Approach**: For context classes with known child types, replace the
`$referencing_contexts` array with dedicated typed properties (e.g.,
`public ?ArrayElementsContext $array_elements = null`). Context classes with
truly dynamic children (e.g., `ArrayElementsContext` whose keys are runtime
array keys) would keep the array.

**Effect**: Eliminates array bucket + string key overhead per link. Applies
uniformly across all context types.

### 2. Inline MemoryLocation into Context (high impact)

Currently each Context that has a location holds a separate MemoryLocation
object. Every PHP object carries ~56 bytes of header overhead.

Survey of the 48 context classes:
- **21 classes** have no MemoryLocation (category A)
- **25 classes** have exactly one MemoryLocation (category B)
- **2 classes** have two MemoryLocations (category C: `DefinedFunctionsContext`,
  `OpArrayContext`)

**Approach**: For category B (the majority), inline the MemoryLocation's fields
(`address`, `size`, and subclass-specific fields like `refcount`, `type_info`,
`value`) directly as properties of the Context class. Introduce a
`LocatedReferenceContext` interface with a `getMemoryLocation()` method that
reconstructs a MemoryLocation on demand. Category C (only 2 classes) gets
individual treatment.

**Effect**: Saves ~56 bytes per Context instance (object header of the
eliminated MemoryLocation). Since the most numerous context types (String,
Array, Object) are all category B, savings scale with target process complexity.

### 3. Truncate/lazy-load string values (target-dependent impact)

`ZendStringMemoryLocation::$value` stores the full string content read from the
target process. For string-heavy applications this can be significant.

**Options**:
- Store only the first N bytes + length for display purposes.
- Omit the value entirely during collection and re-read from the target process
  on demand during analysis (requires the target process to still be alive).

**Effect**: Highly dependent on target process. Large impact for applications
with many large strings; minimal for others.

### 4. SoA (Structure of Arrays) for MemoryLocations collection (medium impact)

`MemoryLocations` stores `array<int => MemoryLocation>` — an associative array
of objects. Each entry incurs both array bucket overhead and object header
overhead.

**Approach**: Replace with parallel arrays (or `SplFixedArray` / FFI buffers)
for address, size, and type fields. Eliminates per-entry object headers.

**Effect**: Reduces overhead for the flat MemoryLocations collection. Less
impactful than optimizations 1-2 if the ReferenceContext tree dominates memory.

### 5. Post-collection pool disposal + streaming analysis (high impact on post-peak)

The ContextPools hold strong references to all pooled contexts. This prevents
GC even after `releaseLinks()` is called on the ReferenceContext tree.

**Approach**: After `collectAll()` completes, dispose of (or nullify) the
ContextPools entirely. Then during analysis, call `releaseLinks()` on
already-analyzed subtrees to allow GC.

**Important caveat**: This does NOT reduce peak memory (which occurs at the end
of collection). It only accelerates memory reclamation during the analysis
phase.

**Caveat on WeakReference alternative**: Using `WeakReference` in pools instead
of destroying them is safe in a two-phase (collect-then-analyze) design, since
the tree holds strong references during collection. However, it would break if
collection and analysis were interleaved — a shared context could be GC'd after
one parent releases it but before the collector links it from another parent.

### 6. Integer keys for `$referencing_contexts` (low-medium impact)

Where `$referencing_contexts` is retained (dynamic children), replace string
keys with integer constants or enum values. PHP optimizes integer-keyed arrays
as packed arrays with lower per-entry overhead (~32 bytes vs ~72 bytes for
string keys).

**Applicability**: Limited to contexts where link names can be mapped to a
finite set of integers. Not applicable to `ArrayElementsContext` where keys
are arbitrary runtime values.

### 7. Eliminate wrapper contexts for array elements / scalar values (high impact, high cost)

`ArrayElementContext` is an empty wrapper (no properties, no MemoryLocation)
created per array element, and `ScalarValueContext` is created per scalar zval.
For a 10,000-element integer array, this produces 20,000 Context objects.

**Possible approaches**:
- Remove `ArrayElementContext` and attach value contexts directly to
  `ArrayElementsContext`.
- Store scalar values as plain PHP values in a native array instead of wrapping
  them in `ScalarValueContext`.
- For associative keys, store key StringContexts in a separate structure.

**Effect**: Could eliminate the majority of Context objects for scalar-heavy
data (config arrays, DB result sets). Scalar arrays would drop from
`O(2n)` contexts to near zero.

**Trade-off**: Breaks the uniform "everything is a ReferenceContext tree"
design. Analysis/traversal code would need special cases for scalar storage,
reducing code clarity. The clean tree structure is a significant
maintainability asset — adding type-specific branching throughout the analyzer
is a real cost.

**Verdict**: Deferred. Consider only after optimizations 1-2 are applied and
profiled. If peak memory is still problematic, this is the next lever, but the
complexity cost is non-trivial.

### 8. DB-backed streaming collection (highest impact on peak, architectural change)

The current design builds the entire ReferenceContext tree in memory during
collection, then serializes it (e.g., to JSON) during analysis. Peak memory
occurs at the end of collection when the full tree is held in memory.

#### Key insight: contexts are opaque on pool cache hit

When a ContextPool returns a cached context (address already seen), the
collector does NOT read any properties from it. It only:
1. Returns it to the caller
2. Caller attaches it to a parent via `add()`

This means during collection, a cached context is used purely as an identity
token ("same node"). No data is extracted from it. All population (via `add()`
and property assignment) happens exclusively on the fresh path.

This makes it possible to replace the in-memory context with a database record
ID — the collector only needs to know "this address was already seen, here's
its ID" to record the edge.

#### Why DB enables what JSON cannot

The collector traverses via DFS. With JSON output, the entire tree must be
held in memory until the final `json_encode`. With a database:

```
visit node → INSERT into DB → recurse into children → return → discard node
```

Memory held at any point is proportional to the **depth** of the DFS stack,
not the total number of nodes. Sibling subtrees that have already been written
to the DB can be GC'd immediately.

```
        Root
       / | \
      A   B   C      ← while traversing B, A is already in DB and GC'd
     /\   |
    D  E  F          ← while traversing F, D and E are GC'd
```

For a typical PHP process, tree depth is O(tens~hundreds) while total node
count is O(tens of thousands). This changes peak memory from O(n) to O(depth).

#### What stays in PHP vs what goes to DB

The collector needs fast address lookups during DFS to avoid re-traversal.
Currently this is done via `$memory_locations->has($address)` and
`$pool->getContextForLocation()`, both backed by PHP arrays of objects.

On a pool cache hit, the returned Context is never read — it is only passed
to a parent's `add()` as an opaque reference. Therefore the PHP side only
needs to know *whether* an address was seen and *which node_id* it maps to.
The actual Context data (type, properties, children) is only needed during
the fresh path and can be written to the DB immediately.

```
PHP side (kept in memory during collection):
  array<int, int>  address → node_id     (~16 bytes per entry)

DB side (streamed out):
  nodes table:  id, type, address, size, refcount, value, ...
  edges table:  parent_id, child_id, link_name
```

This replaces:
- ContextPool arrays of Context objects → `array<int, int>` seen set
- Full ReferenceContext tree → DB edges
- MemoryLocations collection → DB node attributes

#### Proposed collection flow

```
On fresh path:
  Create Context → extract fields → INSERT node → seen[address] = node_id
  → recurse into children → discard Context object after return
On cache hit:
  node_id = seen[address] → INSERT edge only → skip traversal
```

#### In-memory SQLite as default, file-backed as fallback

Even in-memory SQLite stores data more compactly than PHP objects (~130-220
bytes/node vs ~360 bytes/node) because it eliminates PHP object headers
(~56 bytes each) and array bucket overhead. If memory is still tight, the
same code can switch to file-backed SQLite by changing the connection string.

```
                    In-memory SQLite     File-backed SQLite
Memory usage        ~40-60% of current   O(depth) only
I/O cost            None                 Moderate (SSD: light)
Code difference     None                 Connection string only
```

Analysis phase:
  Query DB instead of traversing in-memory tree

#### Trade-offs

- **Pro**: Peak memory drops from O(total nodes) to O(tree depth). This is
  the only approach that fundamentally changes the scaling characteristic.
- **Pro**: Enables arbitrarily large target processes without OOM.
- **Con**: Full architectural change — collector signatures change from
  returning `ReferenceContext` to returning `int` (node_id), `add()` becomes
  edge INSERT, analysis switches from tree traversal to DB queries.
- **Con**: Write performance — thousands of INSERTs need batching/transactions.
  SQLite with WAL mode and prepared statements should be adequate.
- **Con**: Additional step for JSON output, though this is straightforward
  (see below).

#### JSON output compatibility

JSON output can be reconstructed from the DB by walking the tree via DFS
and streaming with `fwrite`, without loading the full tree into memory:

```
DB → DFS walk → fwrite() per node → JSON file (streaming, O(depth) memory)
```

This replaces the current `json_encode($full_tree)` approach. Both collection
and JSON export become streaming, so peak memory stays O(depth) throughout
the entire pipeline:

```
Process memory → [collect, stream to DB] → DB → [stream to JSON] → file
                   O(depth) memory              O(depth) memory
```

#### Relationship to other optimizations

This approach subsumes optimizations 5 (pool disposal) and 7 (wrapper
elimination) — both become irrelevant when contexts are not retained in memory.
Optimizations 1-2 (property inlining, MemoryLocation consolidation) are still
useful if applied to the transient Context objects before DB serialization, as
they reduce per-node processing overhead, though they no longer affect peak
memory.

## Priority

**Incremental path** (preserves current architecture):
Optimizations 1 and 2 are the strongest candidates: they reduce peak memory,
apply uniformly regardless of target process composition, and are mechanically
straightforward to implement. They also preserve the existing interface and
tree structure.

**Architectural path** (if incremental is insufficient):
Optimization 8 (DB-backed streaming) is the fundamental solution — it changes
peak memory scaling from O(nodes) to O(depth). However, it requires
rearchitecting the collector and analysis phases. Consider after measuring
the effect of optimizations 1-2.

Optimization 5 is a middle ground for post-peak reduction within the current
architecture. Optimization 7 reduces node count but at the cost of code
clarity.
