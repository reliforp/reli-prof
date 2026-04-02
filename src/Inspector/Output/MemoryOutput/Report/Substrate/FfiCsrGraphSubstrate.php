<?php

/**
 * This file is part of the reliforp/reli-prof package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Reli\Inspector\Output\MemoryOutput\Report\Substrate;

use Reli\Lib\FFI\FFIHelper;

/**
 * FFI CSR (Compressed Sparse Row) based GraphSubstrate.
 *
 * Uses FFI-allocated int32/int64 arrays instead of PHP arrays for
 * graph edges and per-node numeric data. This reduces memory usage
 * by ~50-100x for large graphs (e.g. 6M edges: ~60 MB vs ~2.2 GB).
 *
 * CSR format:
 *   offsets[node_count + 1]: each node's child list start position
 *   edges[edge_count]:       flat array of child node IDs
 *   Node N's children = edges[offsets[N] .. offsets[N+1])
 */
final class FfiCsrGraphSubstrate extends GraphSubstrate
{
    private int $nodeCount = 0;

    // CSR for tree children
    private \FFI\CData $treeOffsets;
    private \FFI\CData $treeEdges;
    private int $treeEdgeCount = 0;

    // CSR for all children (tree + non-tree, for SCC)
    private \FFI\CData $allOffsets;
    private \FFI\CData $allEdges;
    private int $allEdgeCount = 0;

    // Reverse CSR for all parents
    private \FFI\CData $revOffsets;
    private \FFI\CData $revEdges;

    // Per-node data in FFI
    private \FFI\CData $ffiNodeSizes;
    private \FFI\CData $ffiSubtreeSizes;
    private \FFI\CData $ffiNodeToScc;

    /** @var array<int, int> node_id => CSR index */
    private array $nodeToIndex = [];

    /** @var array<int, int> CSR index => node_id */
    private array $indexToNode = [];

    private bool $subtreeSizesComputed = false;
    private int $nodeSizesSum = 0;

    /** @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedPropertyTypeCoercion */
    public static function loadFromDb(\PDO $db, int $run_id): static
    {
        $substrate = new self();
        $substrate->loadNodeSizesFfi($db, $run_id);
        $substrate->loadEdgesFfi($db, $run_id);
        $substrate->computeSubtreeSizesFfi();
        $substrate->computeSccFfi();
        return $substrate;
    }

    /** @return list<int> */
    public function getChildren(int $nodeId): array
    {
        $idx = $this->nodeToIndex[$nodeId] ?? null;
        if ($idx === null) {
            return [];
        }
        return $this->csrSlice($this->treeOffsets, $this->treeEdges, $idx);
    }

    /** @return list<int> */
    public function getAllChildren(int $nodeId): array
    {
        $idx = $this->nodeToIndex[$nodeId] ?? null;
        if ($idx === null) {
            return [];
        }
        return $this->csrSlice($this->allOffsets, $this->allEdges, $idx);
    }

    /** @return list<int> */
    public function getAllParents(int $nodeId): array
    {
        $idx = $this->nodeToIndex[$nodeId] ?? null;
        if ($idx === null) {
            return [];
        }
        return $this->csrSlice($this->revOffsets, $this->revEdges, $idx);
    }

    public function getNodeSize(int $nodeId): int
    {
        $idx = $this->nodeToIndex[$nodeId] ?? null;
        if ($idx === null) {
            return 0;
        }
        return (int)$this->ffiNodeSizes[$idx];
    }

    public function getSubtreeSize(int $nodeId): int
    {
        $idx = $this->nodeToIndex[$nodeId] ?? null;
        if ($idx === null) {
            return 0;
        }
        return (int)$this->ffiSubtreeSizes[$idx];
    }

    public function hasSubtreeSizes(): bool
    {
        return $this->subtreeSizesComputed;
    }

    public function getNodeSizesSum(): int
    {
        return $this->nodeSizesSum;
    }

    /** @return iterable<int, int> node_id => size */
    public function iterateNodeSizes(): iterable
    {
        for ($i = 0; $i < $this->nodeCount; $i++) {
            yield $this->indexToNode[$i] => (int)$this->ffiNodeSizes[$i];
        }
    }

    /** @return iterable<int, int> node_id => subtree_size */
    public function iterateSubtreeSizes(): iterable
    {
        for ($i = 0; $i < $this->nodeCount; $i++) {
            $size = (int)$this->ffiSubtreeSizes[$i];
            if ($size > 0) {
                yield $this->indexToNode[$i] => $size;
            }
        }
    }

