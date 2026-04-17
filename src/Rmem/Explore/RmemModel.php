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

namespace Reli\Rmem\Explore;

use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Inspector\Output\MemoryOutput\Report\BinaryReportDataProvider;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate;

/**
 * In-memory model for rmem:explore TUI.
 *
 * Wraps GraphSubstrate and provides sorted node lists, children
 * enumeration, and label lookups needed by the TUI.
 */
final class RmemModel
{
    public readonly int $nodeCount;
    public readonly int $edgeCount;

    /** @var list<int> root node IDs */
    public readonly array $roots;

    /** @var array<int, string> node_id => "function:line" label */
    private array $frameLabels;

    private function __construct(
        private GraphSubstrate $substrate,
        array $frameLabels,
    ) {
        $this->nodeCount = count($this->getTopRetained(PHP_INT_MAX));
        $this->edgeCount = $substrate->getEdgeCount();
        $this->roots = $substrate->getRoots();
        $this->frameLabels = $frameLabels;
    }

    public static function fromSubstrate(
        GraphSubstrate $substrate,
        BinaryReader $reader,
    ): self {
        $frameLabels = BinaryReportDataProvider::loadFrameLabels($reader);
        return new self($substrate, $frameLabels);
    }

    /**
     * Get root-level children sorted by retained size descending.
     * These correspond to report's Root Blame Allocation branches
     * (call_frames, class_table, objects_store, etc.).
     *
     * @return list<array{node_id: int, retained: int, shallow: int, label: string, link_name?: string}>
     */
    public function getRootChildren(): array
    {
        $entries = [];
        foreach ($this->roots as $rootId) {
            $children = $this->substrate->getChildren($rootId);
            foreach ($children as $childId) {
                $linkName = $this->substrate->getTreeLinkName($childId) ?? '?';
                $entries[] = [
                    'node_id' => $childId,
                    'retained' => $this->substrate->getSubtreeSize($childId),
                    'shallow' => $this->substrate->getNodeSize($childId),
                    'link_name' => $linkName,
                    'label' => $this->nodeLabel($childId),
                ];
            }
        }
        usort($entries, fn (array $a, array $b) => $b['retained'] <=> $a['retained']);
        return $entries;
    }

    /**
     * Get nodes sorted by retained (subtree) size descending.
     *
     * @return list<array{node_id: int, retained: int, shallow: int, label: string}>
     */
    public function getTopRetained(int $limit): array
    {
        $entries = [];
        foreach ($this->substrate->iterateSubtreeSizes() as $nodeId => $retained) {
            if ($retained <= 0) {
                continue;
            }
            $entries[] = [
                'node_id' => $nodeId,
                'retained' => $retained,
                'shallow' => $this->substrate->getNodeSize($nodeId),
                'label' => $this->nodeLabel($nodeId),
            ];
        }
        usort($entries, fn (array $a, array $b) => $b['retained'] <=> $a['retained']);
        return array_slice($entries, 0, $limit);
    }

    /**
     * Get children of a node sorted by retained size descending.
     *
     * @return list<array{node_id: int, retained: int, shallow: int, link_name: string, label: string}>
     */
    public function getChildren(int $nodeId): array
    {
        $children = $this->substrate->getChildren($nodeId);
        $entries = [];
        foreach ($children as $childId) {
            $linkName = $this->substrate->getTreeLinkName($childId) ?? '?';
            $entries[] = [
                'node_id' => $childId,
                'retained' => $this->substrate->getSubtreeSize($childId),
                'shallow' => $this->substrate->getNodeSize($childId),
                'link_name' => $linkName,
                'label' => $this->nodeLabel($childId),
            ];
        }
        usort($entries, fn (array $a, array $b) => $b['retained'] <=> $a['retained']);
        return $entries;
    }

    /**
     * Get all parents (reverse edges) of a node, sorted by retained size.
     *
     * @return list<array{node_id: int, retained: int, shallow: int, link_name: string, label: string}>
     */
    public function getParents(int $nodeId): array
    {
        $parents = $this->substrate->getAllParents($nodeId);
        $entries = [];
        $seen = [];
        foreach ($parents as $parentId) {
            if (isset($seen[$parentId])) {
                continue;
            }
            $seen[$parentId] = true;
            // Find the link name: what edge label does the parent use to reference this child?
            $linkName = $this->substrate->getTreeLinkName($nodeId) ?? '?';
            $entries[] = [
                'node_id' => $parentId,
                'retained' => $this->substrate->getSubtreeSize($parentId),
                'shallow' => $this->substrate->getNodeSize($parentId),
                'link_name' => $linkName,
                'label' => $this->nodeLabel($parentId),
            ];
        }
        usort($entries, fn (array $a, array $b) => $b['retained'] <=> $a['retained']);
        return $entries;
    }

    /**
     * Get the path from a node to root.
     *
     * @return list<array{node_id: int, link_name: string, label: string}>
     */
    public function pathToRoot(int $nodeId): array
    {
        $path = [];
        $current = $nodeId;
        $visited = [];
        while (true) {
            if (isset($visited[$current])) {
                break;
            }
            $visited[$current] = true;
            $link = $this->substrate->getTreeLinkName($current) ?? '<root>';
            $path[] = [
                'node_id' => $current,
                'link_name' => $link,
                'label' => $this->nodeLabel($current),
            ];
            $parent = $this->substrate->getTreeParentNodeId($current);
            if ($parent === null) {
                break;
            }
            $current = $parent;
        }
        return $path;
    }

    public function nodeLabel(int $nodeId): string
    {
        // Frame label (function:line) if available
        if (isset($this->frameLabels[$nodeId])) {
            return $this->frameLabels[$nodeId];
        }
        // Class name
        $class = $this->substrate->getNodeClass($nodeId);
        if ($class !== null) {
            return $class;
        }
        // Type
        $type = $this->substrate->getNodeType($nodeId);
        if ($type !== null) {
            return $type;
        }
        return "node#{$nodeId}";
    }

    public function nodeType(int $nodeId): string
    {
        return $this->substrate->getNodeType($nodeId) ?? '?';
    }

    public function nodeSize(int $nodeId): int
    {
        return $this->substrate->getNodeSize($nodeId);
    }

    public function subtreeSize(int $nodeId): int
    {
        return $this->substrate->getSubtreeSize($nodeId);
    }

    public function nodeSizesSum(): int
    {
        return $this->substrate->getNodeSizesSum();
    }
}
