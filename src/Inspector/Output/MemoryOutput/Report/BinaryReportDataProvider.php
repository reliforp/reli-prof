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

namespace Reli\Inspector\Output\MemoryOutput\Report;

use FFI;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Format;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\StringDict;
use Reli\Lib\FFI\FFIHelper;

/**
 * Extracts report-pass data from a .rmem binary file.
 *
 * Provides the data that Phase 2/3 hybrid passes would normally get
 * via SQL queries, computed from the binary sections instead.
 */
final class BinaryReportDataProvider
{
    /**
     * Find node IDs that have a specific location_type.
     *
     * Replaces: SELECT DISTINCT node_id FROM context_node_locations
     *           WHERE location_type = ?
     *
     * @return array<int, true> node_id => true
     * @psalm-suppress InaccessibleMethod
     * @psalm-suppress PossiblyNullPropertyFetch
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress InvalidPropertyFetch
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    public static function getNodesByLocationType(
        BinaryReader $reader,
        string $location_type_name,
    ): array {
        if (!$reader->hasSection(Format::SECTION_LOCATIONS)) {
            return [];
        }

        $dict = $reader->getStringDict();
        $count = $reader->getSectionElementCount(Format::SECTION_LOCATIONS);
        $locRows = $reader->castSection(Format::SECTION_LOCATIONS, 'LocationRow');

        $result = [];
        if ($locRows !== null) {
            for ($i = 0; $i < $count; $i++) {
                $type = $dict->lookup((int)$locRows[$i]->location_type_id);
                if ($type === $location_type_name) {
                    $result[(int)$locRows[$i]->node_id] = true;
                }
            }
        } else {
            $data = $reader->getSectionData(Format::SECTION_LOCATIONS);
            for ($i = 0; $i < $count; $i++) {
                $off = $i * Format::LOCATION_ROW_SIZE;
                $node_id = unpack('V', $data, $off)[1];
                $type_id = unpack('V', $data, $off + 4)[1];
                $type = $dict->lookup((int)$type_id);
                if ($type === $location_type_name) {
                    $result[(int)$node_id] = true;
                }
            }
        }
        return $result;
    }

    /**
     * Get top N strings by size with preview.
     *
     * Replaces: SELECT node_id, size, substr(string_value,1,80) as preview
     *           FROM context_node_locations
     *           WHERE location_type = 'ZendStringMemoryLocation'
     *           ORDER BY size DESC LIMIT N
     *
     * @return list<array{node_id: int, size: int, preview: string}>
     * @psalm-suppress InaccessibleMethod
     * @psalm-suppress PossiblyNullPropertyFetch
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress InvalidPropertyFetch
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    public static function getTopStrings(
        BinaryReader $reader,
        int $limit = 10,
    ): array {
        if (!$reader->hasSection(Format::SECTION_LOCATIONS)) {
            return [];
        }

        $dict = $reader->getStringDict();
        $count = $reader->getSectionElementCount(Format::SECTION_LOCATIONS);
        $locRows = $reader->castSection(Format::SECTION_LOCATIONS, 'LocationRow');

        // Collect all ZendStringMemoryLocation entries
        /** @var list<array{node_id: int, size: int, string_value_id: int}> $candidates */
        $candidates = [];
        if ($locRows !== null) {
            for ($i = 0; $i < $count; $i++) {
                $type = $dict->lookup((int)$locRows[$i]->location_type_id);
                if ($type === 'ZendStringMemoryLocation') {
                    $candidates[] = [
                        'node_id' => (int)$locRows[$i]->node_id,
                        'size' => (int)$locRows[$i]->size,
                        'string_value_id' => (int)$locRows[$i]->string_value_id,
                    ];
                }
            }
        } else {
            $data = $reader->getSectionData(Format::SECTION_LOCATIONS);
            for ($i = 0; $i < $count; $i++) {
                $off = $i * Format::LOCATION_ROW_SIZE;
                $type_id = unpack('V', $data, $off + 4)[1];
                $type = $dict->lookup((int)$type_id);
                if ($type === 'ZendStringMemoryLocation') {
                    $candidates[] = [
                        'node_id' => (int)unpack('V', $data, $off)[1],
                        'size' => (int)unpack('P', $data, $off + 20)[1],
                        'string_value_id' => (int)unpack('V', $data, $off + 28)[1],
                    ];
                }
            }
        }

        // Sort by size descending, take top N
        usort($candidates, fn (array $a, array $b): int => $b['size'] <=> $a['size']);
        $candidates = array_slice($candidates, 0, $limit);