    /** @return iterable<int, list<int>> child_id => [parent_id, ...] */
    public function iterateAllParents(): iterable
    {
        for ($i = 0; $i < $this->nodeCount; $i++) {
            $start = (int)$this->revOffsets[$i];
            $end = (int)$this->revOffsets[$i + 1];
            if ($start < $end) {
                $parents = [];
                for ($j = $start; $j < $end; $j++) {
                    $parents[] = (int)$this->revEdges[$j];
                }
                yield $this->indexToNode[$i] => $parents;
            }
        }
    }

    /** @return iterable<int, int> node_id => scc_id */
    public function iterateNodeToScc(): iterable
    {
        for ($i = 0; $i < $this->nodeCount; $i++) {
            $scc_id = (int)$this->ffiNodeToScc[$i];
            if ($scc_id >= 0) {
                yield $this->indexToNode[$i] => $scc_id;
            }
        }
    }

    // ---- Private implementation ----

    /**
     * Extract a CSR slice as a PHP array of original node IDs.
     * @return list<int>
     */
    private function csrSlice(\FFI\CData $offsets, \FFI\CData $edges, int $idx): array
    {
        $start = (int)$offsets[$idx];
        $end = (int)$offsets[$idx + 1];
        if ($start === $end) {
            return [];
        }
        $result = [];
        for ($i = $start; $i < $end; $i++) {
            $result[] = $this->indexToNode[(int)$edges[$i]];
        }
        return $result;
    }

    /** @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument */
    private function loadNodeSizesFfi(\PDO $db, int $run_id): void
    {
        $rows = $db->query(
            "SELECT node_id, sum(size) as s, group_concat(DISTINCT class_name) as cls"
            . " FROM context_node_locations WHERE run_id = {$run_id} GROUP BY node_id"
        )->fetchAll(\PDO::FETCH_NUM);

        // Also get node IDs that appear in edges but not in node_locations
        $edge_node_rows = $db->query(
            "SELECT DISTINCT parent_node_id FROM context_edges"
            . " WHERE run_id = {$run_id} AND parent_node_id IS NOT NULL"
            . " UNION SELECT DISTINCT child_node_id FROM context_edges"
            . " WHERE run_id = {$run_id}"
        )->fetchAll(\PDO::FETCH_COLUMN);

        // Build node_id set
        $all_node_ids = [];
        foreach ($rows as $r) {
            $all_node_ids[(int)$r[0]] = true;
        }
        foreach ($edge_node_rows as $nid) {
            $all_node_ids[(int)$nid] = true;
        }
        // Include -1 sentinel for root parent
        $all_node_ids[-1] = true;
        unset($edge_node_rows);

        // Build mapping
        $idx = 0;
        foreach ($all_node_ids as $node_id => $_) {
            $this->nodeToIndex[$node_id] = $idx;
            $this->indexToNode[$idx] = $node_id;
            $idx++;
        }
        $this->nodeCount = $idx;

        // Allocate FFI arrays
        $this->ffiNodeSizes = FFIHelper::new("int64_t[{$this->nodeCount}]");
        $this->ffiSubtreeSizes = FFIHelper::new("int64_t[{$this->nodeCount}]");
        $this->ffiNodeToScc = FFIHelper::new("int32_t[{$this->nodeCount}]");

        // Initialize node_to_scc to -1 (no SCC)
        for ($i = 0; $i < $this->nodeCount; $i++) {
            $this->ffiNodeToScc[$i] = -1;
        }

        // Fill node sizes and classes
        $this->nodeSizesSum = 0;
        foreach ($rows as $r) {
            $node_id = (int)$r[0];
            $size = (int)$r[1];
            $csrIdx = $this->nodeToIndex[$node_id];
            $this->ffiNodeSizes[$csrIdx] = $size;
            $this->nodeSizesSum += $size;
            if ($r[2] !== null) {
                $this->node_classes[$node_id] = $r[2];
            }
        }
        unset($rows);
    }

