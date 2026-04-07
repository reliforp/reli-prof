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
 * Phase 2 optimizations:
 * - indexToNode: FFI int32 array instead of PHP array (~300 MB → ~12 MB)
 * - nodeToIndex: direct-indexed FFI int32 array if node_ids are compact
 * - node_classes: class dictionary + FFI int16 per-node IDs (~300 MB → ~6 MB)
 *
 * @psalm-suppress InaccessibleMethod, InvalidCast, PossiblyNullOperand, InvalidOperand, MissingConstructor
 */
final class FfiCsrGraphSubstrate extends GraphSubstrate
{
    private int $nodeCount = 0;

    // CSR for tree children
    private \FFI\CData $treeOffsets;
    private \FFI\CData $treeEdges;

    // CSR for strong tree children (tree + strong, for subtree sizes)
    private \FFI\CData $strongTreeOffsets;
    private \FFI\CData $strongTreeEdges;

    // CSR for all children (tree + non-tree)
    private \FFI\CData $allOffsets;
    private \FFI\CData $allEdges;

    // CSR for strong all children (strong tree + non-tree, for SCC)
    private \FFI\CData $strongAllOffsets;
    private \FFI\CData $strongAllEdges;

    // Reverse CSR for all parents
    private \FFI\CData $revOffsets;
    private \FFI\CData $revEdges;

    // Per-node data in FFI
    private \FFI\CData $ffiNodeSizes;
    private \FFI\CData $ffiSubtreeSizes;
    private \FFI\CData $ffiNodeToScc;

    // Node ID mapping (FFI-backed)
    private \FFI\CData $indexToNodeFfi;  // int32_t[nodeCount]: CSR index → node_id

    // Direct-indexed nodeToIndex: nodeToIndexDirect[node_id + 1] = CSR index
    // (offset by 1 to handle -1 sentinel at slot 0)
    // If node_ids are too sparse, falls back to PHP array.
    private ?\FFI\CData $nodeToIndexDirect = null;
    private int $directIndexOffset = 1; // node_id + offset → array index
    private int $directIndexSize = 0;   // size of nodeToIndexDirect array
    /** @var array<int, int>|null fallback PHP mapping when direct indexing is not feasible */
    private ?array $nodeToIndexPhp = null;

    // Class dictionary: small PHP array of unique class names + FFI int16 per node
    /** @var list<string> class_id → class_name */
    private array $classDict = [];
    /** @var array<string, int> class_name → class_id */
    private array $classDictReverse = [];
    private \FFI\CData $nodeClassIds; // int16_t[nodeCount], -1 = no class

    private bool $subtreeSizesComputed = false;
    private int $nodeSizesSum = 0;

    /** @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedPropertyTypeCoercion */
    #[\Override]
    public static function loadFromDb(\PDO $db, int $run_id): static
    {
        $substrate = new self();
        $substrate->loadNodeSizesFfi($db, $run_id);
        $substrate->loadEdgesFfi($db, $run_id);
        $substrate->loadAddressMapping($db, $run_id);
        $substrate->buildSccAdjacency();
        $substrate->computeSubtreeSizesFfi();
        $substrate->computeSccFfi();
        return $substrate;
    }

    /** @return list<int> */
    #[\Override]
    public function getChildren(int $nodeId): array
    {
        $idx = $this->nodeIdToIndex($nodeId);
        if ($idx < 0) {
            return [];
        }
        return $this->csrSlice($this->treeOffsets, $this->treeEdges, $idx);
    }

    /** @return list<int> */
    #[\Override]
    public function getStrongChildren(int $nodeId): array
    {
        $idx = $this->nodeIdToIndex($nodeId);
        if ($idx < 0) {
            return [];
        }
        return $this->csrSlice($this->strongTreeOffsets, $this->strongTreeEdges, $idx);
    }

    /** @return list<int> */
    #[\Override]
    public function getAllChildren(int $nodeId): array
    {
        $idx = $this->nodeIdToIndex($nodeId);
        if ($idx < 0) {
            return [];
        }
        return $this->csrSlice($this->allOffsets, $this->allEdges, $idx);
    }

    /** @return list<int> */
    #[\Override]
    public function getStrongAllChildren(int $nodeId): array
    {
        $idx = $this->nodeIdToIndex($nodeId);
        if ($idx < 0) {
            return [];
        }
        return $this->csrSlice($this->strongAllOffsets, $this->strongAllEdges, $idx);
    }

