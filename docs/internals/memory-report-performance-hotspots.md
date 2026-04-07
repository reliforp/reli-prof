# Memory Report Performance Hotspots

Notes for future optimization work on `inspector:memory:report`.

As of 2026-04-07, `FfiCsrGraphSubstrate::computeSccFfi()` already caches
canonical neighbor lists. The next bottlenecks are mostly in Phase 3 report
passes that repeatedly materialize adjacency lists from the FFI CSR substrate.

## Main Observation

The likely remaining hotspot is not a single pass, but the access pattern
shared by many passes:

- `FfiCsrGraphSubstrate::getChildren()`
- `FfiCsrGraphSubstrate::getStrongChildren()`
- `FfiCsrGraphSubstrate::getAllChildren()`
- `FfiCsrGraphSubstrate::getAllParents()`

Each of those currently calls `csrSlice()`, which allocates a fresh PHP array
for every lookup. Several passes call these methods inside nested loops over
large parts of the graph.

Files:

- `src/Inspector/Output/MemoryOutput/Report/Substrate/GraphSubstrate.php`
- `src/Inspector/Output/MemoryOutput/Report/Substrate/FfiCsrGraphSubstrate.php`

## Priority Candidates

### 1. Substrate-level no-allocation traversal

Highest leverage change.

Why it looks expensive:

- FFI CSR stores edges compactly, but pass code still pays to convert each
  touched adjacency range into a PHP array.
- Optimizing a single pass helps locally; reducing adjacency materialization
  helps almost every graph-based pass.

Likely direction:

- Add APIs that return scalar facts without building arrays:
  - child count
  - parent count
  - "does this node have any child in set X"
  - "scan children until predicate matches"
- Prefer APIs that do the scan inside the substrate implementation rather than
  by yielding a generator or invoking a PHP callback per edge.
- Keep PHP-array `GraphSubstrate` compatibility, but optimize the FFI
  implementation first.

Candidate API sketch:

```php
public function getChildrenCount(int $node_id): int;
public function getStrongChildrenCount(int $node_id): int;
public function hasAnyChildInSet(int $node_id, array $node_set): bool;
public function hasAnyParentOutsideSet(int $node_id, array $node_set): bool;
```

The exact API can differ; the point is to avoid `list<int>` materialization
when the caller only needs counts or membership tests.

### 2. `TopArraysPass`

Probably the simplest next win.

File:

- `src/Inspector/Output/MemoryOutput/Report/Pass/TopArraysPass.php`

Why it looks expensive:

- Iterates all nodes with `iterateNodeSizes()`
- For each node, scans `getChildren($node)`
- On array nodes, calls `count($this->substrate->getChildren($child))`
  just to count elements

The element count path is especially wasteful in FFI mode because it
materializes the whole child list only to call `count()`.

Likely direction:

- Add `getChildrenCount()` to substrate and switch
  `count($this->substrate->getChildren($child))` to it
- If useful, add a cheap "first matching child" helper to avoid scanning and
  materializing more than needed

### 3. `CycleClusterPass`

Likely one of the heaviest remaining passes.

File:

- `src/Inspector/Output/MemoryOutput/Report/Pass/CycleClusterPass.php`

Why it looks expensive:

- Per top SCC group, it runs multiple graph walks:
  - `findBackReference()`
  - `computeRetained()`
  - `findEntryPath()`
- Resource leak detection later in the file walks strong children again
- `findBackReference()` currently does:
  - `getAllChildren($node)`
  - `getChildren($node)`
  - `in_array()` tree-edge membership check

Likely direction:

- Avoid repeated tree-child materialization inside `findBackReference()`
- Precompute a cheap tree-child membership structure for nodes touched by the
  top SCC groups
- Replace "load children array and test membership in userland" with a
  substrate-level membership helper if possible

### 4. `BlameAllocationPass`

Likely expensive on graphs with many shared nodes.

File:

- `src/Inspector/Output/MemoryOutput/Report/Pass/BlameAllocationPass.php`

Why it looks expensive:

- BFS from every root via `getChildren()`
- Then full node-size iteration
- For shared nodes, calls `getAllParents($node)` and distributes blame

Likely direction:

- Use substrate helpers for parent counts and parent scanning
- Consider building ownership data in one pass rather than repeated per-node
  parent materialization

### 5. Property / ownership passes

Second-tier candidates after the above.

Files:

- `src/Inspector/Output/MemoryOutput/Report/Pass/OwnershipPatternPass.php`
- `src/Inspector/Output/MemoryOutput/Report/Pass/PerPropertyMemoryPass.php`
- `src/Inspector/Output/MemoryOutput/Report/Pass/PropertyScalingPass.php`

Why they look expensive:

- Repeated `getChildren()` calls nested 2 to 3 levels deep
- Common pattern:
  - object
  - `object_properties`
  - property node
  - maybe one more child level

Likely direction:

- Consider precomputing or caching the `object_properties` child for object
  nodes touched by these passes
- Reuse any substrate-level count / scan helpers added above

## Probably Not First Priority

### `TopStringsPass`

File:

- `src/Inspector/Output/MemoryOutput/Report/Pass/TopStringsPass.php`

Reason:

- Main SQL query is already `ORDER BY size DESC LIMIT 10`
- Path reconstruction does extra DB work, but only for a tiny result set

### `ReportGenerator`

File:

- `src/Inspector/Output/MemoryOutput/Report/ReportGenerator.php`

Reason:

- Mostly orchestration
- The heavy cost appears to sit inside graph-based passes and substrate access

## Suggested Next Session Plan

1. Add one or two cheap substrate APIs that avoid PHP array materialization.
2. Convert `TopArraysPass` first and measure again.
3. If report runtime is still dominated by graph work, convert
   `CycleClusterPass` next.
4. Then revisit `BlameAllocationPass`.

## Measurement Guidance

When validating improvements, prefer a snapshot large enough to use
`FfiCsrGraphSubstrate` automatically. Small snapshots can hide the real cost
because the PHP-array substrate has a different performance profile.