    /** @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument */
    private function loadEdgesFfi(\PDO $db, int $run_id): void
    {
        $rows = $db->query(
            "SELECT parent_node_id, child_node_id, is_tree"
            . " FROM context_edges WHERE run_id = {$run_id}"
        )->fetchAll(\PDO::FETCH_NUM);

        $this->edge_count = count($rows);

        // Step 1: Count degrees
        $treeDegree = FFIHelper::new("int32_t[{$this->nodeCount}]");
        $allDegree = FFIHelper::new("int32_t[{$this->nodeCount}]");
        $revDegree = FFIHelper::new("int32_t[{$this->nodeCount}]");

        $treeEdgeCount = 0;
        $allEdgeCount = 0;

        foreach ($rows as $r) {
            $parent = $r[0] === null ? -1 : (int)$r[0];
            $child = (int)$r[1];
            $is_tree = (int)$r[2];

            $parentIdx = $this->nodeToIndex[$parent];
            $childIdx = $this->nodeToIndex[$child];

            if ($is_tree) {
                $treeDegree[$parentIdx] = $treeDegree[$parentIdx] + 1;
                $treeEdgeCount++;
                if ($parent === -1) {
                    $this->roots[] = $child;
                }
            }
            if ($parent !== -1) {
                $allDegree[$parentIdx] = $allDegree[$parentIdx] + 1;
                $allEdgeCount++;
            }
            $revDegree[$childIdx] = $revDegree[$childIdx] + 1;
        }

        $this->treeEdgeCount = $treeEdgeCount;
        $this->allEdgeCount = $allEdgeCount;
        $revEdgeCount = count($rows); // every edge has a child

        // Step 2: Build offsets via prefix sum
        $this->treeOffsets = FFIHelper::new("int32_t[" . ($this->nodeCount + 1) . "]");
        $this->allOffsets = FFIHelper::new("int32_t[" . ($this->nodeCount + 1) . "]");
        $this->revOffsets = FFIHelper::new("int32_t[" . ($this->nodeCount + 1) . "]");

        $this->treeOffsets[0] = 0;
        $this->allOffsets[0] = 0;
        $this->revOffsets[0] = 0;

        for ($i = 0; $i < $this->nodeCount; $i++) {
            $this->treeOffsets[$i + 1] = $this->treeOffsets[$i] + $treeDegree[$i];
            $this->allOffsets[$i + 1] = $this->allOffsets[$i] + $allDegree[$i];
            $this->revOffsets[$i + 1] = $this->revOffsets[$i] + $revDegree[$i];
        }

        // Step 3: Allocate edge arrays
        $safeTreeCount = max($treeEdgeCount, 1);
        $safeAllCount = max($allEdgeCount, 1);
        $safeRevCount = max($revEdgeCount, 1);

        $this->treeEdges = FFIHelper::new("int32_t[{$safeTreeCount}]");
        $this->allEdges = FFIHelper::new("int32_t[{$safeAllCount}]");
        $this->revEdges = FFIHelper::new("int32_t[{$safeRevCount}]");

        // Step 4: Fill edges using write positions
        $treePos = FFIHelper::new("int32_t[{$this->nodeCount}]");
        $allPos = FFIHelper::new("int32_t[{$this->nodeCount}]");
        $revPos = FFIHelper::new("int32_t[{$this->nodeCount}]");

        for ($i = 0; $i < $this->nodeCount; $i++) {
            $treePos[$i] = $this->treeOffsets[$i];
            $allPos[$i] = $this->allOffsets[$i];
            $revPos[$i] = $this->revOffsets[$i];
        }

        foreach ($rows as $r) {
            $parent = $r[0] === null ? -1 : (int)$r[0];
            $child = (int)$r[1];
            $is_tree = (int)$r[2];

            $parentIdx = $this->nodeToIndex[$parent];
            $childIdx = $this->nodeToIndex[$child];

            if ($is_tree) {
                $pos = (int)$treePos[$parentIdx];
                $this->treeEdges[$pos] = $childIdx;
                $treePos[$parentIdx] = $pos + 1;
            }
            if ($parent !== -1) {
                $pos = (int)$allPos[$parentIdx];
                $this->allEdges[$pos] = $childIdx;
                $allPos[$parentIdx] = $pos + 1;
            }
            // Store original node_id for parents (not index) since
            // all_parents includes -1 sentinel
            $pos = (int)$revPos[$childIdx];
            $this->revEdges[$pos] = $parent;
            $revPos[$childIdx] = $pos + 1;
        }

        unset($rows, $treeDegree, $allDegree, $revDegree, $treePos, $allPos, $revPos);
    }

    private function computeSubtreeSizesFfi(): void
    {
        // Use a visited array to track which nodes have been computed
        $visited = [];

        $stack = [];
        foreach ($this->roots as $root) {
            $stack[] = [$this->nodeToIndex[$root], false];
        }

        while ($stack) {
            [$idx, $processed] = array_pop($stack);
            if ($processed) {
                $size = (int)$this->ffiNodeSizes[$idx];
                $start = (int)$this->treeOffsets[$idx];
                $end = (int)$this->treeOffsets[$idx + 1];
                for ($i = $start; $i < $end; $i++) {
                    $size += (int)$this->ffiSubtreeSizes[(int)$this->treeEdges[$i]];
                }
                $this->ffiSubtreeSizes[$idx] = $size;
                $visited[$idx] = true;
            } else {
                $stack[] = [$idx, true];
                $start = (int)$this->treeOffsets[$idx];
                $end = (int)$this->treeOffsets[$idx + 1];
                for ($i = $start; $i < $end; $i++) {
                    $childIdx = (int)$this->treeEdges[$i];
                    if (!isset($visited[$childIdx])) {
                        $stack[] = [$childIdx, false];
                    }
                }
            }
        }
        $this->subtreeSizesComputed = true;
    }