    /** @return list<int> */
    #[\Override]
    public function getAllParents(int $nodeId): array
    {
        $idx = $this->nodeIdToIndex($nodeId);
        if ($idx < 0) {
            return [];
        }
        return $this->csrSlice($this->revOffsets, $this->revEdges, $idx);
    }

    #[\Override]
    public function getIncomingCount(int $nodeId): int
    {
        $idx = $this->nodeIdToIndex($nodeId);
        if ($idx < 0) {
            return 0;
        }
        return (int)$this->revOffsets[$idx + 1] - (int)$this->revOffsets[$idx];
    }

    #[\Override]
    public function getNodeSize(int $nodeId): int
    {
        $idx = $this->nodeIdToIndex($nodeId);
        if ($idx < 0) {
            return 0;
        }
        return (int)$this->ffiNodeSizes[$idx];
    }

    #[\Override]
    public function getSubtreeSize(int $nodeId): int
    {
        $idx = $this->nodeIdToIndex($nodeId);
        if ($idx < 0) {
            return 0;
        }
        return (int)$this->ffiSubtreeSizes[$idx];
    }

    #[\Override]
    public function getNodeClass(int $nodeId): ?string
    {
        $idx = $this->nodeIdToIndex($nodeId);
        if ($idx < 0) {
            return null;
        }
        $classId = (int)$this->nodeClassIds[$idx];
        if ($classId < 0) {
            return null;
        }
        return $this->classDict[$classId] ?? null;
    }

    #[\Override]
    public function hasSubtreeSizes(): bool
    {
        return $this->subtreeSizesComputed;
    }

    #[\Override]
    public function getNodeSizesSum(): int
    {
        return $this->nodeSizesSum;
    }

    /** @return iterable<int, int> node_id => size */
    #[\Override]
    public function iterateNodeSizes(): iterable
    {
        for ($i = 0; $i < $this->nodeCount; $i++) {
            yield (int)$this->indexToNodeFfi[$i] => (int)$this->ffiNodeSizes[$i];
        }
    }

    /** @return iterable<int, int> node_id => subtree_size */
    #[\Override]
    public function iterateSubtreeSizes(): iterable
    {
        for ($i = 0; $i < $this->nodeCount; $i++) {
            $size = (int)$this->ffiSubtreeSizes[$i];
            if ($size > 0) {
                yield (int)$this->indexToNodeFfi[$i] => $size;
            }
        }
    }