        // Resolve string previews
        $result = [];
        foreach ($candidates as $c) {
            $full = $dict->lookup($c['string_value_id']);
            $preview = $full !== null ? substr($full, 0, 80) : '';
            $result[] = [
                'node_id' => $c['node_id'],
                'size' => $c['size'],
                'preview' => $preview,
            ];
        }
        return $result;
    }

    /**
     * Compute non-tree strong edge aggregation by link_name.
     *
     * Replaces the GROUP BY link_name query in NonTreeEdgePass::analyzeWithSubstrate.
     *
     * @return list<array{link_name: string, ref_count: int, target_count: int,
     *     sample_parent_node_id: int, sample_child_node_id: int}>
     * @psalm-suppress InaccessibleMethod
     * @psalm-suppress PossiblyNullPropertyFetch
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress InvalidPropertyFetch
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    public static function getNonTreeEdgeStats(
        BinaryReader $reader,
        int $limit = 20,
    ): array {
        if (!$reader->hasSection(Format::SECTION_EDGES)) {
            return [];
        }

        $dict = $reader->getStringDict();
        $count = $reader->getSectionElementCount(Format::SECTION_EDGES);
        $edgeRows = $reader->castSection(Format::SECTION_EDGES, 'EdgeRow');

        // Aggregate: link_name_id => {count, targets set, sample_parent, sample_child}
        /** @var array<int, array{count: int, targets: array<int, true>, sample_parent: int, sample_child: int}> */
        $agg = [];
        if ($edgeRows !== null) {
            for ($i = 0; $i < $count; $i++) {
                if ((int)$edgeRows[$i]->is_tree !== 0 || (int)$edgeRows[$i]->strength !== 0) {
                    continue; // skip tree edges and non-strong edges
                }
                $lid = (int)$edgeRows[$i]->link_name_id;
                $parent = (int)$edgeRows[$i]->parent_node_id;
                $child = (int)$edgeRows[$i]->child_node_id;
                if (!isset($agg[$lid])) {
                    $agg[$lid] = ['count' => 0, 'targets' => [], 'sample_parent' => $parent, 'sample_child' => $child];
                }
                $agg[$lid]['count']++;
                $agg[$lid]['targets'][$child] = true;
            }
        } else {
            $data = $reader->getSectionData(Format::SECTION_EDGES);
            for ($i = 0; $i < $count; $i++) {
                $off = $i * Format::EDGE_ROW_SIZE;
                $row = unpack('Vparent/Vchild/Vlid/Cis_tree/Cstrength', $data, $off);
                if ((int)$row['is_tree'] !== 0 || (int)$row['strength'] !== 0) {
                    continue;
                }
                $lid = (int)$row['lid'];
                $parent = (int)$row['parent'];
                $child = (int)$row['child'];
                if (!isset($agg[$lid])) {
                    $agg[$lid] = ['count' => 0, 'targets' => [], 'sample_parent' => $parent, 'sample_child' => $child];
                }
                $agg[$lid]['count']++;
                $agg[$lid]['targets'][$child] = true;
            }
        }

        // Filter HAVING count > 10, sort by count DESC, resolve link names
        $results = [];
        foreach ($agg as $lid => $info) {
            if ($info['count'] <= 10) {
                continue;
            }
            $link_name = $dict->lookup($lid);
            if ($link_name === null) {
                continue;
            }
            $results[] = [
                'link_name' => $link_name,
                'ref_count' => $info['count'],
                'target_count' => count($info['targets']),
                'sample_parent_node_id' => $info['sample_parent'],
                'sample_child_node_id' => $info['sample_child'],
            ];
        }
        usort($results, fn (array $a, array $b): int => $b['ref_count'] <=> $a['ref_count']);
        return array_slice($results, 0, $limit);
    }

    /**
     * Compute dedup_candidate groups from a binary file.
     *
     * Uses a coarse first pass to shortlist likely groups, then a second pass
     * that de-duplicates by child_node_id so shared fan-in does not inflate
     * the reported size beyond the underlying node sizes.
     *
     * @return list<array{
     *     link_name: string,
     *     size: int,
     *     cnt: int,
     *     total_waste: int,
     *     sample_parent_node_id: int,
     *     sample_child_node_id: int,
     *     sample_location_type: ?string,
     *     sample_child_node_ids: list<int>,
     *     examples: array<string, mixed>
     * }>
     * @psalm-suppress InaccessibleMethod
     * @psalm-suppress PossiblyNullPropertyFetch
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress InvalidPropertyFetch
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    public static function getDedupCandidateStats(
        BinaryReader $reader,
        int $limit = 10,
    ): array {
        if (
            !$reader->hasSection(Format::SECTION_EDGES)
            || !$reader->hasSection(Format::SECTION_LOCATIONS)
        ) {
            return [];
        }

        $dict = $reader->getStringDict();

        // Use on-disk node_sizes (int64, indexed by node_id) instead
        // of loading all locations into a PHP array. This avoids the ~1 GB
        // $node_meta PHP array that was the #1 PHP heap consumer.
        $nodeSizesSlots = $reader->getSectionElementCount('node_sizes');
        if (!$reader->hasSection('node_sizes') || $nodeSizesSlots === 0) {
            return self::getDedupCandidateStatsFallback($reader, $limit);
        }
        $expected = $nodeSizesSlots * 8;
        $nodeSizes = FFIHelper::new("int64_t[{$nodeSizesSlots}]");
        $sizePtr = $reader->getSectionPointer('node_sizes');
        if ($sizePtr !== null) {
            // Zero-copy from mmap
            FFI::memcpy($nodeSizes, $sizePtr, $expected);
        } else {
            $rawSizes = $reader->getSectionData('node_sizes');
            if (strlen($rawSizes) < $expected) {
                return self::getDedupCandidateStatsFallback($reader, $limit);
            }
            FFI::memcpy($nodeSizes, $rawSizes, $expected);
            unset($rawSizes);
        }

        /** @var array<string, array{
         *     link_name_id: int,
         *     size: int,
         *     ref_count: int,
         *     sample_parent_node_id: int,
         *     sample_child_node_id: int
         * }> $coarse
         */
        $coarse = [];
        $edge_count = $reader->getSectionElementCount(Format::SECTION_EDGES);
        $edgeRows = $reader->castSection(Format::SECTION_EDGES, 'EdgeRow');

        if ($edgeRows !== null) {
            for ($i = 0; $i < $edge_count; $i++) {
                if ((int)$edgeRows[$i]->is_tree !== 0 || (int)$edgeRows[$i]->strength !== 0) {
                    continue;
                }
                $child = (int)$edgeRows[$i]->child_node_id;
                if ($child < 0 || $child >= $nodeSizesSlots) {
                    continue;
                }
                $size = (int)$nodeSizes[$child];
                if ($size <= 0) {
                    continue;
                }
                $lid = (int)$edgeRows[$i]->link_name_id;
                $key = $lid . ':' . $size;
                if (!isset($coarse[$key])) {
                    $coarse[$key] = [
                        'link_name_id' => $lid,
                        'size' => $size,
                        'ref_count' => 0,
                        'sample_parent_node_id' => (int)$edgeRows[$i]->parent_node_id,
                        'sample_child_node_id' => $child,
                    ];
                }
                $coarse[$key]['ref_count']++;
            }
        } else {
            $data = $reader->getSectionData(Format::SECTION_EDGES);
            for ($i = 0; $i < $edge_count; $i++) {
                $off = $i * Format::EDGE_ROW_SIZE;
                $row = unpack('Vparent/Vchild/Vlid/Cis_tree/Cstrength', $data, $off);
                if ((int)$row['is_tree'] !== 0 || (int)$row['strength'] !== 0) {
                    continue;
                }
                $child = (int)$row['child'];
                if ($child < 0 || $child >= $nodeSizesSlots) {
                    continue;
                }
                $size = (int)$nodeSizes[$child];
                if ($size <= 0) {
                    continue;
                }
                $lid = (int)$row['lid'];
                $key = $lid . ':' . $size;
                if (!isset($coarse[$key])) {
                    $coarse[$key] = [
                        'link_name_id' => $lid,
                        'size' => $size,
                        'ref_count' => 0,
                        'sample_parent_node_id' => (int)$row['parent'],
                        'sample_child_node_id' => $child,
                    ];
                }
                $coarse[$key]['ref_count']++;
            }
        }

        // Build a location index: node_id → best LocationRow index.
        // Dense FFI int32 array indexed by node_id (-1 = no location).
        // A node may have multiple LocationRows (e.g. array header + table).
        // Prefer the row that has class_id or string_value_id set, matching
        // the first-non-null logic in the original loadNodeMeta.
        $locCount = $reader->getSectionElementCount(Format::SECTION_LOCATIONS);
        $locRows = $reader->castSection(Format::SECTION_LOCATIONS, 'LocationRow');
        /** @var \FFI\CData|null $locIndex int32[nodeSizesSlots], -1 = no location */
        $locIndex = null;
        if ($locRows !== null && $nodeSizesSlots > 0) {
            $locIndex = FFIHelper::new("int32_t[{$nodeSizesSlots}]");
            FFI::memset($locIndex, 0xFF, $nodeSizesSlots * 4); // fill with -1
            for ($i = 0; $i < $locCount; $i++) {
                $nid = (int)$locRows[$i]->node_id;
                if ($nid < 0 || $nid >= $nodeSizesSlots) {
                    continue;
                }
                $cur = (int)$locIndex[$nid];
                if ($cur === -1) {
                    // First row for this node
                    $locIndex[$nid] = $i;
                } else {
                    // Prefer a row with richer metadata (class_id or string_value_id)
                    $curHas = ((int)$locRows[$cur]->class_id !== Format::NULL_STRING_ID)
                           || ((int)$locRows[$cur]->string_value_id !== Format::NULL_STRING_ID);
                    if (!$curHas) {
                        $newHas = ((int)$locRows[$i]->class_id !== Format::NULL_STRING_ID)
                               || ((int)$locRows[$i]->string_value_id !== Format::NULL_STRING_ID);
                        if ($newHas) {
                            $locIndex[$nid] = $i;
                        }
                    }
                }
            }
        }

        $candidate_limit = max($limit * 16, 64);
        /** @var list<array{
         *     key: string,
         *     link_name: string,
         *     size: int,
         *     coarse_total: int,
         *     sample_parent_node_id: int,
         *     sample_child_node_id: int,
         *     sample_location_type: ?string
         * }> $candidates
         */
        $candidates = [];
        foreach ($coarse as $key => $info) {
            $coarse_total = $info['ref_count'] * $info['size'];
            if ($info['ref_count'] <= 50 || $coarse_total <= 10240) {
                continue;
            }
            $link_name = $dict->lookup($info['link_name_id']);
            if ($link_name === null || $link_name === 'key') {
                continue;
            }
            $sampleLocType = null;
            if ($locIndex !== null && $locRows !== null) {
                $li = (int)$locIndex[$info['sample_child_node_id']];
                if ($li >= 0) {
                    $sampleLocType = $dict->lookup((int)$locRows[$li]->location_type_id);
                }
            }
            $candidates[] = [
                'key' => $key,
                'link_name' => $link_name,
                'size' => $info['size'],
                'coarse_total' => $coarse_total,
                'sample_parent_node_id' => $info['sample_parent_node_id'],
                'sample_child_node_id' => $info['sample_child_node_id'],
                'sample_location_type' => $sampleLocType,
            ];
        }
        usort($candidates, fn (array $a, array $b): int => $b['coarse_total'] <=> $a['coarse_total']);
        $candidates = array_slice($candidates, 0, $candidate_limit);

        /** @var array<string, array{
         *     link_name: string,
         *     size: int,
         *     sample_parent_node_id: int,
         *     sample_child_node_id: int,
         *     sample_location_type: ?string,
         *     sample_child_node_ids: list<int>,
         *     seen_children: array<int, true>,
         *     string_counts: array<string, int>,
         *     object_samples: list<string>
         * }> $groups
         */
        $groups = [];
        foreach ($candidates as $candidate) {
            $groups[$candidate['key']] = [
                'link_name' => $candidate['link_name'],
                'size' => $candidate['size'],
                'sample_parent_node_id' => $candidate['sample_parent_node_id'],
                'sample_child_node_id' => $candidate['sample_child_node_id'],
                'sample_location_type' => $candidate['sample_location_type'],
                'sample_child_node_ids' => [],
                'seen_children' => [],
                'string_counts' => [],
                'object_samples' => [],
            ];
        }

        if ($groups === []) {
            return [];
        }

        // 2nd edge scan: accumulate dedup details per group.
        // Build $meta on demand from nodeSizes + locIndex/locRows.
        if ($edgeRows !== null) {
            for ($i = 0; $i < $edge_count; $i++) {
                if ((int)$edgeRows[$i]->is_tree !== 0 || (int)$edgeRows[$i]->strength !== 0) {
                    continue;
                }
                $child = (int)$edgeRows[$i]->child_node_id;
                if ($child < 0 || $child >= $nodeSizesSlots) {
                    continue;
                }
                $size = (int)$nodeSizes[$child];
                if ($size <= 0) {
                    continue;
                }
                $key = (int)$edgeRows[$i]->link_name_id . ':' . $size;
                if (!isset($groups[$key])) {
                    continue;
                }
                $meta = self::buildNodeMeta($child, $size, $locIndex, $locRows, $dict);
                self::accumulateDedupGroup($groups[$key], $child, $meta, $dict);
            }
        } else {
            $data = $reader->getSectionData(Format::SECTION_EDGES);
            for ($i = 0; $i < $edge_count; $i++) {
                $off = $i * Format::EDGE_ROW_SIZE;
                $row = unpack('Vparent/Vchild/Vlid/Cis_tree/Cstrength', $data, $off);
                if ((int)$row['is_tree'] !== 0 || (int)$row['strength'] !== 0) {
                    continue;
                }
                $child = (int)$row['child'];
                if ($child < 0 || $child >= $nodeSizesSlots) {
                    continue;
                }
                $size = (int)$nodeSizes[$child];
                if ($size <= 0) {
                    continue;
                }
                $key = (int)$row['lid'] . ':' . $size;
                if (!isset($groups[$key])) {
                    continue;
                }
                $meta = self::buildNodeMeta($child, $size, $locIndex, $locRows, $dict);
                self::accumulateDedupGroup($groups[$key], $child, $meta, $dict);
            }
        }

        $results = [];
        foreach ($groups as $group) {
            $cnt = count($group['seen_children']);
            $total_waste = $cnt * $group['size'];
            if ($cnt <= 50 || $total_waste <= 10240) {
                continue;
            }

            $results[] = [
                'link_name' => $group['link_name'],
                'size' => $group['size'],
                'cnt' => $cnt,
                'total_waste' => $total_waste,
                'sample_parent_node_id' => $group['sample_parent_node_id'],
                'sample_child_node_id' => $group['sample_child_node_id'],
                'sample_location_type' => $group['sample_location_type'],
                'sample_child_node_ids' => $group['sample_child_node_ids'],
                'examples' => self::buildDedupExamples(
                    $group['string_counts'],
                    $group['object_samples'],
                ),
            ];
        }

        usort($results, fn (array $a, array $b): int => $b['total_waste'] <=> $a['total_waste']);
        return array_slice($results, 0, $limit);
    }

    /**
     * Load frame labels (function_name:lineno) from the binary attributes section.
     *
     * Replaces NodeLabeler's SQL query on context_node_attributes.
     *
     * @return array<int, string> node_id => "function_name:lineno"
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    public static function loadFrameLabels(BinaryReader $reader): array
    {
        if (!$reader->hasSection(Format::SECTION_ATTRIBUTES)) {
            return [];
        }

        $dict = $reader->getStringDict();
        $data = $reader->getSectionData(Format::SECTION_ATTRIBUTES);
        $count = $reader->getSectionElementCount(Format::SECTION_ATTRIBUTES);

        /** @var array<int, array{function_name?: string, lineno?: string}> $by_node */
        $by_node = [];
        $offset = 0;
        for ($i = 0; $i < $count; $i++) {
            $row = unpack('Vnode_id/Vkey_id/Vvalue_id', $data, $offset);
            $offset += 12;

            $key = $dict->lookup((int)$row['key_id']);
            if ($key !== 'function_name' && $key !== 'lineno') {
                continue;
            }
            $value = $dict->lookup((int)$row['value_id']) ?? '';
            $node_id = (int)$row['node_id'];
            if ($key === 'function_name') {
                $by_node[$node_id]['function_name'] = $value;
            } else {
                $by_node[$node_id]['lineno'] = $value;
            }
        }

        $labels = [];
        foreach ($by_node as $node_id => $kvs) {
            $fn = $kvs['function_name'] ?? '';
            if ($fn === '') {
                continue;
            }
            $ln = $kvs['lineno'] ?? '';
            $labels[$node_id] = $ln !== '' ? "{$fn}:{$ln}" : $fn;
        }
        return $labels;
    }

    /**
     * Build a single node's meta on demand from nodeSizes + locIndex/locRows.
     *
     * @return array{size: int, location_type: ?string, class_name: ?string, string_value_id: ?int}
     */
    private static function buildNodeMeta(
        int $nodeId,
        int $size,
        ?\FFI\CData $locIndex,
        ?\FFI\CData $locRows,
        StringDict $dict,
    ): array {
        $meta = [
            'size' => $size,
            'location_type' => null,
            'class_name' => null,
            'string_value_id' => null,
        ];
        if ($locIndex === null || $locRows === null) {
            return $meta;
        }
        $li = (int)$locIndex[$nodeId];
        if ($li < 0) {
            return $meta;
        }
        $meta['location_type'] = $dict->lookup((int)$locRows[$li]->location_type_id);
        $classId = (int)$locRows[$li]->class_id;
        if ($classId !== Format::NULL_STRING_ID) {
            $meta['class_name'] = $dict->lookup($classId);
        }
        $svId = (int)$locRows[$li]->string_value_id;
        if ($svId !== Format::NULL_STRING_ID) {
            $meta['string_value_id'] = $svId;
        }
        return $meta;
    }

    /**
     * Fallback for getDedupCandidateStats when node_sizes section is missing.
     * Uses the original loadNodeMeta approach.
     *
     * @return list<array<string, mixed>>
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    private static function getDedupCandidateStatsFallback(
        BinaryReader $reader,
        int $limit,
    ): array {
        // Delegate to the original full-meta approach
        $node_meta = self::loadNodeMeta($reader);
        if ($node_meta === []) {
            return [];
        }
        $dict = $reader->getStringDict();
        $edge_count = $reader->getSectionElementCount(Format::SECTION_EDGES);
        $edgeRows = $reader->castSection(Format::SECTION_EDGES, 'EdgeRow');

        $coarse = [];
        if ($edgeRows !== null) {
            for ($i = 0; $i < $edge_count; $i++) {
                if ((int)$edgeRows[$i]->is_tree !== 0 || (int)$edgeRows[$i]->strength !== 0) {
                    continue;
                }
                $child = (int)$edgeRows[$i]->child_node_id;
                $meta = $node_meta[$child] ?? null;
                if ($meta === null || $meta['size'] <= 0) {
                    continue;
                }
                $lid = (int)$edgeRows[$i]->link_name_id;
                $key = $lid . ':' . $meta['size'];
                if (!isset($coarse[$key])) {
                    $coarse[$key] = [
                        'link_name_id' => $lid,
                        'size' => $meta['size'],
                        'ref_count' => 0,
                        'sample_parent_node_id' => (int)$edgeRows[$i]->parent_node_id,
                        'sample_child_node_id' => $child,
                    ];
                }
                $coarse[$key]['ref_count']++;
            }
        } else {
            $edgeData = $reader->getSectionData(Format::SECTION_EDGES);
            for ($i = 0; $i < $edge_count; $i++) {
                $off = $i * Format::EDGE_ROW_SIZE;
                $row = unpack('Vparent/Vchild/Vlid/Cis_tree/Cstrength', $edgeData, $off);
                if ((int)$row['is_tree'] !== 0 || (int)$row['strength'] !== 0) {
                    continue;
                }
                $child = (int)$row['child'];
                $meta = $node_meta[$child] ?? null;
                if ($meta === null || $meta['size'] <= 0) {
                    continue;
                }
                $lid = (int)$row['lid'];
                $key = $lid . ':' . $meta['size'];
                if (!isset($coarse[$key])) {
                    $coarse[$key] = [
                        'link_name_id' => $lid,
                        'size' => $meta['size'],
                        'ref_count' => 0,
                        'sample_parent_node_id' => (int)$row['parent'],
                        'sample_child_node_id' => $child,
                    ];
                }
                $coarse[$key]['ref_count']++;
            }
        }

        // Build results using the same logic but with full $node_meta
        $candidate_limit = max($limit * 16, 64);
        $candidates = [];
        foreach ($coarse as $key => $info) {
            $coarse_total = $info['ref_count'] * $info['size'];
            if ($info['ref_count'] <= 50 || $coarse_total <= 10240) {
                continue;
            }
            $link_name = $dict->lookup($info['link_name_id']);
            if ($link_name === null || $link_name === 'key') {
                continue;
            }
            $sample_child_meta = $node_meta[$info['sample_child_node_id']] ?? null;
            $candidates[] = [
                'key' => $key,
                'link_name' => $link_name,
                'size' => $info['size'],
                'coarse_total' => $coarse_total,
                'sample_parent_node_id' => $info['sample_parent_node_id'],
                'sample_child_node_id' => $info['sample_child_node_id'],
                'sample_location_type' => $sample_child_meta['location_type'] ?? null,
            ];
        }
        usort($candidates, fn (array $a, array $b): int => $b['coarse_total'] <=> $a['coarse_total']);
        $candidates = array_slice($candidates, 0, $candidate_limit);

        $groups = [];
        foreach ($candidates as $candidate) {
            $groups[$candidate['key']] = [
                'link_name' => $candidate['link_name'],
                'size' => $candidate['size'],
                'sample_parent_node_id' => $candidate['sample_parent_node_id'],
                'sample_child_node_id' => $candidate['sample_child_node_id'],
                'sample_location_type' => $candidate['sample_location_type'],
                'sample_child_node_ids' => [],
                'seen_children' => [],
                'string_counts' => [],
                'object_samples' => [],
            ];
        }
        if ($groups === []) {
            return [];
        }
        if ($edgeRows !== null) {
            for ($i = 0; $i < $edge_count; $i++) {
                if ((int)$edgeRows[$i]->is_tree !== 0 || (int)$edgeRows[$i]->strength !== 0) {
                    continue;
                }
                $child = (int)$edgeRows[$i]->child_node_id;
                $meta = $node_meta[$child] ?? null;
                if ($meta === null || $meta['size'] <= 0) {
                    continue;
                }
                $key = (int)$edgeRows[$i]->link_name_id . ':' . $meta['size'];
                if (!isset($groups[$key])) {
                    continue;
                }
                self::accumulateDedupGroup($groups[$key], $child, $meta, $dict);
            }
        } else {
            $edgeData = $reader->getSectionData(Format::SECTION_EDGES);
            for ($i = 0; $i < $edge_count; $i++) {
                $off = $i * Format::EDGE_ROW_SIZE;
                $row = unpack('Vparent/Vchild/Vlid/Cis_tree/Cstrength', $edgeData, $off);
                if ((int)$row['is_tree'] !== 0 || (int)$row['strength'] !== 0) {
                    continue;
                }
                $child = (int)$row['child'];
                $meta = $node_meta[$child] ?? null;
                if ($meta === null || $meta['size'] <= 0) {
                    continue;
                }
                $key = (int)$row['lid'] . ':' . $meta['size'];
                if (!isset($groups[$key])) {
                    continue;
                }
                self::accumulateDedupGroup($groups[$key], $child, $meta, $dict);
            }
        }

        $results = [];
        foreach ($groups as $group) {
            $cnt = count($group['seen_children']);
            $total_waste = $cnt * $group['size'];
            if ($cnt <= 50 || $total_waste <= 10240) {
                continue;
            }
            $results[] = [
                'link_name' => $group['link_name'],
                'size' => $group['size'],
                'cnt' => $cnt,
                'total_waste' => $total_waste,
                'sample_parent_node_id' => $group['sample_parent_node_id'],
                'sample_child_node_id' => $group['sample_child_node_id'],
                'sample_location_type' => $group['sample_location_type'],
                'sample_child_node_ids' => $group['sample_child_node_ids'],
                'examples' => self::buildDedupExamples(
                    $group['string_counts'],
                    $group['object_samples'],
                ),
            ];
        }
        usort($results, fn (array $a, array $b): int => $b['total_waste'] <=> $a['total_waste']);
        return array_slice($results, 0, $limit);
    }

    /**
     * @return array<int, array{
     *     size: int,
     *     location_type: ?string,
     *     class_name: ?string,
     *     string_value_id: ?int
     * }>
     * @psalm-suppress InaccessibleMethod
     * @psalm-suppress PossiblyNullPropertyFetch
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress InvalidPropertyFetch
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    private static function loadNodeMeta(BinaryReader $reader): array
    {
        $dict = $reader->getStringDict();
        $count = $reader->getSectionElementCount(Format::SECTION_LOCATIONS);
        $locRows = $reader->castSection(Format::SECTION_LOCATIONS, 'LocationRow');

        /** @var array<int, array{
         *     size: int,
         *     location_type: ?string,
         *     class_name: ?string,
         *     string_value_id: ?int
         * }> $meta
         */
        $meta = [];
        if ($locRows !== null) {
            for ($i = 0; $i < $count; $i++) {
                $node_id = (int)$locRows[$i]->node_id;
                if (!isset($meta[$node_id])) {
                    $meta[$node_id] = [
                        'size' => 0,
                        'location_type' => null,
                        'class_name' => null,
                        'string_value_id' => null,
                    ];
                }
                $meta[$node_id]['size'] += (int)$locRows[$i]->size;
                if ($meta[$node_id]['location_type'] === null) {
                    $meta[$node_id]['location_type'] = $dict->lookup((int)$locRows[$i]->location_type_id);
                }
                if (
                    $meta[$node_id]['class_name'] === null
                    && (int)$locRows[$i]->class_id !== Format::NULL_STRING_ID
                ) {
                    $meta[$node_id]['class_name'] = $dict->lookup((int)$locRows[$i]->class_id);
                }
                if (
                    $meta[$node_id]['string_value_id'] === null
                    && (int)$locRows[$i]->string_value_id !== Format::NULL_STRING_ID
                ) {
                    $meta[$node_id]['string_value_id'] = (int)$locRows[$i]->string_value_id;
                }
            }
        } else {
            $data = $reader->getSectionData(Format::SECTION_LOCATIONS);
            for ($i = 0; $i < $count; $i++) {
                $off = $i * Format::LOCATION_ROW_SIZE;
                $row = unpack(
                    'Vnode_id/Vlocation_type_id/Vclass_id/Paddress/Psize/Vstring_value_id',
                    $data,
                    $off,
                );
                $node_id = (int)$row['node_id'];
                if (!isset($meta[$node_id])) {
                    $meta[$node_id] = [
                        'size' => 0,
                        'location_type' => null,
                        'class_name' => null,
                        'string_value_id' => null,
                    ];
                }
                $meta[$node_id]['size'] += (int)$row['size'];
                if ($meta[$node_id]['location_type'] === null) {
                    $meta[$node_id]['location_type'] = $dict->lookup((int)$row['location_type_id']);
                }
                if (
                    $meta[$node_id]['class_name'] === null
                    && (int)$row['class_id'] !== Format::NULL_STRING_ID
                ) {
                    $meta[$node_id]['class_name'] = $dict->lookup((int)$row['class_id']);
                }
                if (
                    $meta[$node_id]['string_value_id'] === null
                    && (int)$row['string_value_id'] !== Format::NULL_STRING_ID
                ) {
                    $meta[$node_id]['string_value_id'] = (int)$row['string_value_id'];
                }
            }
        }

        return $meta;
    }

    /**
     * @param array{
     *     link_name: string,
     *     sample_parent_node_id: int,
     *     sample_child_node_id: int,
     *     size: int,
     *     sample_child_node_ids: list<int>,
     *     seen_children: array<int, true>,
     *     string_counts: array<string, int>,
     *     object_samples: list<string>,
     *     sample_location_type: ?string
     * } $group
     * @param array{
     *     size: int,
     *     location_type: ?string,
     *     class_name: ?string,
     *     string_value_id: ?int
     * } $meta
     */
    private static function accumulateDedupGroup(
        array &$group,
        int $child_node_id,
        array $meta,
        StringDict $dict,
    ): void {
        if (isset($group['seen_children'][$child_node_id])) {
            return;
        }
        $group['seen_children'][$child_node_id] = true;

        if (count($group['sample_child_node_ids']) < 20) {
            $group['sample_child_node_ids'][] = $child_node_id;
        }

        $string_value_id = $meta['string_value_id'];
        if ($string_value_id !== null) {
            $value = $dict->lookup($string_value_id);
            if ($value !== null) {
                $group['string_counts'][$value] = ($group['string_counts'][$value] ?? 0) + 1;
            }
            return;
        }

        if (count($group['object_samples']) >= 3) {
            return;
        }

        $label = $meta['class_name']
            ?? $meta['location_type']
            ?? 'unknown';
        $sample = "{$label} ({$meta['size']}B)";
        if (!in_array($sample, $group['object_samples'], true)) {
            $group['object_samples'][] = $sample;
        }
    }

    /**
     * @param array<string, int> $string_counts
     * @param list<string> $object_samples
     * @return array<string, mixed>
     */
    private static function buildDedupExamples(
        array $string_counts,
        array $object_samples,
    ): array {
        if ($string_counts !== []) {
            arsort($string_counts);
            $values = array_keys($string_counts);
            $top_value = $values[0] ?? '';
            if (strlen($top_value) > 60) {
                $top_value = substr($top_value, 0, 57) . '...';
            }

            $samples = array_map(
                static function (string $value): string {
                    return strlen($value) > 40
                        ? substr($value, 0, 37) . '...'
                        : $value;
                },
                array_slice($values, 0, 5),
            );

            $top_count = reset($string_counts);
            return [
                'type' => 'string',
                'identical_count' => $top_count > 1 ? $top_count : 0,
                'sample_value' => $top_value,
                'samples' => $samples,
                'distinct_values' => count($string_counts),
            ];
        }

        return [
            'type' => 'object',
            'samples' => $object_samples,
            'identical_count' => 0,
        ];
    }
}
