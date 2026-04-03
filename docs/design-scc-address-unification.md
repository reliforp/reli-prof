# SCC Address Unification for Streaming Mode

## Problem

Streaming + defer mode produces a graph where the same PHP object appears
as multiple graph nodes (one per collection phase). This breaks SCC
(cycle) detection because the back-edges that form cycles span different
node IDs for the same underlying object.

### Example

A circular reference `Node#1 -> Node#2 -> Node#3 -> Node#1`:

In non-streaming mode, all three nodes have a single node_id each, and
the back-edge `Node#3 -> Node#1` creates a cycle detectable by Tarjan.

In streaming + defer mode:
- objects_store phase: Node#1 gets node_id=100. Property `$next` is
  deferred. Later resolved as `emitReference(sentinel_of_Node#2, 100, "next")`.
- call_frames phase: Node#1 also appears as node_id=500 (from a local
  variable). Property `$next` is followed normally, creating tree edge
  `500 -> 501` where 501 is Node#2's call_frames node.
- The non-tree edge from objects_store (100 -> sentinel_of_Node#2) and
  the tree edge from call_frames (500 -> 501) never connect into a cycle
  because node 100 and node 500 represent the same object but have
  different IDs.

## Solution: Address-Based Node Unification in GraphSubstrate

During `GraphSubstrate::loadFromDb()`, after loading edges, unify nodes
that share the same memory address using Union-Find. The SCC computation
then uses the unified node graph, while all other data (property names,
sizes, class names) remains per-original-node.

### Implementation Plan

#### 1. Load address-to-node mapping

In `GraphSubstrate::loadEdges()` (or a new method called after
`loadNodeSizes()`), load the address→node_id mapping:

```sql
SELECT node_id, address
FROM context_node_locations
WHERE run_id = :run_id AND address IS NOT NULL
GROUP BY address
```

Build `array<int, list<int>>` mapping address → [node_id, ...].
Only addresses with 2+ node_ids need unification.

#### 2. Union-Find structure

Add a simple Union-Find (disjoint set) to GraphSubstrate:

```php
/** @var array<int, int> node_id => canonical node_id */
private array $canonical = [];

private function findCanonical(int $node): int
{
    while (isset($this->canonical[$node]) && $this->canonical[$node] !== $node) {
        // Path compression
        $this->canonical[$node] = $this->canonical[$this->canonical[$node]] ?? $this->canonical[$node];
        $node = $this->canonical[$node];
    }
    return $node;
}

private function union(int $a, int $b): void
{
    $ca = $this->findCanonical($a);
    $cb = $this->findCanonical($b);
    if ($ca !== $cb) {
        $this->canonical[$cb] = $ca;
    }
}
```

#### 3. Build unified adjacency for SCC

After loading edges and building the address→node mapping, unify nodes
with the same address. Then build a separate adjacency list for SCC that
maps through the canonical IDs:

```php
/** @var array<int, list<int>> canonical_parent => [canonical_child, ...] */
private array $scc_adjacency = [];
```

Populate during `loadEdges()` or a new `buildSccAdjacency()` step:

```php
protected function buildSccAdjacency(): void
{
    // For each strong edge (tree + non-tree), add canonical → canonical
    foreach ($this->strong_all_children as $parent => $children) {
        $cp = $this->findCanonical($parent);
        foreach ($children as $child) {
            $cc = $this->findCanonical($child);
            if ($cp !== $cc) {  // skip self-loops from unification
                $this->scc_adjacency[$cp][] = $cc;
            }
        }
    }
    // Deduplicate
    foreach ($this->scc_adjacency as $node => $children) {
        $this->scc_adjacency[$node] = array_values(array_unique($children));
    }
}
```

#### 4. Modify computeScc() to use unified adjacency

Replace `$this->strong_all_children[$node]` with
`$this->scc_adjacency[$node]` in the Tarjan algorithm. Also iterate over
canonical nodes only (keys of `$this->scc_adjacency` + isolated nodes).

The SCC result will contain canonical node IDs. Map back to all
original node IDs for profile generation:

```php
// After SCC detection, expand canonical nodes to originals
foreach ($sccs as &$scc) {
    $expanded = [];
    foreach ($scc as $canonical) {
        // Find all node_ids that map to this canonical
        $expanded[] = $canonical;
        foreach ($this->canonical as $node => $canon) {
            if ($this->findCanonical($node) === $canonical) {
                $expanded[] = $node;
            }
        }
    }
    $scc = array_values(array_unique($expanded));
}
```

Or more efficiently, pre-build a reverse mapping
`canonical → [original_node_ids]`.

#### 5. SCC profile generation uses original nodes

`buildSccProfiles()` already works with node_id lists. After expanding
canonical→original, the existing code for class_counts, total_size,
ext_in, ext_out should work unchanged — it just operates on a larger
set of node_ids per SCC.

### Performance Analysis

**Memory overhead:**
- `$canonical` array: at most N entries (number of multi-phase nodes).
  For a typical dump, ~200K objects × 2 phases = ~400K entries × 16 bytes
  = ~6 MB.
- `$scc_adjacency`: similar size to `$strong_all_children` after dedup.
  No significant overhead.

**CPU overhead:**
- Union-Find with path compression: effectively O(1) per operation.
- Building `scc_adjacency`: one pass over all strong edges, O(E).
- Deduplication: O(E log E) worst case, typically much less.
- Tarjan on unified graph: O(V' + E') where V' <= V, E' <= E.

**Total: O(E log E) additional work, negligible compared to existing
Tarjan O(V + E).** No performance concern.

### What Does NOT Change

- DB schema (no new columns or tables)
- Edge emit logic in MemoryLocationsCollector or ContextAnalyzer
- Non-SCC report passes (NonTreeEdgePass, PropertyScalingPass, etc.)
- subtree_size computation (uses tree edges, not affected)
- FfiCsrGraphSubstrate (needs same changes to computeScc)

### Files to Modify

1. `src/Inspector/Output/MemoryOutput/Report/Substrate/GraphSubstrate.php`
   - Add `$canonical` array and `findCanonical()`, `union()` methods
   - Add `loadAddressMapping()` method (new SQL query)
   - Add `buildSccAdjacency()` method
   - Modify `computeScc()` to use `$scc_adjacency`
   - Modify `buildSccProfiles()` to expand canonical→original nodes

2. `src/Inspector/Output/MemoryOutput/Report/Substrate/FfiCsrGraphSubstrate.php`
   - Same changes for the FFI CSR variant's SCC computation

3. `tests/Inspector/Output/MemoryOutput/Report/Substrate/GraphSubstrateTest.php`
   - Add test cases for address unification:
     - Two nodes same address → unified in SCC
     - Cycle via unified nodes detected
     - No unification when addresses differ
     - Profile generation with expanded node sets

### Test Strategy

Create a test fixture with:
- Nodes A1, A2 at same address (different phases of same object)
- Nodes B1, B2 at same address
- Edge A1 → B1 (tree, strong)
- Edge B2 → A2 (non-tree, strong, deferred resolution)
- Without unification: no cycle (A1→B1 and B2→A2 are disconnected)
- With unification: cycle A→B→A detected

Also verify with the real circular-reference test target (Node→Node cycle)
that SCC is correctly detected in streaming mode.