    /** @return iterable<int, list<int>> child_id => [parent_id, ...] */
    #[\Override]
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
                yield (int)$this->indexToNodeFfi[$i] => $parents;
            }
        }
    }

    /** @return iterable<int, string> node_id => class_name */
    #[\Override]
    public function iterateNodeClasses(): iterable
    {
        for ($i = 0; $i < $this->nodeCount; $i++) {
            $classId = (int)$this->nodeClassIds[$i];
            if ($classId >= 0) {
                yield (int)$this->indexToNodeFfi[$i] => $this->classDict[$classId];
            }
        }
    }

    /** @return iterable<int, int> node_id => scc_id */
    #[\Override]
    public function iterateNodeToScc(): iterable
    {
        for ($i = 0; $i < $this->nodeCount; $i++) {
            $scc_id = (int)$this->ffiNodeToScc[$i];
            if ($scc_id >= 0) {
                yield (int)$this->indexToNodeFfi[$i] => $scc_id;
            }
        }
    }

    // ---- Private implementation ----

    /**
     * Convert node_id to CSR index. Returns -1 if not found.
     */
    private function nodeIdToIndex(int $nodeId): int
    {
        if ($this->nodeToIndexDirect !== null) {
            $slot = $nodeId + $this->directIndexOffset;
            if ($slot < 0 || $slot >= $this->directIndexSize) {
                return -1;
            }
            return (int)$this->nodeToIndexDirect[$slot];
        }
        return $this->nodeToIndexPhp[$nodeId] ?? -1;
    }

    /**
     * Convert CSR index to node_id.
     */
    private function indexToNodeId(int $idx): int
    {
        return (int)$this->indexToNodeFfi[$idx];
    }

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
            $result[] = (int)$this->indexToNodeFfi[(int)$edges[$i]];
        }
        return $result;
    }

    /** @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedPropertyTypeCoercion */
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

        // Build sorted node_id list
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

        // Build mapping: assign CSR indices
        $this->nodeCount = count($all_node_ids);
        $this->indexToNodeFfi = FFIHelper::new("int32_t[{$this->nodeCount}]");

        $idx = 0;
        $minNodeId = PHP_INT_MAX;
        $maxNodeId = PHP_INT_MIN;
        foreach ($all_node_ids as $node_id => $_) {
            $this->indexToNodeFfi[$idx] = $node_id;
            if ($node_id < $minNodeId) {
                $minNodeId = $node_id;
            }
            if ($node_id > $maxNodeId) {
                $maxNodeId = $node_id;
            }
            $idx++;
        }

        // Decide nodeToIndex strategy: direct indexing vs PHP fallback
        // Direct indexing: array[node_id + offset] = csr_index
        // Feasible if range is compact (< 4x node count, < 100M entries)
        $range = $maxNodeId - $minNodeId + 1;
        if ($range > 0 && $range < $this->nodeCount * 4 && $range < 100_000_000) {
            $this->directIndexOffset = -$minNodeId; // node_id + offset → 0-based slot
            $directSize = $range;
            $this->directIndexSize = $directSize;
            $this->nodeToIndexDirect = FFIHelper::new("int32_t[{$directSize}]");
            // Initialize all to -1
            for ($i = 0; $i < $directSize; $i++) {
                $this->nodeToIndexDirect[$i] = -1;
            }
            // Fill
            for ($i = 0; $i < $this->nodeCount; $i++) {
                $nid = (int)$this->indexToNodeFfi[$i];
                $slot = $nid + $this->directIndexOffset;
                $this->nodeToIndexDirect[$slot] = $i;
            }
        } else {
            // Fallback: PHP associative array
            $this->nodeToIndexPhp = [];
            for ($i = 0; $i < $this->nodeCount; $i++) {
                $this->nodeToIndexPhp[(int)$this->indexToNodeFfi[$i]] = $i;
            }
        }
        unset($all_node_ids);

        // Allocate per-node FFI arrays
        $this->ffiNodeSizes = FFIHelper::new("int64_t[{$this->nodeCount}]");
        $this->ffiSubtreeSizes = FFIHelper::new("int64_t[{$this->nodeCount}]");
        $this->ffiNodeToScc = FFIHelper::new("int32_t[{$this->nodeCount}]");
        $this->nodeClassIds = FFIHelper::new("int16_t[{$this->nodeCount}]");

        // Initialize
        for ($i = 0; $i < $this->nodeCount; $i++) {
            $this->ffiNodeToScc[$i] = -1;
            $this->nodeClassIds[$i] = -1;
        }

        // Fill node sizes and class dictionary
        $this->nodeSizesSum = 0;
        foreach ($rows as $r) {
            $node_id = (int)$r[0];
            $size = (int)$r[1];
            $csrIdx = $this->nodeIdToIndex($node_id);
            $this->ffiNodeSizes[$csrIdx] = $size;
            $this->nodeSizesSum += $size;
            if ($r[2] !== null) {
                $className = (string)$r[2];
                if (!isset($this->classDictReverse[$className])) {
                    $classId = count($this->classDict);
                    $this->classDict[] = $className;
                    $this->classDictReverse[$className] = $classId;
                }
                $this->nodeClassIds[$csrIdx] = $this->classDictReverse[$className];
            }
        }
        unset($rows);
    }

    /**
     * Load edges from DB into CSR arrays using cursor streaming.
     * Two cursor passes avoid loading all edges into a PHP array.
     *
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument
     */
    private function loadEdgesFfi(\PDO $db, int $run_id): void
    {
        $nc = $this->nodeCount;
        $edge_query = "SELECT parent_node_id, child_node_id, is_tree, strength"
            . " FROM context_edges WHERE run_id = {$run_id}";

        // Pass 1 (cursor): count degrees and collect roots
        $treeDeg = FFIHelper::new("int32_t[{$nc}]");
        $strongTreeDeg = FFIHelper::new("int32_t[{$nc}]");
        $allDeg = FFIHelper::new("int32_t[{$nc}]");
        $strongAllDeg = FFIHelper::new("int32_t[{$nc}]");
        $revDeg = FFIHelper::new("int32_t[{$nc}]");

        $treeCount = 0;
        $strongTreeCount = 0;
        $allCount = 0;
        $strongAllCount = 0;
        $edgeCount = 0;

        $stmt = $db->query($edge_query);
        while ($r = $stmt->fetch(\PDO::FETCH_NUM)) {
            $edgeCount++;
            $parent = $r[0] === null ? -1 : (int)$r[0];
            $child = (int)$r[1];
            $is_tree = (int)$r[2];
            $is_strong = ((string)($r[3] ?? 'strong')) === 'strong';

            $pi = $this->nodeIdToIndex($parent);
            $ci = $this->nodeIdToIndex($child);

            if ($is_tree) {
                $treeDeg[$pi] = $treeDeg[$pi] + 1;
                $treeCount++;
                if ($is_strong) {
                    $strongTreeDeg[$pi] = $strongTreeDeg[$pi] + 1;
                    $strongTreeCount++;
                }
                if ($parent === -1) {
                    $this->roots[] = $child;
                }
            }
            if ($parent !== -1) {
                $allDeg[$pi] = $allDeg[$pi] + 1;
                $allCount++;
                if ($is_strong) {
                    $strongAllDeg[$pi] = $strongAllDeg[$pi] + 1;
                    $strongAllCount++;
                }
            }
            $revDeg[$ci] = $revDeg[$ci] + 1;
        }
        $stmt->closeCursor();
        $this->edge_count = $edgeCount;

        // Build offsets via prefix sum
        $this->treeOffsets = FFIHelper::new("int32_t[" . ($nc + 1) . "]");
        $this->strongTreeOffsets = FFIHelper::new("int32_t[" . ($nc + 1) . "]");
        $this->allOffsets = FFIHelper::new("int32_t[" . ($nc + 1) . "]");
        $this->strongAllOffsets = FFIHelper::new("int32_t[" . ($nc + 1) . "]");
        $this->revOffsets = FFIHelper::new("int32_t[" . ($nc + 1) . "]");

        $this->treeOffsets[0] = 0;
        $this->strongTreeOffsets[0] = 0;
        $this->allOffsets[0] = 0;
        $this->strongAllOffsets[0] = 0;
        $this->revOffsets[0] = 0;

        for ($i = 0; $i < $nc; $i++) {
            $this->treeOffsets[$i + 1] = $this->treeOffsets[$i] + $treeDeg[$i];
            $this->strongTreeOffsets[$i + 1] = $this->strongTreeOffsets[$i] + $strongTreeDeg[$i];
            $this->allOffsets[$i + 1] = $this->allOffsets[$i] + $allDeg[$i];
            $this->strongAllOffsets[$i + 1] = $this->strongAllOffsets[$i] + $strongAllDeg[$i];
            $this->revOffsets[$i + 1] = $this->revOffsets[$i] + $revDeg[$i];
        }
        unset($treeDeg, $strongTreeDeg, $allDeg, $strongAllDeg, $revDeg);

        // Allocate edge arrays
        $this->treeEdges = FFIHelper::new("int32_t[" . max($treeCount, 1) . "]");
        $this->strongTreeEdges = FFIHelper::new("int32_t[" . max($strongTreeCount, 1) . "]");
        $this->allEdges = FFIHelper::new("int32_t[" . max($allCount, 1) . "]");
        $this->strongAllEdges = FFIHelper::new("int32_t[" . max($strongAllCount, 1) . "]");
        $this->revEdges = FFIHelper::new("int32_t[" . max($edgeCount, 1) . "]");

        // Write positions (FFI)
        $treeP = FFIHelper::new("int32_t[{$nc}]");
        $streeP = FFIHelper::new("int32_t[{$nc}]");
        $allP = FFIHelper::new("int32_t[{$nc}]");
        $sallP = FFIHelper::new("int32_t[{$nc}]");
        $revP = FFIHelper::new("int32_t[{$nc}]");
        for ($i = 0; $i < $nc; $i++) {
            $treeP[$i] = $this->treeOffsets[$i];
            $streeP[$i] = $this->strongTreeOffsets[$i];
            $allP[$i] = $this->allOffsets[$i];
            $sallP[$i] = $this->strongAllOffsets[$i];
            $revP[$i] = $this->revOffsets[$i];
        }

        // Pass 2 (cursor): fill CSR arrays
        $stmt = $db->query($edge_query);
        while ($r = $stmt->fetch(\PDO::FETCH_NUM)) {
            $parent = $r[0] === null ? -1 : (int)$r[0];
            $child = (int)$r[1];
            $is_tree = (int)$r[2];
            $is_strong = ((string)($r[3] ?? 'strong')) === 'strong';

            $pi = $this->nodeIdToIndex($parent);
            $ci = $this->nodeIdToIndex($child);

            if ($is_tree) {
                $p = (int)$treeP[$pi];
                $this->treeEdges[$p] = $ci;
                $treeP[$pi] = $p + 1;
                if ($is_strong) {
                    $p = (int)$streeP[$pi];
                    $this->strongTreeEdges[$p] = $ci;
                    $streeP[$pi] = $p + 1;
                }
            }
            if ($parent !== -1) {
                $p = (int)$allP[$pi];
                $this->allEdges[$p] = $ci;
                $allP[$pi] = $p + 1;
                if ($is_strong) {
                    $p = (int)$sallP[$pi];
                    $this->strongAllEdges[$p] = $ci;
                    $sallP[$pi] = $p + 1;
                }
            }
            $p = (int)$revP[$ci];
            $this->revEdges[$p] = $parent;
            $revP[$ci] = $p + 1;
        }
        $stmt->closeCursor();
        unset($treeP, $streeP, $allP, $sallP, $revP);
    }

    /**
     * Override: skip building scc_adjacency PHP array entirely.
     * computeSccFfiUnified resolves canonical IDs inline during Tarjan.
     */
    #[\Override]
    protected function buildSccAdjacency(): void
    {
        // Intentionally empty — canonical resolution is done inline
        // in computeSccFfiUnified using the strongAll CSR directly.
    }

    /** @psalm-suppress UnsupportedReferenceUsage */
    private function computeSubtreeSizesFfi(): void
    {
        $visited = [];

        $stack = [];
        foreach ($this->roots as $root) {
            $stack[] = [$this->nodeIdToIndex($root), false];
        }

        while ($stack) {
            [$idx, $processed] = array_pop($stack);
            if ($processed) {
                $size = (int)$this->ffiNodeSizes[$idx];
                $start = (int)$this->strongTreeOffsets[$idx];
                $end = (int)$this->strongTreeOffsets[$idx + 1];
                for ($i = $start; $i < $end; $i++) {
                    $size += (int)$this->ffiSubtreeSizes[(int)$this->strongTreeEdges[$i]];
                }
                $this->ffiSubtreeSizes[$idx] = $size;
                $visited[$idx] = true;
            } else {
                $stack[] = [$idx, true];
                $start = (int)$this->strongTreeOffsets[$idx];
                $end = (int)$this->strongTreeOffsets[$idx + 1];
                for ($i = $start; $i < $end; $i++) {
                    $childIdx = (int)$this->strongTreeEdges[$i];
                    if (!isset($visited[$childIdx])) {
                        $stack[] = [$childIdx, false];
                    }
                }
            }
        }
        $this->subtreeSizesComputed = true;
    }

    /**
     * SCC computation using strongAll CSR with inline canonical resolution.
     * Runs Tarjan on CSR indices; when canonical mapping exists, maps
     * each neighbor to its canonical CSR index before visiting. This
     * avoids materializing a separate scc_adjacency PHP array.
     *
     * @psalm-suppress UnsupportedReferenceUsage, MixedArgument
     */
    private function computeSccFfi(): void
    {
        $has_canonical = $this->canonical !== [];

        // If canonical mapping exists, build index-level canonical map:
        // csrIdx → canonical csrIdx. This avoids repeated node_id lookups.
        /** @var array<int, int> $canonIdx  csrIdx → canonical csrIdx */
        $canonIdx = [];
        /** @var array<int, list<int>> $canonical_original_indices canonical csrIdx => original csrIdx list */
        $canonical_original_indices = [];
        if ($has_canonical) {
            for ($v = 0; $v < $this->nodeCount; $v++) {
                $nid = (int)$this->indexToNodeFfi[$v];
                if ($nid === -1) {
                    continue;
                }
                $canon = $this->findCanonical($nid);
                if ($canon !== $nid) {
                    $canonIdx[$v] = $this->nodeIdToIndex($canon);
                }
                $canonical_original_indices[$canonIdx[$v] ?? $v][] = $v;
            }
        }
        /** @var array<int, list<int>> $canonical_neighbors canonical csrIdx => canonical neighbor csrIdx list */
        $canonical_neighbors = [];

        $index_counter = 0;
        $stack = [];
        $on_stack = [];
        $index = [];
        $lowlink = [];
        $sccs = [];

        for ($v = 0; $v < $this->nodeCount; $v++) {
            if ((int)$this->indexToNodeFfi[$v] === -1) {
                continue;
            }
            // Use canonical index if available
            $cv = $canonIdx[$v] ?? $v;
            if (isset($index[$cv])) {
                continue;
            }

            $call_stack = [[$cv, 0]];
            $index[$cv] = $lowlink[$cv] = $index_counter++;
            $stack[] = $cv;
            $on_stack[$cv] = true;

            while ($call_stack) {
                [$node, $ci] = array_pop($call_stack);

                if ($has_canonical) {
                    if (!isset($canonical_neighbors[$node])) {
                        $neighbors = [];
                        $seen = [];
                        foreach ($canonical_original_indices[$node] ?? [$node] as $oi) {
                            $start = (int)$this->strongAllOffsets[$oi];
                            $end = (int)$this->strongAllOffsets[$oi + 1];
                            for ($j = $start; $j < $end; $j++) {
                                $w = (int)$this->strongAllEdges[$j];
                                $cw = $canonIdx[$w] ?? $w;
                                if ($cw !== $node && !isset($seen[$cw])) {
                                    $seen[$cw] = true;
                                    $neighbors[] = $cw;
                                }
                            }
                        }
                        $canonical_neighbors[$node] = $neighbors;
                    }
                    $neighbors = $canonical_neighbors[$node];
                } else {
                    $neighbors = [];
                    $seen = [];
                    $start = (int)$this->strongAllOffsets[$node];
                    $end = (int)$this->strongAllOffsets[$node + 1];
                    for ($j = $start; $j < $end; $j++) {
                        $w = (int)$this->strongAllEdges[$j];
                        if ($w !== $node && !isset($seen[$w])) {
                            $seen[$w] = true;
                            $neighbors[] = $w;
                        }
                    }
                }
                $count = count($neighbors);

                $found_unvisited = false;
                for ($i = $ci; $i < $count; $i++) {
                    $w = $neighbors[$i];
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
                        /** @var list<int> $scc CSR indices (canonical) */
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
                        $lowlink[$parent_frame[0]] = min(
                            $lowlink[$parent_frame[0]],
                            $lowlink[$node]
                        );
                    }
                }
            }
        }

        // When canonical mapping was used, SCCs contain canonical CSR indices.
        // Expand to all original CSR indices for profile building.
        if ($has_canonical) {
            foreach ($sccs as &$scc) {
                $expanded = [];
                foreach ($scc as $canonical_idx) {
                    foreach ($canonical_original_indices[$canonical_idx] ?? [$canonical_idx] as $original_idx) {
                        $expanded[] = $original_idx;
                    }
                }
                $scc = $expanded;
            }
            unset($scc);
        }

        $this->buildSccProfilesFfi($sccs);
    }

    /**
     * Build SCC profiles from CSR index-based SCCs.
     *
     * @param list<list<int>> $sccs Each SCC is a list of CSR indices
     */
    private function buildSccProfilesFfi(array $sccs): void
    {
        // Build SCC profiles (convert CSR indices back to node IDs)
        foreach ($sccs as $scc_id => $scc_indices) {
            $scc_nodes = [];
            foreach ($scc_indices as $idx) {
                $scc_nodes[] = $this->indexToNodeId($idx);
            }

            $scc_set = array_flip($scc_indices);
            $total_size = 0;
            $class_counts = [];

            foreach ($scc_indices as $idx) {
                $node_id = $this->indexToNodeId($idx);
                $this->ffiNodeToScc[$idx] = $scc_id;
                $total_size += (int)$this->ffiNodeSizes[$idx];
                $classId = (int)$this->nodeClassIds[$idx];
                if ($classId >= 0) {
                    $cls = $this->classDict[$classId];
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
                    $parentIdx = $this->nodeIdToIndex($parentNodeId);
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
                'single_owner_likelihood' => $ext_in <= 2
                    ? 'high'
                    : ($ext_in <= 10 ? 'medium' : 'low'),
            ];
        }
    }
}
