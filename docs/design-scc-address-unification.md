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

### What Changes in DB Schema

Add `canonical_node_id` column to `context_nodes`:

```sql
ALTER TABLE context_nodes ADD COLUMN canonical_node_id INTEGER;
CREATE INDEX idx_context_nodes_canonical ON context_nodes(run_id, canonical_node_id);
```

- `canonical_node_id` = the representative node_id for all nodes sharing
  the same memory address. NULL for nodes with unique addresses.
- Populated during `finalizeStreaming()` (or `loadFromDb()` for GraphSubstrate)
  by a single pass over `context_node_locations`:

```sql
-- Find addresses that appear in multiple nodes
WITH address_groups AS (
    SELECT address, MIN(node_id) AS canonical
    FROM context_node_locations
    WHERE run_id = :run_id AND address IS NOT NULL
    GROUP BY address
    HAVING COUNT(DISTINCT node_id) > 1
)
UPDATE context_nodes SET canonical_node_id = (
    SELECT ag.canonical FROM address_groups ag
    JOIN context_node_locations cnl ON cnl.address = ag.address AND cnl.run_id = :run_id
    WHERE cnl.node_id = context_nodes.node_id
)
WHERE run_id = :run_id;
```

Or more efficiently, compute in PHP and batch UPDATE:

```php
// 1. Load address → node_ids mapping
$rows = $db->query("
    SELECT address, GROUP_CONCAT(DISTINCT node_id) AS node_ids
    FROM context_node_locations
    WHERE run_id = {$run_id} AND address IS NOT NULL
    GROUP BY address
    HAVING COUNT(DISTINCT node_id) > 1
")->fetchAll();

// 2. Build canonical mapping
$canonical = [];
foreach ($rows as $row) {
    $node_ids = array_map('intval', explode(',', $row['node_ids']));
    $canon = min($node_ids);
    foreach ($node_ids as $nid) {
        $canonical[$nid] = $canon;
    }
}

// 3. Batch UPDATE
$stmt = $db->prepare("UPDATE context_nodes SET canonical_node_id = ? WHERE run_id = ? AND node_id = ?");
foreach ($canonical as $nid => $canon) {
    $stmt->execute([$canon, $run_id, $nid]);
}
```

**This enables DB users to query cycles and aliases directly:**

```sql
-- Find all nodes representing the same object
SELECT * FROM context_nodes
WHERE canonical_node_id = 100 AND run_id = 1;

-- Find cycle edges via canonical IDs
SELECT e.*, cn.canonical_node_id AS parent_canonical, cn2.canonical_node_id AS child_canonical
FROM context_edges e
JOIN context_nodes cn ON cn.node_id = e.parent_node_id AND cn.run_id = e.run_id
JOIN context_nodes cn2 ON cn2.node_id = e.child_node_id AND cn2.run_id = e.run_id
WHERE e.run_id = 1
AND COALESCE(cn.canonical_node_id, cn.node_id) != COALESCE(cn2.canonical_node_id, cn2.node_id);
```

### What Does NOT Change

- Edge emit logic in MemoryLocationsCollector or ContextAnalyzer
- Non-SCC report passes (NonTreeEdgePass, PropertyScalingPass, etc.)
- subtree_size computation (uses tree edges, not affected)
- FfiCsrGraphSubstrate (needs same changes to computeScc)

### Files to Modify

1. `src/Inspector/Output/MemoryOutput/PdoMemoryOutput.php`
   - Add `canonical_node_id` column to `context_nodes` CREATE TABLE
   - In `finalizeStreaming()`: compute and write canonical_node_id values

2. `src/Inspector/Output/MemoryOutput/Report/Substrate/GraphSubstrate.php`
   - Add `$canonical` array and `findCanonical()`, `union()` methods
   - Add `loadAddressMapping()` method (load canonical_node_id from DB,
     or compute from context_node_locations if not populated)
   - Add `buildSccAdjacency()` method
   - Modify `computeScc()` to use `$scc_adjacency`
   - Modify `buildSccProfiles()` to expand canonical→original nodes

3. `src/Inspector/Output/MemoryOutput/Report/Substrate/FfiCsrGraphSubstrate.php`
   - Same changes for the FFI CSR variant's SCC computation

4. `tests/Inspector/Output/MemoryOutput/Report/Substrate/GraphSubstrateTest.php`
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
