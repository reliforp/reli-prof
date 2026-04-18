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
        $this->nodeCount = $substrate->getNodeCount();
        $this->edgeCount = $substrate->getEdgeCount();
        $this->roots = $substrate->getRoots();
        $this->frameLabels = $frameLabels;
        $this->reader = $reader;
    }

    public static function fromSubstrate(
        GraphSubstrate $substrate,
        BinaryReader $reader,
    ): self {
        $frameLabels = BinaryReportDataProvider::loadFrameLabels($reader);
        return new self($substrate, $frameLabels, $reader);
    }

    /**
     * Lazily load location info (addresses, string values, refcounts, classes).
     * Called automatically on first nodeDetail()/resolveClass(), or explicitly
     * from the TUI render loop.
     */
    public function ensureLocationInfoLoaded(): void
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
        // Min-heap of size $limit: keep the top-k largest retained.
        // O(N log k) vs O(N log N) for full sort. Memory: O(k) not O(N).
        $heap = new \SplMinHeap();
        foreach ($this->substrate->iterateSubtreeSizes() as $nodeId => $retained) {
            if ($retained <= 0) {
                continue;
            }
            if ($heap->count() < $limit) {
                $heap->insert([$retained, $nodeId]);
            } elseif ($retained > $heap->top()[0]) {
                $heap->extract();
                $heap->insert([$retained, $nodeId]);
            }
        }

        // Extract in descending order
        $entries = [];
        while (!$heap->isEmpty()) {
            [$retained, $nodeId] = $heap->extract();
            $entries[] = [
                'node_id' => $nodeId,
                'retained' => $retained,
                'shallow' => $this->substrate->getNodeSize($nodeId),
                'label' => $this->nodeLabel($nodeId),
            ];
        }
        return array_reverse($entries);
    }

    /**
     * Get children of a node sorted by retained size descending.
     *
     * @param bool $allEdges If true, include non-tree edges (reference edges).
     * @return list<array{node_id: int, retained: int, shallow: int, link_name: string, label: string}>
     */
    public function getChildren(int $nodeId, bool $allEdges = false, string $sort = 'retained'): array
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
        // Add pseudo-edges to definitions
        $this->addDefinitionLinks($nodeId, $entries);

        if ($sort === 'link') {
            usort($entries, function (array $a, array $b): int {
                // Numeric link names (frame numbers, array keys) sort numerically
                $aNum = is_numeric($a['link_name']);
                $bNum = is_numeric($b['link_name']);
                if ($aNum && $bNum) {
                    return (int)$a['link_name'] <=> (int)$b['link_name'];
                }
                return strnatcasecmp($a['link_name'], $b['link_name']);
            });
        } else {
            usort($entries, fn (array $a, array $b) => $b['retained'] <=> $a['retained']);
        }
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

    // ---- Rankings (cached) ----

    /** @var list<array{class: string, count: int, total_shallow: int, avg_shallow: int}>|null */
    private ?array $classRanking = null;

    /** @var list<array{type: string, count: int, total_shallow: int}>|null */
    private ?array $typeRanking = null;

    /**
     * @return list<array{class: string, count: int, total_shallow: int, avg_shallow: int}>
     */
    public function getClassRanking(): array
    {
        if ($this->classRanking !== null) {
            return $this->classRanking;
        }
        $this->ensureLocationInfoLoaded();

        // Use substrate's iterateNodeClasses first, fall back to
        // location-derived nodeClasses for broader coverage.
        $groups = [];
        foreach ($this->substrate->iterateNodeClasses() as $nodeId => $className) {
            if (!isset($groups[$className])) {
                $groups[$className] = ['count' => 0, 'total' => 0];
            }
            $groups[$className]['count']++;
            $groups[$className]['total'] += $this->substrate->getNodeSize($nodeId);
        }
        // Supplement from locations (catches nodes missed by substrate)
        foreach ($this->nodeClasses as $nodeId => $className) {
            if (!isset($groups[$className])) {
                $groups[$className] = ['count' => 0, 'total' => 0];
            }
            // Avoid double-counting: only add if substrate didn't have it
            if ($this->substrate->getNodeClass($nodeId) === null) {
                $groups[$className]['count']++;
                $groups[$className]['total'] += $this->substrate->getNodeSize($nodeId);
            }
        }
        $result = [];
        foreach ($groups as $class => $g) {
            $result[] = [
                'class' => $class,
                'count' => $g['count'],
                'total_shallow' => $g['total'],
                'avg_shallow' => $g['count'] > 0 ? (int)($g['total'] / $g['count']) : 0,
            ];
        }
        usort($result, fn (array $a, array $b) => $b['total_shallow'] <=> $a['total_shallow']);
        $this->classRanking = $result;
        return $result;
    }

    /**
     * @return list<array{type: string, count: int, total_shallow: int}>
     */
    public function getTypeRanking(): array
    {
        if ($this->typeRanking !== null) {
            return $this->typeRanking;
        }
        $groups = [];
        foreach ($this->substrate->iterateNodeSizes() as $nodeId => $size) {
            $type = $this->substrate->getNodeType($nodeId) ?? '?';
            if (!isset($groups[$type])) {
                $groups[$type] = ['count' => 0, 'total' => 0];
            }
            $groups[$type]['count']++;
            $groups[$type]['total'] += $size;
        }
        $result = [];
        foreach ($groups as $type => $g) {
            $result[] = [
                'type' => $type,
                'count' => $g['count'],
                'total_shallow' => $g['total'],
            ];
        }
        usort($result, fn (array $a, array $b) => $b['total_shallow'] <=> $a['total_shallow']);
        $this->typeRanking = $result;
        return $result;
    }

    /**
     * Get all nodes of a given class, sorted by retained size.
     * @return list<array{node_id: int, retained: int, shallow: int, label: string}>
     */
    public function getNodesByClass(string $className): array
    {
        $this->ensureLocationInfoLoaded();
        $seen = [];
        $entries = [];
        foreach ($this->substrate->iterateNodeClasses() as $nodeId => $cls) {
            if ($cls === $className) {
                $seen[$nodeId] = true;
                $entries[] = [
                    'node_id' => $nodeId,
                    'retained' => $this->substrate->getSubtreeSize($nodeId),
                    'shallow' => $this->substrate->getNodeSize($nodeId),
                    'label' => $this->nodeLabel($nodeId),
                ];
            }
        }
        foreach ($this->nodeClasses as $nodeId => $cls) {
            if ($cls === $className && !isset($seen[$nodeId])) {
                $entries[] = [
                    'node_id' => $nodeId,
                    'retained' => $this->substrate->getSubtreeSize($nodeId),
                    'shallow' => $this->substrate->getNodeSize($nodeId),
                    'label' => $this->nodeLabel($nodeId),
                ];
            }
        }
        usort($entries, fn (array $a, array $b) => $b['retained'] <=> $a['retained']);
        return $entries;
    }

    /**
     * Get all nodes of a given type, sorted by retained size.
     * @return list<array{node_id: int, retained: int, shallow: int, label: string}>
     */
    public function getNodesByType(string $typeName): array
    {
        $entries = [];
        foreach ($this->substrate->iterateNodeSizes() as $nodeId => $size) {
            if (($this->substrate->getNodeType($nodeId) ?? '?') === $typeName) {
                $entries[] = [
                    'node_id' => $nodeId,
                    'retained' => $this->substrate->getSubtreeSize($nodeId),
                    'shallow' => $size,
                    'label' => $this->nodeLabel($nodeId),
                ];
            }
        }
        usort($entries, fn (array $a, array $b) => $b['retained'] <=> $a['retained']);
        return $entries;
    }

    // ---- Function/class definition lookup ----

    /** @var array<string, int>|null function_name → node_id in function_table */
    private ?array $functionIndex = null;
    /** @var array<string, int>|null class_name → node_id in class_table */
    private ?array $classDefIndex = null;

    /**
     * Find the function_table definition node for a given function name.
     */
    public function findFunctionDef(string $funcName): ?int
    {
        $this->buildDefinitionIndexes();
        return $this->functionIndex[strtolower($funcName)] ?? null;
    }

    /**
     * Find the class_table definition node for a given class name.
     */
    public function findClassDef(string $className): ?int
    {
        $this->buildDefinitionIndexes();
        // class_table uses lowercase keys
        return $this->classDefIndex[strtolower($className)] ?? null;
    }

    public function buildDefinitionIndexes(): void
    {
        if ($this->functionIndex !== null) {
            return;
        }
        $this->functionIndex = [];
        $this->classDefIndex = [];

        foreach ($this->roots as $rootId) {
            $linkName = $this->substrate->getTreeLinkName($rootId);
            if ($linkName === 'function_table' || $linkName === 'class_table') {
                $children = $this->substrate->getChildren($rootId);
                foreach ($children as $childId) {
                    $childLink = $this->substrate->getTreeLinkName($childId);
                    if ($childLink === null) {
                        continue;
                    }
                    if ($linkName === 'function_table') {
                        $this->functionIndex[$childLink] = $childId;
                    } else {
                        $this->classDefIndex[$childLink] = $childId;
                    }
                }
            }
        }
    }

    /**
     * Add pseudo-edges to function/class definitions for explore navigation.
     * These appear as virtual children with link_name "[def]".
     * @param list<array{node_id: int, retained: int, shallow: int, link_name: string, label: string}> &$entries
     */
    private function addDefinitionLinks(int $nodeId, array &$entries): void
    {
        $seen = [];
        foreach ($entries as $e) {
            $seen[$e['node_id']] = true;
        }

        // Frame label → function definition
        $frameLabel = $this->frameLabels[$nodeId] ?? null;
        if ($frameLabel !== null) {
            $funcName = preg_replace('/:\d+$/', '', $frameLabel);
            if ($funcName !== null && $funcName !== '') {
                $defId = $this->findFunctionDef($funcName);
                if ($defId !== null && !isset($seen[$defId])) {
                    $entries[] = [
                        'node_id' => $defId,
                        'retained' => $this->substrate->getSubtreeSize($defId),
                        'shallow' => $this->substrate->getNodeSize($defId),
                        'link_name' => '⇒ def',
                        'label' => $this->nodeLabel($defId),
                    ];
                    $seen[$defId] = true;
                }
                // Class definition
                $classEnd = strrpos($funcName, '::');
                if ($classEnd !== false) {
                    $className = substr($funcName, 0, $classEnd);
                    $classDefId = $this->findClassDef($className);
                    if ($classDefId !== null && !isset($seen[$classDefId])) {
                        $entries[] = [
                            'node_id' => $classDefId,
                            'retained' => $this->substrate->getSubtreeSize($classDefId),
                            'shallow' => $this->substrate->getNodeSize($classDefId),
                            'link_name' => '⇒ class',
                            'label' => $this->nodeLabel($classDefId),
                        ];
                        $seen[$classDefId] = true;
                    }
                }
            }
        }

        // Object class → class definition
        $class = $this->resolveClass($nodeId);
        if ($class !== null) {
            $classDefId = $this->findClassDef($class);
            if ($classDefId !== null && !isset($seen[$classDefId])) {
                $entries[] = [
                    'node_id' => $classDefId,
                    'retained' => $this->substrate->getSubtreeSize($classDefId),
                    'shallow' => $this->substrate->getNodeSize($classDefId),
                    'link_name' => '⇒ class',
                    'label' => $this->nodeLabel($classDefId),
                ];
            }
        }
    }

    public function findNodeByAddress(int $address): ?int
    {
        $this->ensureLocationInfoLoaded();
        $nodeId = array_search($address, $this->nodeAddresses, true);
        return $nodeId !== false ? $nodeId : null;
    }

    /** @return list<int> raw child node IDs (tree edges) */
    public function getChildrenRaw(int $nodeId): array
    {
        return $this->substrate->getChildren($nodeId);
    }

    /** @var array<int, int>|null node_id => scc_id */
    private ?array $nodeSccMap = null;

    /**
     * Get SCC ID for a node, or null if not in any SCC.
     */
    public function getNodeSccId(int $nodeId): ?int
    {
        $this->ensureSccMap();
        return $this->nodeSccMap[$nodeId] ?? null;
    }

    /**
     * Get SCC profile by ID.
     * @return array{id: int, nodes: list<int>, node_count: int, total_size: int, signature: string}|null
     */
    public function getSccProfile(int $sccId): ?array
    {
        $profiles = $this->getSccProfiles();
        if ($profiles === null) {
            return null;
        }
        foreach ($profiles as $p) {
            if ($p['id'] === $sccId) {
                return $p;
            }
        }
        return null;
    }

    private function ensureSccMap(): void
    {
        if ($this->nodeSccMap !== null) {
            return;
        }
        $this->nodeSccMap = [];
        $profiles = $this->getSccProfiles();
        if ($profiles === null) {
            return;
        }
        foreach ($profiles as $profile) {
            foreach ($profile['nodes'] as $nid) {
                $this->nodeSccMap[$nid] = $profile['id'];
            }
        }
    }

    /**
     * Load SCC profiles from sidecar cache if available.
     * @return list<array{id: int, nodes: list<int>, node_count: int, total_size: int, signature: string}>|null
     */
    public function getSccProfiles(): ?array
    {
        if ($this->reader === null) {
            return null;
        }
        $rmemPath = $this->reader->getFilePath();
        $cache = \Reli\Inspector\Output\MemoryOutput\BinaryFormat\DerivedCacheReader::open($rmemPath);
        if ($cache === null) {
            return null;
        }
        if (!$cache->hasSection(\Reli\Inspector\Output\MemoryOutput\BinaryFormat\DerivedCacheFormat::SECTION_SCC_PROFILES)) {
            return null;
        }
        $json = $cache->getSectionData(\Reli\Inspector\Output\MemoryOutput\BinaryFormat\DerivedCacheFormat::SECTION_SCC_PROFILES);
        if ($json === null) {
            return null;
        }
        $profiles = json_decode($json, true);
        return is_array($profiles) ? $profiles : null;
    }

    public function getFrameLabel(int $nodeId): ?string
    {
        return $this->frameLabels[$nodeId] ?? null;
    }

    public function resolveClassPublic(int $nodeId): ?string
    {
        return $this->resolveClass($nodeId);
    }

    private function resolveClass(int $nodeId): ?string
    {
        return $this->substrate->getNodeClass($nodeId)
            ?? $this->nodeClasses[$nodeId]
            ?? null;
    }

    /**
     * Get a short inline preview for string/scalar nodes.
     * Returns null for non-value nodes.
     */
    public static function sanitizeForTerminal(string $s): string
    {
        // Replace common whitespace escapes with visible representations
        $s = str_replace(["\n", "\r", "\t", "\0"], ['\n', '\r', '\t', '\0'], $s);
        // Strip ANSI escape sequences
        $s = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $s) ?? $s;
        // Replace remaining control chars (0x00-0x1f, 0x7f) with ·
        $s = preg_replace('/[\x00-\x1f\x7f]/', "\xC2\xB7", $s) ?? $s;
        return $s;
    }

    private function valuePreview(int $nodeId): ?string
    {
        $sv = $this->nodeStringValues[$nodeId] ?? null;
        if ($sv !== null) {
            $preview = self::sanitizeForTerminal($sv);
            if (strlen($preview) > 40) {
                $preview = substr($preview, 0, 37) . '...';
            }
            return "\"{$preview}\"";
        }
        $attrs = $this->nodeAttributes[$nodeId] ?? [];
        if (isset($attrs['value'])) {
            $val = $attrs['value'];
            if (strlen($val) > 40) {
                $val = substr($val, 0, 37) . '...';
            }
            return $val;
        }
        return null;
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

        // Value preview for string/scalar
        $preview = $this->valuePreview($nodeId);
        if ($preview !== null) {
            $base = $type ?? 'value';
            return "{$base} = {$preview}";
        }

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
        $this->ensureLocationInfoLoaded();
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
