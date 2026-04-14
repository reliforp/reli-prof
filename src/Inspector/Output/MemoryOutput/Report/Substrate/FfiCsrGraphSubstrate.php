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

use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Format;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
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
 * @psalm-suppress InaccessibleMethod
 * @psalm-suppress InvalidCast
 * @psalm-suppress PossiblyNullOperand
 * @psalm-suppress InvalidOperand
 * @psalm-suppress MissingConstructor
 * @psalm-suppress PossiblyNullPropertyFetch
 * @psalm-suppress UndefinedPropertyFetch
 * @psalm-suppress InvalidPropertyFetch
 * @psalm-suppress PossiblyInvalidArrayAccess
 * @psalm-suppress MixedArrayOffset
 * @psalm-suppress PossiblyNullArgument
 * @psalm-suppress PossiblyUndefinedVariable
 * @psalm-suppress MixedArrayTypeCoercion
 * @psalm-suppress MixedOperand
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
    // int32 (not int16) per node: dumps with anonymous classes / closures
    // can easily produce 32k+ unique class names, which silently wrapped
    // around when this was int16 and pointed every overflowed node at the
    // wrong dict slot.
    private \FFI\CData $nodeClassIds; // int32_t[nodeCount], -1 = no class

    // Tree-edge link_name index: an int32 per child CSR slot (-1 = no
    // tree edge) plus an interned dict. Built during loadEdgesFfi so
    // report passes get O(1) link_name lookups without per-row SQL.
    //
    // int16 was the original choice but it overflowed on real captures:
    // array elements show up as link_name = the integer key string ("0",
    // "1", ...), so any dump containing arrays with thousands of distinct
    // numeric keys blew through 32767 entries and treeLinkIds wrapped to
    // negative — collapsing some nodes to "null link_name" and pointing
    // others at the wrong dict slot. Result: BlameAllocationPass and the
    // walker passes (DrillDownPass etc.) emitted random numeric strings
    // instead of real names. int32 makes the wrap unreachable in practice.
    /** @var list<string> link_id → link_name */
    private array $linkDict = [];
    /** @var array<string, int> link_name → link_id */
    private array $linkDictReverse = [];
    private ?\FFI\CData $treeLinkIds = null;     // int32_t[nodeCount], -1 = no tree edge
    private ?\FFI\CData $treeParentIdx = null;   // int32_t[nodeCount], -1 = no tree parent

    // Per-node context type ("PhpReferenceContext", etc.). Same shape
    // as the class dictionary above — int16 per node plus small dict.
    /** @var list<string> type_id → type name */
    private array $nodeTypeDict = [];
    /** @var array<string, int> type name → type_id */
    private array $nodeTypeDictReverse = [];
    private ?\FFI\CData $nodeTypeIds = null;     // int16_t[nodeCount], -1 = unknown

    private bool $subtreeSizesComputed = false;
    private int $nodeSizesSum = 0;

    /**
     * Rows per chunked fetchAll inside the loader paths. Plumbed in
     * from GraphSubstrate::createFromDb so the user can size it for
     * their memory budget via the `--substrate-bulk-fetch-chunk` CLI
     * flag. Default keeps per-chunk peak under ~80 MB on the wide
     * loadEdgesFfi row layout.
     */
    private int $bulkFetchChunk = 200000;

    /** @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedPropertyTypeCoercion */
    #[\Override]
    public static function loadFromDb(
        \PDO $db,
        int $run_id,
        int $bulk_fetch_chunk = 200000,
    ): static {
        $substrate = new self();
        if ($bulk_fetch_chunk > 0) {
            $substrate->bulkFetchChunk = $bulk_fetch_chunk;
        }
        $substrate->loadNodeSizesFfi($db, $run_id);
        $substrate->loadNodeTypesFfi($db, $run_id);
        $substrate->loadEdgesFfi($db, $run_id);
        $substrate->loadAddressMapping($db, $run_id);
        $substrate->buildSccAdjacency();
        $substrate->computeSubtreeSizesFfi();
        $substrate->computeSccFfi();
        return $substrate;
    }

    /**
     * Load the substrate directly from a .rmem binary file.
     *
     * When the file is mmap'd, uses FFI::cast to access rows as
     * packed C structs (NodeRow/EdgeRow/LocationRow) for zero-copy
     * reads. Falls back to unpack() on non-mmap readers.
     *
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedPropertyTypeCoercion
     */
    #[\Override]
    public static function loadFromBinary(BinaryReader $reader): static
    {
        $substrate = new self();
        $dict = $reader->getStringDict();

        $nodeRowCount = $reader->getSectionElementCount(Format::SECTION_NODES);

        // Try mmap + struct cast path
        $nodeRows = $reader->castSection(Format::SECTION_NODES, 'NodeRow');

        // ---- Phase 1: Build node ID mapping from the nodes section ----
        /** @var array<int, true> $all_node_ids */
        $all_node_ids = [];
        if ($nodeRows !== null) {
            for ($i = 0; $i < $nodeRowCount; $i++) {
                $all_node_ids[(int)$nodeRows[$i]->node_id] = true;
            }
        } else {
            $nodeData = $reader->getSectionData(Format::SECTION_NODES);
            $offset = 0;
            for ($i = 0; $i < $nodeRowCount; $i++) {
                $node_id = unpack('V', $nodeData, $offset)[1];
                $all_node_ids[(int)$node_id] = true;
                $offset += Format::NODE_ROW_SIZE;
            }
        }
        // Include -1 sentinel for the synthetic root parent.
        $all_node_ids[-1] = true;

        $substrate->nodeCount = count($all_node_ids);
        $nc = $substrate->nodeCount;
        $substrate->indexToNodeFfi = FFIHelper::new("int32_t[{$nc}]");

        $idx = 0;
        $minNodeId = PHP_INT_MAX;
        $maxNodeId = PHP_INT_MIN;
        foreach ($all_node_ids as $node_id => $_) {
            $substrate->indexToNodeFfi[$idx] = $node_id;
            if ($node_id < $minNodeId) {
                $minNodeId = $node_id;
            }
            if ($node_id > $maxNodeId) {
                $maxNodeId = $node_id;
            }
            $idx++;
        }

        // Decide nodeToIndex strategy
        $range = $maxNodeId - $minNodeId + 1;
        if ($range > 0 && $range < $nc * 4 && $range < 100_000_000) {
            $substrate->directIndexOffset = -$minNodeId;
            $directSize = $range;
            $substrate->directIndexSize = $directSize;
            $substrate->nodeToIndexDirect = FFIHelper::new("int32_t[{$directSize}]");
            for ($i = 0; $i < $directSize; $i++) {
                $substrate->nodeToIndexDirect[$i] = -1;
            }
            for ($i = 0; $i < $nc; $i++) {
                $nid = (int)$substrate->indexToNodeFfi[$i];
                $slot = $nid + $substrate->directIndexOffset;
                $substrate->nodeToIndexDirect[$slot] = $i;
            }
        } else {
            $substrate->nodeToIndexPhp = [];
            for ($i = 0; $i < $nc; $i++) {
                $substrate->nodeToIndexPhp[(int)$substrate->indexToNodeFfi[$i]] = $i;
            }
        }
        unset($all_node_ids);

        // Allocate per-node FFI arrays
        $substrate->ffiNodeSizes = FFIHelper::new("int64_t[{$nc}]");
        $substrate->ffiSubtreeSizes = FFIHelper::new("int64_t[{$nc}]");
        $substrate->ffiNodeToScc = FFIHelper::new("int32_t[{$nc}]");
        $substrate->nodeClassIds = FFIHelper::new("int32_t[{$nc}]");
        for ($i = 0; $i < $nc; $i++) {
            $substrate->ffiNodeToScc[$i] = -1;
            $substrate->nodeClassIds[$i] = -1;
        }

        // ---- Phase 2: Load node types from nodes section ----
        $substrate->nodeTypeIds = FFIHelper::new("int16_t[{$nc}]");
        for ($i = 0; $i < $nc; $i++) {
            $substrate->nodeTypeIds[$i] = -1;
        }

        // Localise for the inner loop
        $directMap = $substrate->nodeToIndexDirect;
        $directOffset = $substrate->directIndexOffset;
        $directSize = $substrate->directIndexSize;
        $phpMap = $substrate->nodeToIndexPhp;

        for ($i = 0; $i < $nodeRowCount; $i++) {
            if ($nodeRows !== null) {
                $node_id = (int)$nodeRows[$i]->node_id;
                $type_id = (int)$nodeRows[$i]->type_id;
                $canonical_id = (int)$nodeRows[$i]->canonical_id;
            } else {
                $row = unpack('Vnode_id/Vcanonical_id/Vtype_id/Vclass_id', $nodeData, $i * Format::NODE_ROW_SIZE);
                $node_id = (int)$row['node_id'];
                $type_id = (int)$row['type_id'];
                $canonical_id = (int)$row['canonical_id'];
            }

            if ($directMap !== null) {
                $slot = $node_id + $directOffset;
                $csrIdx = ($slot < 0 || $slot >= $directSize) ? -1 : (int)$directMap[$slot];
            } else {
                $csrIdx = $phpMap[$node_id] ?? -1;
            }
            if ($csrIdx < 0) {
                continue;
            }

            $type = $dict->lookup($type_id);
            if ($type !== null) {
                if (!isset($substrate->nodeTypeDictReverse[$type])) {
                    $substrate->nodeTypeDictReverse[$type] = count($substrate->nodeTypeDict);
                    $substrate->nodeTypeDict[] = $type;
                }
                $substrate->nodeTypeIds[$csrIdx] = $substrate->nodeTypeDictReverse[$type];
            }

            if ($canonical_id !== 0 && $canonical_id !== $node_id) {
                $substrate->canonical[$node_id] = $canonical_id;
                $substrate->canonical[$canonical_id] = $canonical_id;
            }
        }
        unset($nodeData, $nodeRows);

        // ---- Phase 3: Load node sizes + classes + canonical address grouping ----
        // Single pass over the locations section for all three purposes.
        /** @var array<int, list<int>> $addr_to_nodes address → [node_id, ...] for canonical */
        $addr_to_nodes = [];
        if ($reader->hasSection(Format::SECTION_LOCATIONS)) {
            $locCount = $reader->getSectionElementCount(Format::SECTION_LOCATIONS);
            $locRows = $reader->castSection(Format::SECTION_LOCATIONS, 'LocationRow');
            $locData = $locRows === null ? $reader->getSectionData(Format::SECTION_LOCATIONS) : null;
            $ffiNodeSizes = $substrate->ffiNodeSizes;
            $nodeClassIds = $substrate->nodeClassIds;

            $substrate->nodeSizesSum = 0;
            for ($i = 0; $i < $locCount; $i++) {
                if ($locRows !== null) {
                    $node_id = (int)$locRows[$i]->node_id;
                    $class_id = (int)$locRows[$i]->class_id;
                    $size = (int)$locRows[$i]->size;
                    $address = (int)$locRows[$i]->address;
                } else {
                    $off = $i * Format::LOCATION_ROW_SIZE;
                    $node_id = unpack('V', $locData, $off)[1];
                    $class_id = unpack('V', $locData, $off + 8)[1];
                    $size = unpack('P', $locData, $off + 20)[1];
                    $address = unpack('P', $locData, $off + 12)[1];
                }

                if ($directMap !== null) {
                    $slot = $node_id + $directOffset;
                    $csrIdx = ($slot < 0 || $slot >= $directSize) ? -1 : (int)$directMap[$slot];
                } else {
                    $csrIdx = $phpMap[$node_id] ?? -1;
                }
                if ($csrIdx >= 0) {
                    $ffiNodeSizes[$csrIdx] = (int)$ffiNodeSizes[$csrIdx] + (int)$size;
                    $substrate->nodeSizesSum += (int)$size;

                    if ((int)$class_id !== Format::NULL_STRING_ID && (int)$nodeClassIds[$csrIdx] === -1) {
                        $className = $dict->lookup((int)$class_id);
                        if ($className !== null) {
                            if (!isset($substrate->classDictReverse[$className])) {
                                $substrate->classDictReverse[$className] = count($substrate->classDict);
                                $substrate->classDict[] = $className;
                            }
                            $nodeClassIds[$csrIdx] = $substrate->classDictReverse[$className];
                        }
                    }
                }

                // Canonical address grouping (was Phase 3.5)
                if ($address !== 0) {
                    $addr_to_nodes[$address][] = $node_id;
                }
            }
            unset($locData, $locRows);
        }

        // Canonical: group nodes by address, assign min(node_id) as canonical
        foreach ($addr_to_nodes as $nodes) {
            $unique_nodes = array_values(array_unique($nodes));
            if (count($unique_nodes) <= 1) {
                continue;
            }
            $canon = min($unique_nodes);
            foreach ($unique_nodes as $nid) {
                $substrate->canonical[$nid] = $canon;
                $substrate->canonical[$canon] = $canon;
            }
        }
        unset($addr_to_nodes);

        if ($substrate->canonical !== []) {
            foreach ($substrate->canonical as $node => $parent) {
                $canon = $substrate->findCanonical($node);
                $substrate->canonicalToOriginals[$canon][] = $node;
            }
            foreach ($substrate->canonicalToOriginals as $canon => $nodes) {
                $substrate->canonicalToOriginals[$canon] = array_values(array_unique($nodes));
            }
        }

        // ---- Phase 4: Load edges → build CSR ----
        $edgeCount = $reader->getSectionElementCount(Format::SECTION_EDGES);
        $substrate->edge_count = $edgeCount;
        $rootParentIdx = $substrate->nodeIdToIndex(-1);

        $substrate->treeOffsets = FFIHelper::new("int32_t[" . ($nc + 1) . "]");
        $substrate->strongTreeOffsets = FFIHelper::new("int32_t[" . ($nc + 1) . "]");
        $substrate->allOffsets = FFIHelper::new("int32_t[" . ($nc + 1) . "]");
        $substrate->strongAllOffsets = FFIHelper::new("int32_t[" . ($nc + 1) . "]");
        $substrate->revOffsets = FFIHelper::new("int32_t[" . ($nc + 1) . "]");

        if ($edgeCount === 0) {
            $substrate->treeEdges = FFIHelper::new("int32_t[1]");
            $substrate->strongTreeEdges = FFIHelper::new("int32_t[1]");
            $substrate->allEdges = FFIHelper::new("int32_t[1]");
            $substrate->strongAllEdges = FFIHelper::new("int32_t[1]");
            $substrate->revEdges = FFIHelper::new("int32_t[1]");
            $substrate->subtreeSizesComputed = false;
            return $substrate;
        }

        // Staging buffers
        $stageParentIdx = FFIHelper::new("int32_t[{$edgeCount}]");
        $stageChildIdx = FFIHelper::new("int32_t[{$edgeCount}]");
        $stageFlags = FFIHelper::new("int8_t[{$edgeCount}]");

        $substrate->treeLinkIds = FFIHelper::new("int32_t[{$nc}]");
        $substrate->treeParentIdx = FFIHelper::new("int32_t[{$nc}]");
        for ($k = 0; $k < $nc; $k++) {
            $substrate->treeLinkIds[$k] = -1;
            $substrate->treeParentIdx[$k] = -1;
        }

        $treeDeg = FFIHelper::new("int32_t[{$nc}]");
        $strongTreeDeg = FFIHelper::new("int32_t[{$nc}]");
        $allDeg = FFIHelper::new("int32_t[{$nc}]");
        $strongAllDeg = FFIHelper::new("int32_t[{$nc}]");
        $revDeg = FFIHelper::new("int32_t[{$nc}]");

        $treeCount = 0;
        $strongTreeCount = 0;
        $allCount = 0;
        $strongAllCount = 0;

        $treeLinkIds = $substrate->treeLinkIds;
        $treeParentIdx = $substrate->treeParentIdx;

        $edgeRows = $reader->castSection(Format::SECTION_EDGES, 'EdgeRow');
        $edgeData = $edgeRows === null ? $reader->getSectionData(Format::SECTION_EDGES) : null;
        for ($i = 0; $i < $edgeCount; $i++) {
            if ($edgeRows !== null) {
                $raw_parent = (int)$edgeRows[$i]->parent_node_id;
                $child = (int)$edgeRows[$i]->child_node_id;
                $link_name_id = (int)$edgeRows[$i]->link_name_id;
                $is_tree = (int)$edgeRows[$i]->is_tree;
                $is_strong = (int)$edgeRows[$i]->strength === 0;
            } else {
                $row = unpack(
                    'Vparent_node_id/Vchild_node_id/Vlink_name_id/Cis_tree/Cstrength',
                    $edgeData,
                    $i * Format::EDGE_ROW_SIZE,
                );
                $raw_parent = (int)$row['parent_node_id'];
                $child = (int)$row['child_node_id'];
                $link_name_id = (int)$row['link_name_id'];
                $is_tree = (int)$row['is_tree'];
                $is_strong = (int)$row['strength'] === 0;
            }
            $parentIsRoot = $raw_parent === 0xFFFFFFFF;
            $parent = $parentIsRoot ? -1 : $raw_parent;

            if ($directMap !== null) {
                $slot = $parent + $directOffset;
                $pi = ($slot < 0 || $slot >= $directSize) ? -1 : (int)$directMap[$slot];
                $slot = $child + $directOffset;
                $ci = ($slot < 0 || $slot >= $directSize) ? -1 : (int)$directMap[$slot];
            } else {
                $pi = $phpMap[$parent] ?? -1;
                $ci = $phpMap[$child] ?? -1;
            }

            $stageParentIdx[$i] = $pi;
            $stageChildIdx[$i] = $ci;
            $stageFlags[$i] = $is_tree | ($is_strong ? 2 : 0);

            if ($is_tree) {
                $treeDeg[$pi] = $treeDeg[$pi] + 1;
                $treeCount++;
                if ($is_strong) {
                    $strongTreeDeg[$pi] = $strongTreeDeg[$pi] + 1;
                    $strongTreeCount++;
                }
                if ($parentIsRoot) {
                    $substrate->roots[] = $child;
                }
                $link_name = $dict->lookup($link_name_id);
                if ($link_name !== null) {
                    if (!isset($substrate->linkDictReverse[$link_name])) {
                        $substrate->linkDictReverse[$link_name] = count($substrate->linkDict);
                        $substrate->linkDict[] = $link_name;
                    }
                    $treeLinkIds[$ci] = $substrate->linkDictReverse[$link_name];
                }
                $treeParentIdx[$ci] = $pi;
            }
            if (!$parentIsRoot) {
                $allDeg[$pi] = $allDeg[$pi] + 1;
                $allCount++;
                if ($is_strong) {
                    $strongAllDeg[$pi] = $strongAllDeg[$pi] + 1;
                    $strongAllCount++;
                }
            }
            $revDeg[$ci] = $revDeg[$ci] + 1;
        }
        unset($edgeData, $edgeRows);

        // Build offsets via prefix sum
        $substrate->treeOffsets[0] = 0;
        $substrate->strongTreeOffsets[0] = 0;
        $substrate->allOffsets[0] = 0;
        $substrate->strongAllOffsets[0] = 0;
        $substrate->revOffsets[0] = 0;
        for ($k = 0; $k < $nc; $k++) {
            $substrate->treeOffsets[$k + 1] = $substrate->treeOffsets[$k] + $treeDeg[$k];
            $substrate->strongTreeOffsets[$k + 1] = $substrate->strongTreeOffsets[$k] + $strongTreeDeg[$k];
            $substrate->allOffsets[$k + 1] = $substrate->allOffsets[$k] + $allDeg[$k];
            $substrate->strongAllOffsets[$k + 1] = $substrate->strongAllOffsets[$k] + $strongAllDeg[$k];
            $substrate->revOffsets[$k + 1] = $substrate->revOffsets[$k] + $revDeg[$k];
        }
        unset($treeDeg, $strongTreeDeg, $allDeg, $strongAllDeg, $revDeg);

        $substrate->treeEdges = FFIHelper::new("int32_t[" . max($treeCount, 1) . "]");
        $substrate->strongTreeEdges = FFIHelper::new("int32_t[" . max($strongTreeCount, 1) . "]");
        $substrate->allEdges = FFIHelper::new("int32_t[" . max($allCount, 1) . "]");
        $substrate->strongAllEdges = FFIHelper::new("int32_t[" . max($strongAllCount, 1) . "]");
        $substrate->revEdges = FFIHelper::new("int32_t[{$edgeCount}]");

        $treeP = FFIHelper::new("int32_t[{$nc}]");
        $streeP = FFIHelper::new("int32_t[{$nc}]");
        $allP = FFIHelper::new("int32_t[{$nc}]");
        $sallP = FFIHelper::new("int32_t[{$nc}]");
        $revP = FFIHelper::new("int32_t[{$nc}]");
        for ($k = 0; $k < $nc; $k++) {
            $treeP[$k] = $substrate->treeOffsets[$k];
            $streeP[$k] = $substrate->strongTreeOffsets[$k];
            $allP[$k] = $substrate->allOffsets[$k];
            $sallP[$k] = $substrate->strongAllOffsets[$k];
            $revP[$k] = $substrate->revOffsets[$k];
        }

        // Second pass: walk staged edges into CSR
        for ($k = 0; $k < $edgeCount; $k++) {
            $pi = (int)$stageParentIdx[$k];
            $ci = (int)$stageChildIdx[$k];
            $flags = (int)$stageFlags[$k];
            $is_tree = ($flags & 1) !== 0;
            $is_strong = ($flags & 2) !== 0;

            if ($is_tree) {
                $p = (int)$treeP[$pi];
                $substrate->treeEdges[$p] = $ci;
                $treeP[$pi] = $p + 1;
                if ($is_strong) {
                    $p = (int)$streeP[$pi];
                    $substrate->strongTreeEdges[$p] = $ci;
                    $streeP[$pi] = $p + 1;
                }
            }
            if ($pi !== $rootParentIdx) {
                $p = (int)$allP[$pi];
                $substrate->allEdges[$p] = $ci;
                $allP[$pi] = $p + 1;
                if ($is_strong) {
                    $p = (int)$sallP[$pi];
                    $substrate->strongAllEdges[$p] = $ci;
                    $sallP[$pi] = $p + 1;
                }
            }
            $p = (int)$revP[$ci];
            $substrate->revEdges[$p] = $pi;
            $revP[$ci] = $p + 1;
        }
        unset($treeP, $streeP, $allP, $sallP, $revP);
        unset($stageParentIdx, $stageChildIdx, $stageFlags);

        // ---- Phase 5: compute derived structures ----
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
    public function getNodeType(int $nodeId): ?string
    {
        if ($this->nodeTypeIds === null) {
            return null;
        }
        $idx = $this->nodeIdToIndex($nodeId);
        if ($idx < 0) {
            return null;
        }
        $typeId = (int)$this->nodeTypeIds[$idx];
        if ($typeId < 0) {
            return null;
        }
        return $this->nodeTypeDict[$typeId] ?? null;
    }

    #[\Override]
    public function getTreeLinkName(int $child_node_id): ?string
    {
        if ($this->treeLinkIds === null) {
            return null;
        }
        $idx = $this->nodeIdToIndex($child_node_id);
        if ($idx < 0) {
            return null;
        }
        $link_id = (int)$this->treeLinkIds[$idx];
        if ($link_id < 0) {
            return null;
        }
        return $this->linkDict[$link_id] ?? null;
    }

    #[\Override]
    public function getTreeParentNodeId(int $child_node_id): ?int
    {
        if ($this->treeParentIdx === null) {
            return null;
        }
        $idx = $this->nodeIdToIndex($child_node_id);
        if ($idx < 0) {
            return null;
        }
        $parent_idx = (int)$this->treeParentIdx[$idx];
        if ($parent_idx < 0) {
            return null;
        }
        $parent_id = (int)$this->indexToNodeFfi[$parent_idx];
        return $parent_id === -1 ? null : $parent_id;
    }

    #[\Override]
    public function hasTreeLinkIndex(): bool
    {
        return $this->treeLinkIds !== null;
    }

    /**
     * @return iterable<int>
     */
    #[\Override]
    public function iterateTreeChildrenByLinkName(string $link_name): iterable
    {
        if ($this->treeLinkIds === null) {
            return;
        }
        $link_id = $this->linkDictReverse[$link_name] ?? null;
        if ($link_id === null) {
            return;
        }
        $n = $this->nodeCount;
        for ($i = 0; $i < $n; $i++) {
            if ((int)$this->treeLinkIds[$i] === $link_id) {
                yield (int)$this->indexToNodeFfi[$i];
            }
        }
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
                    // revEdges stores CSR indices (matching the rest of the
                    // CSR arrays); translate back to node_ids on the way out.
                    $parents[] = (int)$this->indexToNodeFfi[(int)$this->revEdges[$j]];
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
        // Two streaming passes, both chunked via prepared statements
        // paginated by node_id over the (run_id, node_id) PRIMARY KEY
        // / covering index, so paginating by `node_id > ?` is a
        // forward seek per chunk:
        //
        //   Pass 1: chunked range scan over context_nodes — the
        //     authoritative source of node_ids. PdoContextTreeSink::
        //     emitNode() writes a context_nodes row for every emitted
        //     node, and after the 29f9f258 collector-job fix every
        //     node_id that ever lands in context_edges (parent or
        //     child) corresponds to a context_nodes row in the same
        //     run. Verified empirically by querying the analyze DB
        //     for the set difference (it's empty); historically there
        //     was a UNION DISTINCT over context_edges here as a
        //     backstop, but the underlying invariant is now solid
        //     and the backstop's table scan was paying nothing back.
        //
        //   Pass 2: chunked GROUP BY over context_node_locations to
        //     fill node sizes + class_name dictionary (this used to
        //     be a single monolithic fetchAll on report12.rbt and
        //     dominated loadNodeSizesFfi wall).
        $chunk = $this->bulkFetchChunk > 0 ? $this->bulkFetchChunk : 200000;

        // Pass 1: chunked context_nodes range scan.
        $stmt = $db->prepare(
            "SELECT node_id FROM context_nodes
             WHERE run_id = ? AND node_id > ?
             ORDER BY node_id
             LIMIT {$chunk}"
        );
        $all_node_ids = [];
        $last_node_id = PHP_INT_MIN;
        while (true) {
            $stmt->execute([$run_id, $last_node_id]);
            $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $nid) {
                $all_node_ids[(int)$nid] = true;
                $last_node_id = (int)$nid;
            }
            if (count($rows) < $chunk) {
                break;
            }
        }

        // Include -1 sentinel for the synthetic root parent.
        $all_node_ids[-1] = true;

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
        $this->nodeClassIds = FFIHelper::new("int32_t[{$this->nodeCount}]");

        // Initialize
        for ($i = 0; $i < $this->nodeCount; $i++) {
            $this->ffiNodeToScc[$i] = -1;
            $this->nodeClassIds[$i] = -1;
        }

        // Pass 2: stream the size + class aggregation in chunks. The
        // previous monolithic fetchAll on a multi-million-row GROUP BY
        // was the largest single PHP allocation in report; chunking
        // keeps the per-chunk peak bounded by `chunk` rows × 3 cols.
        //
        // group_concat(DISTINCT class_name) used to live here, but the
        // consumer below treats $r[2] as a SINGLE class-name string and
        // uses it as a dict key — every concrete dump only has at most
        // one non-null class_name per node_id (a node IS one PHP value,
        // so it has one class). min() picks the same name in single-
        // class rows and automatically skips NULL-only groups, dropping
        // the per-row distinct hash entirely.
        //
        // Localise hot reads (same pattern loadEdgesFfi uses) so the
        // inner row loop never goes through `$this->` for nodeIdToIndex
        // or the dict updates.
        $directMap = $this->nodeToIndexDirect;
        $directOffset = $this->directIndexOffset;
        $directSize = $this->directIndexSize;
        $phpMap = $this->nodeToIndexPhp;
        $ffiNodeSizes = $this->ffiNodeSizes;
        $nodeClassIds = $this->nodeClassIds;
        // The class dict is touched only on cache miss (a few hundred
        // to a few thousand times for typical heaps), so reading the
        // properties directly via $this-> is fine — no need for the
        // by-reference aliasing dance, which Psalm can't model.

        $stmt = $db->prepare(
            "SELECT node_id, sum(size) as s, min(class_name) as cls
             FROM context_node_locations
             WHERE run_id = ? AND node_id > ?
             GROUP BY node_id
             ORDER BY node_id
             LIMIT {$chunk}"
        );
        $this->nodeSizesSum = 0;
        $last_node_id = PHP_INT_MIN;
        while (true) {
            $stmt->execute([$run_id, $last_node_id]);
            $rows = $stmt->fetchAll(\PDO::FETCH_NUM);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $r) {
                $node_id = (int)$r[0];
                $size = (int)$r[1];
                if ($directMap !== null) {
                    $slot = $node_id + $directOffset;
                    $csrIdx = ($slot < 0 || $slot >= $directSize)
                        ? -1
                        : (int)$directMap[$slot];
                } else {
                    $csrIdx = $phpMap[$node_id] ?? -1;
                }
                if ($csrIdx < 0) {
                    // Defensive: should not happen since context_nodes is
                    // a strict superset of context_node_locations.node_id.
                    $last_node_id = $node_id;
                    continue;
                }
                $ffiNodeSizes[$csrIdx] = $size;
                $this->nodeSizesSum += $size;
                if ($r[2] !== null) {
                    $className = (string)$r[2];
                    if (!isset($this->classDictReverse[$className])) {
                        $this->classDictReverse[$className] = count($this->classDict);
                        $this->classDict[] = $className;
                    }
                    $nodeClassIds[$csrIdx] = $this->classDictReverse[$className];
                }
                $last_node_id = $node_id;
            }
            if (count($rows) < $chunk) {
                break;
            }
        }
    }

    /**
     * Per-node context type index. One pass over context_nodes
     * fills an int16-per-CSR-slot dictionary; the path-walker passes
     * (DrillDownPass / ChokePointPass / TopStringsPass / TopArraysPass /
     * CycleClusterPass::findEntryPath) read it via getNodeType
     * instead of issuing per-node prepared statements.
     *
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument
     */
    private function loadNodeTypesFfi(\PDO $db, int $run_id): void
    {
        $nc = $this->nodeCount;
        $this->nodeTypeIds = FFIHelper::new("int16_t[{$nc}]");
        for ($i = 0; $i < $nc; $i++) {
            $this->nodeTypeIds[$i] = -1;
        }

        // Localise hot reads (same pattern loadEdgesFfi uses) so the
        // inner loop never goes through `$this->` for nodeIdToIndex.
        // The type dict is touched only on cache miss so $this->
        // access on the dict properties is fine.
        $directMap = $this->nodeToIndexDirect;
        $directOffset = $this->directIndexOffset;
        $directSize = $this->directIndexSize;
        $phpMap = $this->nodeToIndexPhp;
        $nodeTypeIds = $this->nodeTypeIds;
        $chunk = $this->bulkFetchChunk;

        // Chunked node_id pagination via the lazy covering index
        // `(run_id, node_id, type)`. Because the index covers all
        // three columns SQLite answers the chunked query as a pure
        // index range scan — no per-row heap lookups.
        $stmt = $db->prepare(
            "SELECT node_id, type FROM context_nodes
             WHERE run_id = ? AND node_id > ?
             ORDER BY node_id
             LIMIT {$chunk}"
        );
        $last_node_id = PHP_INT_MIN;
        while (true) {
            $stmt->execute([$run_id, $last_node_id]);
            $rows = $stmt->fetchAll(\PDO::FETCH_NUM);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $node_id = (int)$row[0];
                if ($directMap !== null) {
                    $slot = $node_id + $directOffset;
                    $idx = ($slot < 0 || $slot >= $directSize)
                        ? -1
                        : (int)$directMap[$slot];
                } else {
                    $idx = $phpMap[$node_id] ?? -1;
                }
                if ($idx >= 0) {
                    $type = (string)$row[1];
                    if (!isset($this->nodeTypeDictReverse[$type])) {
                        $this->nodeTypeDictReverse[$type] = count($this->nodeTypeDict);
                        $this->nodeTypeDict[] = $type;
                    }
                    $nodeTypeIds[$idx] = $this->nodeTypeDictReverse[$type];
                }
                $last_node_id = $node_id;
            }
            if (count($rows) < $chunk) {
                break;
            }
        }
    }

    /**
     * Load edges from DB into CSR arrays via a single SQL pass.
     *
     * The previous implementation issued the same SELECT twice — once
     * to count degrees, once to fill the CSR arrays. ~70% of the
     * function's runtime sat inside PDOStatement::fetch on huge dumps,
     * almost all of it from the redundant second scan. We now stage
     * every edge into compact FFI int arrays during the SQL pass and
     * walk *those* for the CSR fill, eliminating the second SQL
     * round-trip entirely. The staging buffer costs ~9 bytes per edge
     * (parent index int32 + child index int32 + flags int8) and is
     * freed before the function returns.
     *
     * Also fixes a long-standing bug in the rev-edges build: the old
     * code stored the *raw* parent value (which could be -1 for root
     * edges) into revEdges, but csrSlice reads each entry as a CSR
     * index and dereferences indexToNodeFfi[entry]. That meant
     * getAllParents() returned garbage for non-root nodes and crashed
     * with "C array index out of bounds" the moment anyone asked for
     * the parents of a real root. Storing the parent's CSR index $pi
     * (already computed) makes csrSlice's contract hold and the
     * function actually works.
     *
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument
     */
    private function loadEdgesFfi(\PDO $db, int $run_id): void
    {
        $nc = $this->nodeCount;
        $rootParentIdx = $this->nodeIdToIndex(-1);

        // Get an exact edge count up front so the staging arrays can
        // be sized in one allocation. COUNT(*) is fast on the
        // (run_id, *) indexed table and pays back many times over by
        // letting the second pass walk an in-memory FFI buffer.
        $edgeCount = (int)$db->query(
            "SELECT count(*) FROM context_edges WHERE run_id = {$run_id}"
        )->fetchColumn();
        $this->edge_count = $edgeCount;

        $this->treeOffsets = FFIHelper::new("int32_t[" . ($nc + 1) . "]");
        $this->strongTreeOffsets = FFIHelper::new("int32_t[" . ($nc + 1) . "]");
        $this->allOffsets = FFIHelper::new("int32_t[" . ($nc + 1) . "]");
        $this->strongAllOffsets = FFIHelper::new("int32_t[" . ($nc + 1) . "]");
        $this->revOffsets = FFIHelper::new("int32_t[" . ($nc + 1) . "]");

        if ($edgeCount === 0) {
            $this->treeEdges = FFIHelper::new("int32_t[1]");
            $this->strongTreeEdges = FFIHelper::new("int32_t[1]");
            $this->allEdges = FFIHelper::new("int32_t[1]");
            $this->strongAllEdges = FFIHelper::new("int32_t[1]");
            $this->revEdges = FFIHelper::new("int32_t[1]");
            return;
        }

        // Staging buffer: written during the SQL pass, read during the
        // CSR fill pass. Flags layout: bit 0 = is_tree, bit 1 = is_strong.
        $stageParentIdx = FFIHelper::new("int32_t[{$edgeCount}]");
        $stageChildIdx = FFIHelper::new("int32_t[{$edgeCount}]");
        $stageFlags = FFIHelper::new("int8_t[{$edgeCount}]");

        // Tree-edge link_name index: int32 per child slot, -1 = no
        // tree edge. Initialised below to -1 in one pass over the
        // CSR slot space, then filled as we walk tree edges. Must
        // stay int32: see the field-declaration comment for the int16
        // wrap-around bug that motivated the widening.
        $this->treeLinkIds = FFIHelper::new("int32_t[{$nc}]");
        $this->treeParentIdx = FFIHelper::new("int32_t[{$nc}]");
        for ($k = 0; $k < $nc; $k++) {
            $this->treeLinkIds[$k] = -1;
            $this->treeParentIdx[$k] = -1;
        }

        $treeDeg = FFIHelper::new("int32_t[{$nc}]");
        $strongTreeDeg = FFIHelper::new("int32_t[{$nc}]");
        $allDeg = FFIHelper::new("int32_t[{$nc}]");
        $strongAllDeg = FFIHelper::new("int32_t[{$nc}]");
        $revDeg = FFIHelper::new("int32_t[{$nc}]");

        $treeCount = 0;
        $strongTreeCount = 0;
        $allCount = 0;
        $strongAllCount = 0;

        // Localise everything the inner row loop touches. Property
        // access on $this and method dispatch on $this->nodeIdToIndex
        // each cost ~50-100 ns per call, and the loop runs 5M+ times
        // on big captures. The two nodeIdToIndex calls per row alone
        // were ~10M method dispatches before this.
        $directMap = $this->nodeToIndexDirect;
        $directOffset = $this->directIndexOffset;
        $directSize = $this->directIndexSize;
        $phpMap = $this->nodeToIndexPhp;
        $treeLinkIds = $this->treeLinkIds;
        $treeParentIdx = $this->treeParentIdx;
        // The link dict and roots list are touched only on the
        // tree-edge branch (cache miss + once per root edge), not
        // every row, so $this-> access here is fine — Psalm can't
        // model the by-reference aliasing pattern this used to use.

        // Chunked id pagination via the lazy `(run_id, id)` index.
        // `id` is the rowid alias from the schema-level
        // `id INTEGER PRIMARY KEY`, so each chunk runs as an
        // `id > ?` range scan with O(chunk) cost per round trip.
        $i = 0;
        $chunk = $this->bulkFetchChunk;
        $stmt = $db->prepare(
            "SELECT parent_node_id, child_node_id, link_name, is_tree, strength, id
             FROM context_edges
             WHERE run_id = ? AND id > ?
             ORDER BY id
             LIMIT {$chunk}"
        );
        $last_id = 0;
        while (true) {
            $stmt->execute([$run_id, $last_id]);
            $rows = $stmt->fetchAll(\PDO::FETCH_NUM);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $r) {
                $parentIsRoot = $r[0] === null;
                $parent = $parentIsRoot ? -1 : (int)$r[0];
                $child = (int)$r[1];
                $link_name = (string)$r[2];
                $is_tree = (int)$r[3];
                $is_strong = ((string)($r[4] ?? 'strong')) === 'strong';

                if ($directMap !== null) {
                    $slot = $parent + $directOffset;
                    $pi = ($slot < 0 || $slot >= $directSize)
                        ? -1
                        : (int)$directMap[$slot];
                    $slot = $child + $directOffset;
                    $ci = ($slot < 0 || $slot >= $directSize)
                        ? -1
                        : (int)$directMap[$slot];
                } else {
                    $pi = $phpMap[$parent] ?? -1;
                    $ci = $phpMap[$child] ?? -1;
                }

                $stageParentIdx[$i] = $pi;
                $stageChildIdx[$i] = $ci;
                $stageFlags[$i] = $is_tree | ($is_strong ? 2 : 0);
                $i++;

                if ($is_tree) {
                    $treeDeg[$pi] = $treeDeg[$pi] + 1;
                    $treeCount++;
                    if ($is_strong) {
                        $strongTreeDeg[$pi] = $strongTreeDeg[$pi] + 1;
                        $strongTreeCount++;
                    }
                    if ($parentIsRoot) {
                        $this->roots[] = $child;
                    }
                    if (!isset($this->linkDictReverse[$link_name])) {
                        $this->linkDictReverse[$link_name] = count($this->linkDict);
                        $this->linkDict[] = $link_name;
                    }
                    $treeLinkIds[$ci] = $this->linkDictReverse[$link_name];
                    $treeParentIdx[$ci] = $pi;
                }
                if (!$parentIsRoot) {
                    $allDeg[$pi] = $allDeg[$pi] + 1;
                    $allCount++;
                    if ($is_strong) {
                        $strongAllDeg[$pi] = $strongAllDeg[$pi] + 1;
                        $strongAllCount++;
                    }
                }
                $revDeg[$ci] = $revDeg[$ci] + 1;
                $last_id = (int)$r[5];
            }
            if (count($rows) < $chunk) {
                break;
            }
        }

        // Build offsets via prefix sum.
        $this->treeOffsets[0] = 0;
        $this->strongTreeOffsets[0] = 0;
        $this->allOffsets[0] = 0;
        $this->strongAllOffsets[0] = 0;
        $this->revOffsets[0] = 0;

        for ($k = 0; $k < $nc; $k++) {
            $this->treeOffsets[$k + 1] = $this->treeOffsets[$k] + $treeDeg[$k];
            $this->strongTreeOffsets[$k + 1] = $this->strongTreeOffsets[$k] + $strongTreeDeg[$k];
            $this->allOffsets[$k + 1] = $this->allOffsets[$k] + $allDeg[$k];
            $this->strongAllOffsets[$k + 1] = $this->strongAllOffsets[$k] + $strongAllDeg[$k];
            $this->revOffsets[$k + 1] = $this->revOffsets[$k] + $revDeg[$k];
        }
        unset($treeDeg, $strongTreeDeg, $allDeg, $strongAllDeg, $revDeg);

        $this->treeEdges = FFIHelper::new("int32_t[" . max($treeCount, 1) . "]");
        $this->strongTreeEdges = FFIHelper::new("int32_t[" . max($strongTreeCount, 1) . "]");
        $this->allEdges = FFIHelper::new("int32_t[" . max($allCount, 1) . "]");
        $this->strongAllEdges = FFIHelper::new("int32_t[" . max($strongAllCount, 1) . "]");
        $this->revEdges = FFIHelper::new("int32_t[{$edgeCount}]");

        // Write positions, seeded from the offsets we just computed.
        $treeP = FFIHelper::new("int32_t[{$nc}]");
        $streeP = FFIHelper::new("int32_t[{$nc}]");
        $allP = FFIHelper::new("int32_t[{$nc}]");
        $sallP = FFIHelper::new("int32_t[{$nc}]");
        $revP = FFIHelper::new("int32_t[{$nc}]");
        for ($k = 0; $k < $nc; $k++) {
            $treeP[$k] = $this->treeOffsets[$k];
            $streeP[$k] = $this->strongTreeOffsets[$k];
            $allP[$k] = $this->allOffsets[$k];
            $sallP[$k] = $this->strongAllOffsets[$k];
            $revP[$k] = $this->revOffsets[$k];
        }

        // Second pass: walk the staged edges and place them into the
        // CSR arrays. No SQL.
        for ($k = 0; $k < $edgeCount; $k++) {
            $pi = (int)$stageParentIdx[$k];
            $ci = (int)$stageChildIdx[$k];
            $flags = (int)$stageFlags[$k];
            $is_tree = ($flags & 1) !== 0;
            $is_strong = ($flags & 2) !== 0;

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
            if ($pi !== $rootParentIdx) {
                $p = (int)$allP[$pi];
                $this->allEdges[$p] = $ci;
                $allP[$pi] = $p + 1;
                if ($is_strong) {
                    $p = (int)$sallP[$pi];
                    $this->strongAllEdges[$p] = $ci;
                    $sallP[$pi] = $p + 1;
                }
            }
            // revEdges stores the parent's CSR index (matching the
            // contract csrSlice expects). The previous code stored
            // the raw parent value here, which corrupted getAllParents
            // for every non-root node and crashed on the actual root.
            $p = (int)$revP[$ci];
            $this->revEdges[$p] = $pi;
            $revP[$ci] = $p + 1;
        }
        unset($treeP, $streeP, $allP, $sallP, $revP);
        unset($stageParentIdx, $stageChildIdx, $stageFlags);
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
     * Tarjan's three per-node tables (index / lowlink / on_stack) live
     * in FFI int32 / int8 buffers indexed by csrIdx instead of in PHP
     * associative arrays. PHP hashmap access dominates the inner loop
     * on big graphs (5M+ nodes), and these tables are accessed
     * millions of times each — moving them to FFI removes per-visit
     * zval overhead and gives the inner loop O(1) direct memory
     * reads. Also localises the strongAll CSR property accesses so
     * the loop body never goes through `$this->` for the hot reads.
     *
     * @psalm-suppress UnsupportedReferenceUsage, MixedArgument
     */
    private function computeSccFfi(): void
    {
        $nc = $this->nodeCount;
        $has_canonical = $this->canonical !== [];

        // Localise hot FFI handles so the inner loop reads them as
        // local variables. PHP property access has measurable overhead
        // per call; the SCC pass touches these millions of times.
        $strongAllOffsets = $this->strongAllOffsets;
        $strongAllEdges = $this->strongAllEdges;
        $indexToNodeFfi = $this->indexToNodeFfi;

        // If canonical mapping exists, build index-level canonical map:
        // csrIdx → canonical csrIdx. This avoids repeated node_id lookups.
        /** @var array<int, int> $canonIdx  csrIdx → canonical csrIdx */
        $canonIdx = [];
        /** @var array<int, list<int>> $canonical_original_indices canonical csrIdx => original csrIdx list */
        $canonical_original_indices = [];
        if ($has_canonical) {
            for ($v = 0; $v < $nc; $v++) {
                $nid = (int)$indexToNodeFfi[$v];
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

        // Tarjan tables in FFI. -1 in tarjan_index means unvisited;
        // tarjan_on_stack uses 0/1 because int8 is plenty.
        $tarjan_index = FFIHelper::new("int32_t[{$nc}]");
        $tarjan_lowlink = FFIHelper::new("int32_t[{$nc}]");
        $tarjan_on_stack = FFIHelper::new("int8_t[{$nc}]");
        for ($i = 0; $i < $nc; $i++) {
            $tarjan_index[$i] = -1;
            $tarjan_lowlink[$i] = -1;
            $tarjan_on_stack[$i] = 0;
        }

        $index_counter = 0;
        $stack = [];
        $sccs = [];

        for ($v = 0; $v < $nc; $v++) {
            if ((int)$indexToNodeFfi[$v] === -1) {
                continue;
            }
            // Use canonical index if available
            $cv = $canonIdx[$v] ?? $v;
            if ((int)$tarjan_index[$cv] !== -1) {
                continue;
            }

            $call_stack = [[$cv, 0]];
            $tarjan_index[$cv] = $index_counter;
            $tarjan_lowlink[$cv] = $index_counter;
            $index_counter++;
            $stack[] = $cv;
            $tarjan_on_stack[$cv] = 1;

            while ($call_stack) {
                [$node, $ci] = array_pop($call_stack);

                if ($has_canonical) {
                    if (!isset($canonical_neighbors[$node])) {
                        $neighbors = [];
                        $seen = [];
                        foreach ($canonical_original_indices[$node] ?? [$node] as $oi) {
                            $start = (int)$strongAllOffsets[$oi];
                            $end = (int)$strongAllOffsets[$oi + 1];
                            for ($j = $start; $j < $end; $j++) {
                                $w = (int)$strongAllEdges[$j];
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
                    $start = (int)$strongAllOffsets[$node];
                    $end = (int)$strongAllOffsets[$node + 1];
                    for ($j = $start; $j < $end; $j++) {
                        $w = (int)$strongAllEdges[$j];
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
                    $w_index = (int)$tarjan_index[$w];
                    if ($w_index === -1) {
                        $call_stack[] = [$node, $i + 1];
                        $tarjan_index[$w] = $index_counter;
                        $tarjan_lowlink[$w] = $index_counter;
                        $index_counter++;
                        $stack[] = $w;
                        $tarjan_on_stack[$w] = 1;
                        $call_stack[] = [$w, 0];
                        $found_unvisited = true;
                        break;
                    } elseif ((int)$tarjan_on_stack[$w] !== 0) {
                        // Inlined min() — the builtin call adds
                        // measurable overhead in this hot loop.
                        $cur = (int)$tarjan_lowlink[$node];
                        if ($w_index < $cur) {
                            $tarjan_lowlink[$node] = $w_index;
                        }
                    }
                }

                if (!$found_unvisited) {
                    if ((int)$tarjan_lowlink[$node] === (int)$tarjan_index[$node]) {
                        /** @var list<int> $scc CSR indices (canonical) */
                        $scc = [];
                        do {
                            /** @var int $w */
                            $w = array_pop($stack);
                            $tarjan_on_stack[$w] = 0;
                            $scc[] = $w;
                        } while ($w !== $node);
                        if (count($scc) > 1) {
                            $sccs[] = $scc;
                        }
                    }
                    if ($call_stack) {
                        $parent_node = $call_stack[count($call_stack) - 1][0];
                        $cur = (int)$tarjan_lowlink[$parent_node];
                        $cand = (int)$tarjan_lowlink[$node];
                        if ($cand < $cur) {
                            $tarjan_lowlink[$parent_node] = $cand;
                        }
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
                $this->ffiNodeToScc[$idx] = $scc_id;
                $total_size += (int)$this->ffiNodeSizes[$idx];
                $classId = (int)$this->nodeClassIds[$idx];
                if ($classId >= 0) {
                    $cls = $this->classDict[$classId];
                    $class_counts[$cls] = ($class_counts[$cls] ?? 0) + 1;
                }
            }

            $rootParentIdx = $this->nodeIdToIndex(-1);
            $ext_in = 0;
            $ext_out = 0;
            foreach ($scc_indices as $idx) {
                // Reverse edges (parents) — revEdges holds CSR indices.
                $rStart = (int)$this->revOffsets[$idx];
                $rEnd = (int)$this->revOffsets[$idx + 1];
                for ($j = $rStart; $j < $rEnd; $j++) {
                    $parentIdx = (int)$this->revEdges[$j];
                    if ($parentIdx === $rootParentIdx) {
                        continue;
                    }
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
