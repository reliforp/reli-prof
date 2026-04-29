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
use Reli\Lib\String\PathMap;

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

    /**
     * class_name => class_entry node_id. Built lazily from nodeAttributes
     * after attribute load so defined_at resolution for object zvals can
     * hop from the object's class string to its ClassEntryContext node.
     *
     * @var array<string, int>
     */
    private ?array $classEntryIndex = null;

    /**
     * Memo for resolveSourceLocations() to avoid re-walking ancestors on
     * every TUI render. Negative sentinel used for "known absent".
     *
     * @var array<int, list<array{kind: string, filename: string, line: ?int, line_start: ?int, line_end: ?int}>>
     */
    private array $sourceLocationCache = [];

    /**
     * When populated from the derived cache, maps node_id to the
     * resolved indirection targets. held_by_nid / defined_at_nid are
     * the node_ids whose own filename should be surfaced for this
     * node. -1 means "no resolution known". When this is non-null,
     * resolveSourceLocations() uses it to skip the live ancestor walk.
     *
     * @var array<int, array{defined_at: int, held_by: int}>|null
     */
    private ?array $sourceLocationRefs = null;

    private ?BinaryReader $reader;

    private PathMap $pathMap;

    /**
     * @param array<int, string> $frameLabels
     */
    private function __construct(
        private GraphSubstrate $substrate,
        array $frameLabels,
        BinaryReader $reader,
        PathMap $pathMap,
    ) {
        $this->nodeCount = $substrate->getNodeCount();
        $this->edgeCount = $substrate->getEdgeCount();
        $this->roots = $substrate->getRoots();
        $this->frameLabels = $frameLabels;
        $this->reader = $reader;
        $this->pathMap = $pathMap;
    }

    public static function fromSubstrate(
        GraphSubstrate $substrate,
        BinaryReader $reader,
        ?PathMap $pathMap = null,
    ): self {
        $frameLabels = BinaryReportDataProvider::loadFrameLabels($reader);
        return new self($substrate, $frameLabels, $reader, $pathMap ?? PathMap::empty());
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
     * @psalm-suppress InaccessibleMethod, PossiblyNullPropertyFetch, UndefinedPropertyFetch
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
                $nid = $locRows[$i]->node_id;
                if (!isset($addresses[$nid])) {
                    $addr = $locRows[$i]->address;
                    if ($addr !== 0) {
                        $addresses[$nid] = $addr;
                    }
                }
                if (!isset($stringValues[$nid])) {
                    $svId = $locRows[$i]->string_value_id;
                    if ($svId !== Format::NULL_STRING_ID) {
                        $sv = $dict->lookup($svId);
                        if ($sv !== null) {
                            $stringValues[$nid] = $sv;
                        }
                    }
                }
                if (!isset($refcounts[$nid])) {
                    $rc = $locRows[$i]->refcount;
                    if ($rc > 0) {
                        $refcounts[$nid] = $rc;
                    }
                }
                if (!isset($classes[$nid])) {
                    $cid = $locRows[$i]->class_id;
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
            /** @var array{1: int} $nidArr */
            $nidArr = unpack('V', $data, $offset);
            /** @var array{1: int} $keyIdArr */
            $keyIdArr = unpack('V', $data, $offset + 4);
            /** @var array{1: int} $valIdArr */
            $valIdArr = unpack('V', $data, $offset + 8);
            $nid = $nidArr[1];
            $keyId = $keyIdArr[1];
            $valId = $valIdArr[1];
            $offset += 12;

            $key = $dict->lookup($keyId);
            $val = $dict->lookup($valId);
            // function_name is already surfaced via frameLabels; skip to
            // avoid duplicating it in the detail pane. lineno is kept so
            // resolveSourceLocation() can pick it up for navigation.
            if ($key !== null && $val !== null && $key !== 'function_name') {
                $attrs[$nid][$key] = $val;
            }
        }
        return $attrs;
    }

    /**
     * Get root-level branches (call_frames, class_table, objects_store, etc.)
     * sorted by retained size descending.
     *
     * @return list<array{node_id: int, retained: int, shallow: int, label: string, link_name: string}>
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
        /** @var \SplMinHeap<array{int, int}> $heap */
        $heap = new \SplMinHeap();
        foreach ($this->substrate->iterateSubtreeSizes() as $nodeId => $retained) {
            if ($retained <= 0) {
                continue;
            }
            if ($heap->count() < $limit) {
                $heap->insert([$retained, $nodeId]);
            } else {
                /** @var array{int, int} $top */
                $top = $heap->top();
                if ($retained > $top[0]) {
                    $heap->extract();
                    $heap->insert([$retained, $nodeId]);
                }
            }
        }

        // Extract in descending order
        $entries = [];
        while (!$heap->isEmpty()) {
            /** @var array{int, int} $item */
            $item = $heap->extract();
            [$retained, $nodeId] = $item;
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
        // Sentinel: children are the forest roots. Tree edges are
        // stored with the roots pointing BACK to the sentinel via
        // tree_parents[root] = -1, not as children[-1] entries, so the
        // generic substrate lookup would return nothing. Serve the
        // root list directly so Enter on the synthetic "(roots)"
        // parent surfaces every top-level branch.
        if ($nodeId < 0) {
            return $this->getRootChildren();
        }
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
                $aLink = (string)($a['link_name'] ?? '');
                $bLink = (string)($b['link_name'] ?? '');
                $aNum = is_numeric($aLink);
                $bNum = is_numeric($bLink);
                if ($aNum && $bNum) {
                    return (int)$aLink <=> (int)$bLink;
                }
                return strnatcasecmp($aLink, $bLink);
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

    public function getSubstrate(): GraphSubstrate
    {
        return $this->substrate;
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
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }
        /** @var list<array{id: int, nodes: list<int>, node_count: int, total_size: int, signature: string}> */
        return $decoded;
    }

    /**
     * Global search across frame labels, class names, and string values.
     * Returns matching node_ids with context about what matched.
     *
     * @return list<array{node_id: int, retained: int, shallow: int, label: string, match_field: string}>
     */
    public function globalSearch(string $pattern, int $limit = 100): array
    {
        $this->ensureLocationInfoLoaded();
        $lower = strtolower($pattern);
        $results = [];
        $seen = [];

        // Search frame labels (function_name:lineno)
        foreach ($this->frameLabels as $nodeId => $label) {
            if (str_contains(strtolower($label), $lower)) {
                $seen[$nodeId] = true;
                $results[] = [
                    'node_id' => $nodeId,
                    'retained' => $this->substrate->getSubtreeSize($nodeId),
                    'shallow' => $this->substrate->getNodeSize($nodeId),
                    'label' => $this->nodeLabel($nodeId),
                    'match_field' => 'frame',
                ];
                if (count($results) >= $limit) {
                    return $results;
                }
            }
        }

        // Search class names
        foreach ($this->nodeClasses as $nodeId => $className) {
            if (isset($seen[$nodeId])) {
                continue;
            }
            if (str_contains(strtolower($className), $lower)) {
                $seen[$nodeId] = true;
                $results[] = [
                    'node_id' => $nodeId,
                    'retained' => $this->substrate->getSubtreeSize($nodeId),
                    'shallow' => $this->substrate->getNodeSize($nodeId),
                    'label' => $this->nodeLabel($nodeId),
                    'match_field' => 'class',
                ];
                if (count($results) >= $limit) {
                    return $results;
                }
            }
        }

        // Search string values
        foreach ($this->nodeStringValues as $nodeId => $strVal) {
            if (isset($seen[$nodeId])) {
                continue;
            }
            if (str_contains(strtolower($strVal), $lower)) {
                $seen[$nodeId] = true;
                $results[] = [
                    'node_id' => $nodeId,
                    'retained' => $this->substrate->getSubtreeSize($nodeId),
                    'shallow' => $this->substrate->getNodeSize($nodeId),
                    'label' => $this->nodeLabel($nodeId),
                    'match_field' => 'string_value',
                ];
                if (count($results) >= $limit) {
                    return $results;
                }
            }
        }

        usort($results, fn (array $a, array $b) => $b['retained'] <=> $a['retained']);
        return $results;
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
     * Replace escape sequences / control chars with visible stand-ins so
     * the result is safe to splat onto a raw TTY.
     *
     * `$maxLen`, when provided, caps the input *before* the pattern
     * operations run. That cap matters: the TUI calls this from hot
     * paths like `valuePreview()` (one call per rendered row) and from
     * the detail pane, and the raw string_value can be multi-MB for
     * pathological nodes. Running two `preg_replace()` scans over
     * megabytes of text on every repaint turns keypress response into
     * a visible freeze. Callers that know how much they will actually
     * render should pass a generous upper bound (final display length
     * + some margin for ANSI-strip expansion). A null `$maxLen` keeps
     * the historical unbounded behavior for places that really do want
     * the full string.
     */
    public static function sanitizeForTerminal(string $s, ?int $maxLen = null): string
    {
        if ($maxLen !== null && strlen($s) > $maxLen) {
            $s = substr($s, 0, $maxLen);
        }
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
            // Bound the sanitize input so a multi-MB string value
            // doesn't make preg_replace scan megabytes per rendered
            // row — we only need ~40 chars of preview out.
            $preview = self::sanitizeForTerminal($sv, 80);
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

    /**
     * Resolve the node's own source location, applying the configured
     * path map. Returns null unless the node itself carries a filename
     * attribute (op_array / class_entry / call_frame).
     *
     * @return array{filename: string, line_start: ?int, line_end: ?int, line: ?int}|null
     */
    public function resolveSourceLocation(int $nodeId): ?array
    {
        $this->ensureLocationInfoLoaded();
        return $this->rawSourceLocation($nodeId);
    }

    /**
     * Resolve all known source locations for $nodeId with kind tags.
     *
     * Kinds (in order):
     *  - `self`        — the node directly carries a filename attribute.
     *  - `defined_at`  — for object zvals, the class_entry's filename.
     *  - `held_by`     — nearest ancestor with a known source location.
     *
     * @return list<array{kind: string, filename: string, line: ?int, line_start: ?int, line_end: ?int}>
     */
    public function resolveSourceLocations(int $nodeId): array
    {
        $this->ensureLocationInfoLoaded();
        if (isset($this->sourceLocationCache[$nodeId])) {
            return $this->sourceLocationCache[$nodeId];
        }

        $results = [];

        $self = $this->rawSourceLocation($nodeId);
        if ($self !== null) {
            $results[] = ['kind' => 'self'] + $self;
        }

        $definedAt = $this->resolveDefinedAt($nodeId);
        if ($definedAt !== null) {
            $results[] = ['kind' => 'defined_at'] + $definedAt;
        }

        // held_by is only interesting when we don't have self (a node
        // that owns its filename doesn't need to know who holds it).
        // An object zval's defined_at is more valuable, but a held_by
        // entry is still useful to identify the calling code. We emit
        // held_by unless $self already exists to keep the detail pane
        // from getting noisy on every op_array.
        if ($self === null) {
            $heldBy = $this->resolveHeldBy($nodeId);
            if ($heldBy !== null) {
                $results[] = ['kind' => 'held_by'] + $heldBy;
            }
        }

        return $this->sourceLocationCache[$nodeId] = $results;
    }

    /**
     * Format resolveSourceLocation() output as "file:line" (line_start
     * preferred, else lineno). Returns null when no location is known.
     */
    public function formatSourceLocation(int $nodeId): ?string
    {
        $loc = $this->resolveSourceLocation($nodeId);
        if ($loc === null) {
            return null;
        }
        return self::formatLocation($loc);
    }

    /**
     * Render a source location as a single `file:line` token the way
     * terminals expect it — so their built-in pattern matchers can
     * offer a clickable jump.
     *
     * Historically we also printed the closing line as `file:A-B`
     * for nodes that carry a range (op_array, class_entry), but the
     * range form is rejected by the file:line matcher in every
     * terminal and IDE I checked: PhpStorm, iTerm2, Kitty, VS Code
     * integrated terminal. The range is still exposed on
     * `source_locations[*].line_end` for anyone inspecting the raw
     * nodeDetail payload.
     *
     * @param array{filename: string, line: ?int, line_start: ?int, line_end: ?int} $loc
     */
    private static function formatLocation(array $loc): string
    {
        $file = $loc['filename'];
        $line = $loc['line'] ?? $loc['line_start'];
        if ($line !== null && $line > 0) {
            return "{$file}:{$line}";
        }
        return $file;
    }

    /**
     * Local filename/line extraction from the node's own attributes,
     * with path-map applied. Shared between the direct-resolver and
     * the multi-kind resolver.
     *
     * @return array{filename: string, line_start: ?int, line_end: ?int, line: ?int}|null
     */
    private function rawSourceLocation(int $nodeId): ?array
    {
        $attrs = $this->nodeAttributes[$nodeId] ?? [];
        if (!isset($attrs['filename']) || $attrs['filename'] === '') {
            return null;
        }
        $line_start = isset($attrs['line_start']) ? (int)$attrs['line_start'] : null;
        $line_end = isset($attrs['line_end']) ? (int)$attrs['line_end'] : null;
        $line = null;
        if (isset($attrs['lineno'])) {
            $line = (int)$attrs['lineno'];
        } elseif ($line_start !== null) {
            $line = $line_start;
        }
        return [
            'filename' => $this->pathMap->map($attrs['filename']),
            'line_start' => $line_start,
            'line_end' => $line_end,
            'line' => $line,
        ];
    }

    /**
     * Tree-link names whose target child carries the canonical
     * "this thing was defined at" filename. ClassDefinitionContext
     * has a `class_entry` child (a ClassEntryContext that knows
     * the user-defined class's filename); UserFunctionDefinitionContext
     * has an `op_array` child (a OpArrayContext with the function's
     * file:line range). Picking these by link-name keeps the
     * heuristic from leaking into unrelated structural children
     * (function_table, properties_info, …) that happen to share
     * the same parent.
     */
    private const DEFINING_CHILD_LINKS = ['class_entry', 'op_array'];

    /**
     * Find a child of $nodeId whose tree-link is one of the
     * known definition-bearing names AND whose own attributes
     * carry a filename. Returns the child node_id or null.
     */
    private function findDefiningChildNodeId(int $nodeId): ?int
    {
        foreach ($this->substrate->getChildren($nodeId) as $childId) {
            if (!isset($this->nodeAttributes[$childId]['filename'])) {
                continue;
            }
            $linkName = $this->substrate->getTreeLinkName($childId);
            if ($linkName === null) {
                continue;
            }
            if (in_array($linkName, self::DEFINING_CHILD_LINKS, true)) {
                return $childId;
            }
        }
        return null;
    }

    /**
     * @return array{filename: string, line_start: ?int, line_end: ?int, line: ?int}|null
     */
    private function resolveDefinedAt(int $nodeId): ?array
    {
        // Cached refs short-circuit both the class_entry index scan
        // and the child-link lookup.
        if ($this->sourceLocationRefs !== null) {
            $refs = $this->sourceLocationRefs[$nodeId] ?? null;
            if ($refs !== null && $refs['defined_at'] >= 0) {
                return $this->rawSourceLocation($refs['defined_at']);
            }
            if ($refs !== null) {
                return null;
            }
        }

        // Path 1: object zvals carry a class name in nodeClasses;
        // hop through the class_entry index to the matching class
        // definition's filename.
        $class = $this->resolveClass($nodeId);
        if ($class !== null && $class !== '') {
            $index = $this->classEntryIndex();
            $ceNodeId = $index[$class] ?? null;
            if ($ceNodeId !== null && $ceNodeId !== $nodeId) {
                $loc = $this->rawSourceLocation($ceNodeId);
                if ($loc !== null) {
                    return $loc;
                }
            }
        }

        // Path 2: definition wrappers (ClassDefinitionContext,
        // UserFunctionDefinitionContext) own a class_entry / op_array
        // child that carries the filename. Surface that as
        // defined_at so navigating onto a class_table entry shows
        // its source line directly.
        $childId = $this->findDefiningChildNodeId($nodeId);
        if ($childId !== null) {
            return $this->rawSourceLocation($childId);
        }
        return null;
    }

    /**
     * Walk the tree-parent chain and return the source location of the
     * nearest ancestor that has one. Bounded to 128 hops to keep
     * pathological cycles or very deep graphs cheap.
     *
     * @return array{filename: string, line_start: ?int, line_end: ?int, line: ?int}|null
     */
    private function resolveHeldBy(int $nodeId): ?array
    {
        if ($this->sourceLocationRefs !== null) {
            $refs = $this->sourceLocationRefs[$nodeId] ?? null;
            if ($refs !== null && $refs['held_by'] >= 0) {
                return $this->rawSourceLocation($refs['held_by']);
            }
            if ($refs !== null) {
                return null;
            }
        }
        $hops = 0;
        $cur = $this->substrate->getTreeParentNodeId($nodeId);
        while ($cur !== null && $cur >= 0 && $hops < 128) {
            $loc = $this->rawSourceLocation($cur);
            if ($loc !== null) {
                return $loc;
            }
            $cur = $this->substrate->getTreeParentNodeId($cur);
            $hops++;
        }
        return null;
    }

    /**
     * Build the packed source-location refs section for persisting to
     * the derived cache. Format: u32 node_id | i32 defined_at_nid |
     * i32 held_by_nid rows (12 bytes each).
     *
     * Only nodes that benefit from indirection (i.e. do not carry a
     * filename of their own) are included, since direct hits are
     * already served from the attributes section.
     *
     * @return array{bytes: string, count: int}
     */
    public function buildSourceLocationRefs(): array
    {
        $this->ensureLocationInfoLoaded();
        $classIndex = $this->classEntryIndex();
        $bytes = '';
        $count = 0;

        for ($nid = 0; $nid < $this->nodeCount; $nid++) {
            // Nodes with their own filename are "self" and don't need
            // a ref row.
            if (isset($this->nodeAttributes[$nid]['filename'])) {
                continue;
            }

            $definedAt = -1;
            // Path 1: object zvals → class_entry via class name.
            $class = $this->resolveClass($nid);
            if ($class !== null && $class !== '') {
                $cand = $classIndex[$class] ?? null;
                if (
                    $cand !== null
                    && $cand !== $nid
                    && isset($this->nodeAttributes[$cand]['filename'])
                ) {
                    $definedAt = $cand;
                }
            }
            // Path 2: definition wrappers (ClassDefinitionContext,
            // UserFunctionDefinitionContext) → their `class_entry`
            // / `op_array` child carrying the filename. Mirrors the
            // live resolveDefinedAt() fallback.
            if ($definedAt === -1) {
                $cand = $this->findDefiningChildNodeId($nid);
                if ($cand !== null) {
                    $definedAt = $cand;
                }
            }

            $heldBy = -1;
            $hops = 0;
            $cur = $this->substrate->getTreeParentNodeId($nid);
            while ($cur !== null && $cur >= 0 && $hops < 128) {
                if (isset($this->nodeAttributes[$cur]['filename'])) {
                    $heldBy = $cur;
                    break;
                }
                $cur = $this->substrate->getTreeParentNodeId($cur);
                $hops++;
            }

            if ($definedAt === -1 && $heldBy === -1) {
                continue;
            }
            $bytes .= pack('Vll', $nid, $definedAt, $heldBy);
            $count++;
        }
        return ['bytes' => $bytes, 'count' => $count];
    }

    /**
     * Consume a previously built source-location ref blob so that
     * resolveSourceLocations() can skip the live ancestor walk.
     *
     * Idempotent — the last call wins.
     */
    public function primeSourceLocationRefs(string $bytes, int $count): void
    {
        $refs = [];
        $rowSize = \Reli\Inspector\Output\MemoryOutput\BinaryFormat\DerivedCacheFormat::SOURCE_LOC_REF_ROW_SIZE;
        for ($i = 0; $i < $count; $i++) {
            $offset = $i * $rowSize;
            if ($offset + $rowSize > strlen($bytes)) {
                break;
            }
            /** @var array{1: int} $nidArr */
            $nidArr = unpack('V', $bytes, $offset);
            /** @var array{1: int} $definedArr */
            $definedArr = unpack('l', $bytes, $offset + 4);
            /** @var array{1: int} $heldArr */
            $heldArr = unpack('l', $bytes, $offset + 8);
            $refs[$nidArr[1]] = [
                'defined_at' => $definedArr[1],
                'held_by' => $heldArr[1],
            ];
        }
        $this->sourceLocationRefs = $refs;
        // Invalidate memo so resolveSourceLocations() re-queries with
        // the fresh indirection table.
        $this->sourceLocationCache = [];
    }

    /**
     * Try to load source-location refs from the derived cache sidecar
     * for the given rmem file. Returns true if refs were loaded.
     */
    public function tryLoadSourceLocationRefsFromCache(string $rmemPath): bool
    {
        $cache = \Reli\Inspector\Output\MemoryOutput\BinaryFormat\DerivedCacheReader::open($rmemPath);
        if ($cache === null) {
            return false;
        }
        $section = \Reli\Inspector\Output\MemoryOutput\BinaryFormat\DerivedCacheFormat::SECTION_SOURCE_LOC_REFS;
        if (!$cache->hasSection($section)) {
            return false;
        }
        $data = $cache->getSectionData($section);
        $count = $cache->getSectionElementCount($section);
        if ($data === null || $count === 0) {
            return false;
        }
        $this->ensureLocationInfoLoaded();
        $this->primeSourceLocationRefs($data, $count);
        return true;
    }

    /**
     * Build (and memoize) the class_name -> class_entry node_id index
     * by scanning nodeAttributes for `class_name` entries. Populated on
     * first use so pure-graph operations don't pay for it.
     *
     * @return array<string, int>
     */
    private function classEntryIndex(): array
    {
        if ($this->classEntryIndex !== null) {
            return $this->classEntryIndex;
        }
        $index = [];
        foreach ($this->nodeAttributes as $nid => $attrs) {
            if (!isset($attrs['class_name'])) {
                continue;
            }
            // Prefer the first emitted entry; the dumper visits a class
            // once per class_table walk so collisions should not happen,
            // but if they do we keep the lower node_id for determinism.
            $name = $attrs['class_name'];
            if (!isset($index[$name]) || $nid < $index[$name]) {
                $index[$name] = $nid;
            }
        }
        return $this->classEntryIndex = $index;
    }

    public function nodeLabel(int $nodeId): string
    {
        // Sentinel: synthetic parent-of-all-roots. Show a friendly name
        // so the sandwich view's parents pane does not confront the user
        // with "node#-1" when they walk off the top of the tree.
        if ($nodeId < 0) {
            return '(roots)';
        }
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
     *
     * @return array{
     *     type: string,
     *     class: ?string,
     *     shallow: int,
     *     retained: int,
     *     address: ?int,
     *     string_value: ?string,
     *     refcount: ?int,
     *     attributes: array<string, string>,
     *     source_location: ?string,
     *     source_locations: list<array{kind: string, filename: string, line: ?int, line_start: ?int, line_end: ?int, formatted: string}>,
     * }
     */
    public function nodeDetail(int $nodeId): array
    {
        $this->ensureLocationInfoLoaded();

        $locations = [];
        foreach ($this->resolveSourceLocations($nodeId) as $loc) {
            $formatted = self::formatLocation([
                'filename' => $loc['filename'],
                'line' => $loc['line'],
                'line_start' => $loc['line_start'],
                'line_end' => $loc['line_end'],
            ]);
            $locations[] = $loc + ['formatted' => $formatted];
        }

        return [
            'type' => $this->substrate->getNodeType($nodeId) ?? '?',
            'class' => $this->resolveClass($nodeId),
            'shallow' => $this->substrate->getNodeSize($nodeId),
            'retained' => $this->substrate->getSubtreeSize($nodeId),
            'address' => $this->nodeAddresses[$nodeId] ?? null,
            'string_value' => $this->nodeStringValues[$nodeId] ?? null,
            'refcount' => $this->nodeRefcounts[$nodeId] ?? null,
            'attributes' => $this->nodeAttributes[$nodeId] ?? [],
            'source_location' => $locations !== [] ? $locations[0]['formatted'] : null,
            'source_locations' => $locations,
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