    private function computeSccFfi(): void
    {
        $index_counter = 0;
        $stack = [];
        $on_stack = [];
        $index = [];
        $lowlink = [];
        $sccs = [];

        for ($v = 0; $v < $this->nodeCount; $v++) {
            // Skip the -1 sentinel node
            if ($this->indexToNode[$v] === -1) {
                continue;
            }
            if (isset($index[$v])) {
                continue;
            }

            $call_stack = [[$v, 0]];
            $index[$v] = $lowlink[$v] = $index_counter++;
            $stack[] = $v;
            $on_stack[$v] = true;

            while ($call_stack) {
                [$node, $ci] = array_pop($call_stack);
                $start = (int)$this->allOffsets[$node];
                $end = (int)$this->allOffsets[$node + 1];
                $count = $end - $start;

                $found_unvisited = false;
                for ($i = $ci; $i < $count; $i++) {
                    $w = (int)$this->allEdges[$start + $i];
                    if (!isset($index[$w])) {
                        $call_stack[] = [$node, $i + 1];
                        $index[$w] = $lowlink[$w] = $index_counter++;
                        $stack[] = $w;
                        $on_stack[$w] = true;
                        $call_stack[] = [$w, 0];
                        $found_unvisited = true;
                        break;
                    } elseif (isset($on_stack[$w])) {
                        $lowlink[$node] = min($lowlink[$node], $index[$w]);
                    }
                }

                if (!$found_unvisited) {
                    if ($lowlink[$node] === $index[$node]) {
                        /** @var list<int> $scc CSR indices */
                        $scc = [];
                        do {
                            /** @var int $w */
                            $w = array_pop($stack);
                            unset($on_stack[$w]);
                            $scc[] = $w;
                        } while ($w !== $node);
                        if (count($scc) > 1) {
                            $sccs[] = $scc;
                        }
                    }
                    if ($call_stack) {
                        $parent_frame = &$call_stack[count($call_stack) - 1];
                        $lowlink[$parent_frame[0]] = min($lowlink[$parent_frame[0]], $lowlink[$node]);
                    }
                }
            }
        }

        // Build SCC profiles (convert CSR indices back to node IDs)
        foreach ($sccs as $scc_id => $scc_indices) {
            $scc_nodes = [];
            foreach ($scc_indices as $idx) {
                $scc_nodes[] = $this->indexToNode[$idx];
            }

            $scc_set = array_flip($scc_indices);
            $total_size = 0;
            $class_counts = [];

            foreach ($scc_indices as $idx) {
                $node_id = $this->indexToNode[$idx];
                $this->ffiNodeToScc[$idx] = $scc_id;
                $total_size += (int)$this->ffiNodeSizes[$idx];
                $cls = $this->node_classes[$node_id] ?? null;
                if ($cls !== null) {
                    $class_counts[$cls] = ($class_counts[$cls] ?? 0) + 1;
                }
            }

            $ext_in = 0;
            $ext_out = 0;
            foreach ($scc_indices as $idx) {
                // Reverse edges (parents)
                $rStart = (int)$this->revOffsets[$idx];
                $rEnd = (int)$this->revOffsets[$idx + 1];
                for ($j = $rStart; $j < $rEnd; $j++) {
                    $parentNodeId = (int)$this->revEdges[$j];
                    if ($parentNodeId === -1) {
                        continue;
                    }
                    $parentIdx = $this->nodeToIndex[$parentNodeId];
                    if (!isset($scc_set[$parentIdx])) {
                        $ext_in++;
                    }
                }
                // Forward edges (all children)
                $fStart = (int)$this->allOffsets[$idx];
                $fEnd = (int)$this->allOffsets[$idx + 1];
                for ($j = $fStart; $j < $fEnd; $j++) {
                    $childIdx = (int)$this->allEdges[$j];
                    if (!isset($scc_set[$childIdx])) {
                        $ext_out++;
                    }
                }
            }

            arsort($class_counts);
            $signature_parts = [];
            foreach ($class_counts as $cls => $cnt) {
                $signature_parts[] = "{$cls}:{$cnt}";
            }
            $signature = implode(', ', $signature_parts);

            $this->scc_profiles[] = [
                'id' => $scc_id,
                'nodes' => $scc_nodes,
                'node_count' => count($scc_nodes),
                'total_size' => $total_size,
                'ext_in' => $ext_in,
                'ext_out' => $ext_out,
                'class_counts' => $class_counts,
                'signature' => $signature,
                'single_owner_likelihood' => $ext_in <= 2 ? 'high' : ($ext_in <= 10 ? 'medium' : 'low'),
            ];
        }
    }
}
