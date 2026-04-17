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

use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Format;
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

    /** @var array<int, int> node_id => address */
    private array $nodeAddresses = [];

    /** @var array<int, string> node_id => string value (for ZendString nodes) */
    private array $nodeStringValues = [];

    /** @var array<int, int> node_id => refcount */
    private array $nodeRefcounts = [];

    /** @var array<int, string> node_id => class name (from locations, fallback for missing node_classes section) */
    private array $nodeClasses = [];

    /** @var array<int, array<string, string>> node_id => [key => value] from attributes */
    private array $nodeAttributes = [];

    private ?BinaryReader $reader;

    private function __construct(
        private GraphSubstrate $substrate,
        array $frameLabels,
        BinaryReader $reader,
    ) {
        $this->edgeCount = $substrate->getEdgeCount();
        $this->roots = $substrate->getRoots();
        $this->frameLabels = $frameLabels;
        $this->reader = $reader;
        // nodeCount: count roots + rough estimate from edges
        $this->nodeCount = $this->edgeCount > 0 ? $this->edgeCount : count($this->roots);
    }

    public static function fromSubstrate(
        GraphSubstrate $substrate,
        BinaryReader $reader,
    ): self {
        $frameLabels = BinaryReportDataProvider::loadFrameLabels($reader);
        return new self($substrate, $frameLabels, $reader);
    }

    /**
     * Lazily load location info (addresses + string values) on first access.
     */
    private function ensureLocationInfo(): void
    {
        if ($this->nodeAddresses !== []) {
            return;
        }
        if ($this->reader === null) {
            return;
        }
        [$this->nodeAddresses, $this->nodeStringValues, $this->nodeRefcounts, $this->nodeClasses] = self::loadLocationInfo($this->reader);
        $this->nodeAttributes = self::loadAttributes($this->reader);
    }

    /**
     * Load address, string_value, refcount, and class per node from locations section.
     * @return array{array<int, int>, array<int, string>, array<int, int>, array<int, string>}
     */
    private static function loadLocationInfo(BinaryReader $reader): array
    {
        $addresses = [];
        $stringValues = [];
        $refcounts = [];
        $classes = [];

        if (!$reader->hasSection(Format::SECTION_LOCATIONS)) {
            return [$addresses, $stringValues, $refcounts, $classes];
        }

        $dict = $reader->getStringDict();
        $count = $reader->getSectionElementCount(Format::SECTION_LOCATIONS);
        $locRows = $reader->castSection(Format::SECTION_LOCATIONS, 'LocationRow');

        if ($locRows !== null) {
            for ($i = 0; $i < $count; $i++) {
                $nid = (int)$locRows[$i]->node_id;
                if (!isset($addresses[$nid])) {
                    $addr = (int)$locRows[$i]->address;
                    if ($addr !== 0) {
                        $addresses[$nid] = $addr;
                    }
                }
                if (!isset($stringValues[$nid])) {
                    $svId = (int)$locRows[$i]->string_value_id;
                    if ($svId !== Format::NULL_STRING_ID) {
                        $sv = $dict->lookup($svId);
                        if ($sv !== null) {
                            $stringValues[$nid] = $sv;
                        }
                    }
                }
                if (!isset($refcounts[$nid])) {
                    $rc = (int)$locRows[$i]->refcount;
                    if ($rc > 0) {
                        $refcounts[$nid] = $rc;
                    }
                }
                if (!isset($classes[$nid])) {
                    $cid = (int)$locRows[$i]->class_id;
                    if ($cid !== Format::NULL_STRING_ID) {
                        $cn = $dict->lookup($cid);
                        if ($cn !== null) {
                            $classes[$nid] = $cn;
                        }
                    }
                }
            }
        }

        return [$addresses, $stringValues, $refcounts, $classes];
    }

    /**
     * Load all attributes (key=value pairs) per node.
     * @return array<int, array<string, string>>
     */
    private static function loadAttributes(BinaryReader $reader): array
    {
        $attrs = [];
        if (!$reader->hasSection(Format::SECTION_ATTRIBUTES)) {
            return $attrs;
        }
        $dict = $reader->getStringDict();
        $data = $reader->getSectionData(Format::SECTION_ATTRIBUTES);
        $count = $reader->getSectionElementCount(Format::SECTION_ATTRIBUTES);
        $offset = 0;
        for ($i = 0; $i < $count; $i++) {
            if ($offset + 12 > strlen($data)) {
                break;
            }
            $nid = unpack('V', $data, $offset)[1];
            $keyId = unpack('V', $data, $offset + 4)[1];
            $valId = unpack('V', $data, $offset + 8)[1];
            $offset += 12;

            $key = $dict->lookup((int)$keyId);
            $val = $dict->lookup((int)$valId);
            if ($key !== null && $val !== null && $key !== 'function_name' && $key !== 'lineno') {
                $attrs[(int)$nid][$key] = $val;
            }
        }
        return $attrs;
    }

    /**
     * Get root-level branches (call_frames, class_table, objects_store, etc.)
     * sorted by retained size descending.
     *
     * @return list<array{node_id: int, retained: int, shallow: int, label: string, link_name?: string}>
     */
    public function getRootChildren(): array
    {
        // Collect root-level nodes (children of -1 sentinel)
        $rootEntries = [];
        foreach ($this->roots as $rootId) {
            $rootEntries[] = [
                'node_id' => $rootId,
                'retained' => $this->substrate->getSubtreeSize($rootId),
                'shallow' => $this->substrate->getNodeSize($rootId),
                'link_name' => $this->substrate->getTreeLinkName($rootId) ?? '?',
                'label' => $this->nodeLabel($rootId),
            ];
        }

        // Filter out empty roots (e.g. interned_strings whose array was
        // already emitted by call_frames via pool cache hit → reference
        // edge only, no children).
        $rootEntries = array_values(array_filter(
            $rootEntries,
            fn (array $e) => $e['retained'] > 0 || $this->substrate->getChildren($e['node_id']) !== [],
        ));
        usort($rootEntries, fn (array $a, array $b) => $b['retained'] <=> $a['retained']);
        return $rootEntries;
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
     * @param bool $allEdges If true, include non-tree edges (reference edges).
     * @return list<array{node_id: int, retained: int, shallow: int, link_name: string, label: string}>
     */
    public function getChildren(int $nodeId, bool $allEdges = false): array
    {
        $children = $allEdges
            ? $this->substrate->getAllChildren($nodeId)
            : $this->substrate->getChildren($nodeId);
        $entries = [];
        $seen = [];
        foreach ($children as $childId) {
            if (isset($seen[$childId])) {
                continue;
            }
            $seen[$childId] = true;
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

    private function resolveClass(int $nodeId): ?string
    {
        return $this->substrate->getNodeClass($nodeId)
            ?? $this->nodeClasses[$nodeId]
            ?? null;
    }

    public function nodeLabel(int $nodeId): string
    {
        // Frame label (function:line) if available
        if (isset($this->frameLabels[$nodeId])) {
            $label = $this->frameLabels[$nodeId];
            $class = $this->resolveClass($nodeId);
            if ($class !== null) {
                $label .= " ({$class})";
            }
            return $label;
        }
        // Class name + type
        $class = $this->resolveClass($nodeId);
        $type = $this->substrate->getNodeType($nodeId);
        if ($class !== null && $type !== null) {
            return "{$type}: {$class}";
        }
        if ($class !== null) {
            return $class;
        }
        if ($type !== null) {
            return $type;
        }
        return "node#{$nodeId}";
    }

    /**
     * Get detailed info for a node (for focus bar / detail view).
     * @return array{type: string, class: ?string, shallow: int, retained: int, address: ?int, string_value: ?string, attributes: array<string, string>}
     */
    public function nodeDetail(int $nodeId): array
    {
        $this->ensureLocationInfo();
        return [
            'type' => $this->substrate->getNodeType($nodeId) ?? '?',
            'class' => $this->resolveClass($nodeId),
            'shallow' => $this->substrate->getNodeSize($nodeId),
            'retained' => $this->substrate->getSubtreeSize($nodeId),
            'address' => $this->nodeAddresses[$nodeId] ?? null,
            'string_value' => $this->nodeStringValues[$nodeId] ?? null,
            'refcount' => $this->nodeRefcounts[$nodeId] ?? null,
            'attributes' => $this->nodeAttributes[$nodeId] ?? [],
        ];
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
