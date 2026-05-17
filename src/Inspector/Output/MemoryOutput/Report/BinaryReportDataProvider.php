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

use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Format;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Inspector\Output\MemoryOutput\RegionFilter;

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
                $type = $dict->lookup($locRows[$i]->location_type_id);
                if ($type === $location_type_name) {
                    $result[$locRows[$i]->node_id] = true;
                }
            }
        } else {
            $data = $reader->getSectionData(Format::SECTION_LOCATIONS);
            for ($i = 0; $i < $count; $i++) {
                $off = $i * Format::LOCATION_ROW_SIZE;
                $node_id = unpack('V', $data, $off)[1];
                $type_id = unpack('V', $data, $off + 4)[1];
                $type = $dict->lookup($type_id);
                if ($type === $location_type_name) {
                    $result[$node_id] = true;
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

        // Apply the shared size-attribution region policy. See RegionFilter.
        /** @var list<array{node_id: int, size: int, string_value_id: int}> $candidates */
        $candidates = [];
        if ($locRows !== null) {
            for ($i = 0; $i < $count; $i++) {
                $type = $dict->lookup($locRows[$i]->location_type_id);
                if ($type !== 'ZendStringMemoryLocation') {
                    continue;
                }
                if (!RegionFilter::isRelevant($dict->lookup($locRows[$i]->region_id))) {
                    continue;
                }
                $candidates[] = [
                    'node_id' => $locRows[$i]->node_id,
                    'size' => $locRows[$i]->size,
                    'string_value_id' => $locRows[$i]->string_value_id,
                ];
            }
        } else {
            $data = $reader->getSectionData(Format::SECTION_LOCATIONS);
            for ($i = 0; $i < $count; $i++) {
                $off = $i * Format::LOCATION_ROW_SIZE;
                $type_id = unpack('V', $data, $off + 4)[1];
                $type = $dict->lookup($type_id);
                if ($type !== 'ZendStringMemoryLocation') {
                    continue;
                }
                $region_id = unpack('V', $data, $off + 40)[1];
                if (!RegionFilter::isRelevant($dict->lookup($region_id))) {
                    continue;
                }
                $candidates[] = [
                    'node_id' => unpack('V', $data, $off)[1],
                    'size' => unpack('P', $data, $off + 20)[1],
                    'string_value_id' => unpack('V', $data, $off + 28)[1],
                ];
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
                if ($edgeRows[$i]->is_tree !== 0 || $edgeRows[$i]->strength !== 0) {
                    continue; // skip tree edges and non-strong edges
                }
                $lid = $edgeRows[$i]->link_name_id;
                $parent = $edgeRows[$i]->parent_node_id;
                $child = $edgeRows[$i]->child_node_id;
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
     * Compute location_types summary from the binary locations section.
     *
     * Equivalent to PdoMemoryOutput::insertLocationTypesSummaryFromDb but
     * derived directly from the rmem locations section.
     *
     * @return array<string, array{count: int, memory_usage: int}>
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    public static function computeLocationTypesSummary(BinaryReader $reader): array
    {
        if (!$reader->hasSection(Format::SECTION_LOCATIONS)) {
            return [];
        }
        $data = $reader->getSectionData(Format::SECTION_LOCATIONS);
        $count = $reader->getSectionElementCount(Format::SECTION_LOCATIONS);
        $dict = $reader->getStringDict();

        /** @var array<string, array{count: int, memory_usage: int}> $result */
        $result = [];
        $offset = 0;
        for ($i = 0; $i < $count; $i++) {
            $location_type_id = unpack('V', $data, $offset + 4)[1];
            $size = unpack('P', $data, $offset + 20)[1];
            $region_id = unpack('V', $data, $offset + 40)[1];
            $offset += Format::LOCATION_ROW_SIZE;

            // Apply the shared size-attribution region policy. See RegionFilter.
            if (!RegionFilter::isRelevant($dict->lookup($region_id))) {
                continue;
            }

            $type = $dict->lookup($location_type_id);
            if ($type === null) {
                continue;
            }
            if (!isset($result[$type])) {
                $result[$type] = ['count' => 0, 'memory_usage' => 0];
            }
            $result[$type]['count']++;
            $result[$type]['memory_usage'] += $size;
        }

        uasort($result, fn (array $a, array $b): int => $b['memory_usage'] <=> $a['memory_usage']);
        return $result;
    }

    /**
     * Compute class_objects summary from the binary locations section.
     *
     * Equivalent to PdoMemoryOutput::insertClassObjectsSummaryFromDb but
     * derived directly from the rmem locations section.
     *
     * @return array<string, array{count: int, memory_usage: int}>
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    public static function computeClassObjectsSummary(BinaryReader $reader): array
    {
        if (!$reader->hasSection(Format::SECTION_LOCATIONS)) {
            return [];
        }
        $data = $reader->getSectionData(Format::SECTION_LOCATIONS);
        $count = $reader->getSectionElementCount(Format::SECTION_LOCATIONS);
        $dict = $reader->getStringDict();

        /** @var array<string, array{count: int, memory_usage: int}> $result */
        $result = [];
        $offset = 0;
        for ($i = 0; $i < $count; $i++) {
            $class_id = unpack('V', $data, $offset + 8)[1];
            $size = unpack('P', $data, $offset + 20)[1];
            $region_id = unpack('V', $data, $offset + 40)[1];
            $offset += Format::LOCATION_ROW_SIZE;

            if ($class_id === Format::NULL_STRING_ID) {
                continue;
            }
            // Apply the shared size-attribution region policy. See RegionFilter.
            if (!RegionFilter::isRelevant($dict->lookup($region_id))) {
                continue;
            }

            $class_name = $dict->lookup($class_id);
            if ($class_name === null) {
                continue;
            }
            if (!isset($result[$class_name])) {
                $result[$class_name] = ['count' => 0, 'memory_usage' => 0];
            }
            $result[$class_name]['count']++;
            $result[$class_name]['memory_usage'] += $size;
        }

        uasort($result, fn (array $a, array $b): int => $b['memory_usage'] <=> $a['memory_usage']);
        return $result;
    }

    /**
     * Load summary key/value pairs from the binary file's summary section.
     *
     * Mirrors the shape expected by summary-based passes (OverviewPass,
     * ChunkCacheHeuristicPass, ChunkFragmentationPass): a list of a single
     * flat associative array.
     *
     * @return array<int, array<string, mixed>>
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    public static function loadSummary(BinaryReader $reader): array
    {
        if (!$reader->hasSection(Format::SECTION_SUMMARY)) {
            return [];
        }
        $data = $reader->getSectionData(Format::SECTION_SUMMARY);
        $dict = $reader->getStringDict();

        $offset = 0;
        $entry_count = unpack('V', $data, $offset)[1];
        $offset += 4;

        $flat = [];
        for ($i = 0; $i < $entry_count; $i++) {
            $key_id = unpack('V', $data, $offset)[1];
            $offset += 4;
            $value_id = unpack('V', $data, $offset)[1];
            $offset += 4;

            $key = $dict->lookup($key_id);
            $value = $dict->lookup($value_id);
            if ($key !== null && $value !== null) {
                $flat[$key] = is_numeric($value)
                    ? (str_contains($value, '.') ? (float)$value : (int)$value)
                    : $value;
            }
        }
        return [$flat];
    }

    /**
     * Pull the capture timestamp string out of the binary runs section.
     *
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    public static function loadCapturedAt(BinaryReader $reader): ?string
    {
        if (!$reader->hasSection(Format::SECTION_RUNS)) {
            return null;
        }
        $runs_data = $reader->getSectionData(Format::SECTION_RUNS);
        // [run_count:u32] then [len:u32][string...]
        $offset = 4;
        if (strlen($runs_data) <= $offset + 4) {
            return null;
        }
        $len = unpack('V', $runs_data, $offset)[1];
        $offset += 4;
        $captured_at = substr($runs_data, $offset, $len);
        return $captured_at !== '' ? $captured_at : null;
    }

    /**
     * Build the `node_id => canonical class/method/function name` map for
     * the binary path. Mirrors NodeLabeler's SQL fetch, but reads sections
     * directly so the binary report renders the same case-preserved
     * identifiers as the SQLite report (the
     * `testBinaryAnalyzeReportMatchesSqliteReportForKeyFindings` parity
     * check requires it).
     *
     * Walks three sections:
     *  - `nodes` — restrict to ClassDefinitionContext /
     *    UserFunctionDefinitionContext / InternalFunctionDefinitionContext.
     *  - `edges` — find the `name` tree-edge from each definition node.
     *  - `locations` — pull the `string_value_id` of the name child and
     *    resolve via the string dict.
     *
     * @return array<int, string>
     * @psalm-suppress InaccessibleMethod
     * @psalm-suppress PossiblyNullPropertyFetch
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress InvalidPropertyFetch
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    public static function loadCanonicalNames(BinaryReader $reader): array
    {
        if (
            !$reader->hasSection(Format::SECTION_NODES)
            || !$reader->hasSection(Format::SECTION_EDGES)
            || !$reader->hasSection(Format::SECTION_LOCATIONS)
        ) {
            return [];
        }

        $dict = $reader->getStringDict();

        $target_types = [
            'ClassDefinitionContext' => true,
            'UserFunctionDefinitionContext' => true,
            'InternalFunctionDefinitionContext' => true,
        ];

        // Step 1: collect node_ids whose type is a class/function definition
        /** @var array<int, true> $is_def_node */
        $is_def_node = [];
        $node_count = $reader->getSectionElementCount(Format::SECTION_NODES);
        $nodeRows = $reader->castSection(Format::SECTION_NODES, 'NodeRow');
        if ($nodeRows !== null) {
            for ($i = 0; $i < $node_count; $i++) {
                $type = $dict->lookup($nodeRows[$i]->type_id);
                if ($type !== null && isset($target_types[$type])) {
                    $is_def_node[$nodeRows[$i]->node_id] = true;
                }
            }
        } else {
            $data = $reader->getSectionData(Format::SECTION_NODES);
            for ($i = 0; $i < $node_count; $i++) {
                $off = $i * Format::NODE_ROW_SIZE;
                $row = unpack('Vnode_id/Vcanonical_id/Vtype_id/Vclass_id', $data, $off);
                $type = $dict->lookup((int)$row['type_id']);
                if ($type !== null && isset($target_types[$type])) {
                    $is_def_node[(int)$row['node_id']] = true;
                }
            }
        }

        if ($is_def_node === []) {
            return [];
        }

        // Step 2: find 'name' tree-edges from definition nodes
        /** @var array<int, int> def_node_id => name_child_node_id */
        $name_child_of = [];
        $edge_count = $reader->getSectionElementCount(Format::SECTION_EDGES);
        $edgeRows = $reader->castSection(Format::SECTION_EDGES, 'EdgeRow');
        // No `is_tree` filter on the `name` edge: when a class registers
        // after its name string has already been collected by some other
        // path (autoload, opcache-loaded interned strings, etc.), the
        // resulting edge is recorded as `is_tree = 0` (back-reference to
        // an existing node). Filtering by `is_tree = 1` would drop ~half
        // of the user-defined ClassDefinitionContexts in real captures
        // while keeping the internal classes that happened to be
        // discovered first via the class table walk. Each
        // ClassDefinitionContext / *FunctionDefinitionContext has at
        // most one `name`-link edge by construction, so dropping the
        // filter doesn't introduce duplicates.
        if ($edgeRows !== null) {
            for ($i = 0; $i < $edge_count; $i++) {
                $parent = $edgeRows[$i]->parent_node_id;
                if (!isset($is_def_node[$parent])) {
                    continue;
                }
                $link = $dict->lookup($edgeRows[$i]->link_name_id);
                if ($link === 'name') {
                    $name_child_of[$parent] = $edgeRows[$i]->child_node_id;
                }
            }
        } else {
            $data = $reader->getSectionData(Format::SECTION_EDGES);
            for ($i = 0; $i < $edge_count; $i++) {
                $off = $i * Format::EDGE_ROW_SIZE;
                $row = unpack('Vparent/Vchild/Vlid/Cis_tree/Cstrength', $data, $off);
                $parent = (int)$row['parent'];
                if (!isset($is_def_node[$parent])) {
                    continue;
                }
                $link = $dict->lookup((int)$row['lid']);
                if ($link === 'name') {
                    $name_child_of[$parent] = (int)$row['child'];
                }
            }
        }

        if ($name_child_of === []) {
            return [];
        }

        // Reverse mapping for the location-pass lookup below.
        /** @var array<int, list<int>> name_child_node_id => def node ids */
        $defs_for_child = [];
        foreach ($name_child_of as $def_id => $child_id) {
            $defs_for_child[$child_id][] = $def_id;
        }

        // Step 3: resolve string_value of each name child via locations
        /** @var array<int, string> $canonical_names */
        $canonical_names = [];
        $loc_count = $reader->getSectionElementCount(Format::SECTION_LOCATIONS);
        $locRows = $reader->castSection(Format::SECTION_LOCATIONS, 'LocationRow');
        if ($locRows !== null) {
            for ($i = 0; $i < $loc_count; $i++) {
                $node_id = $locRows[$i]->node_id;
                if (!isset($defs_for_child[$node_id])) {
                    continue;
                }
                $sv_id = $locRows[$i]->string_value_id;
                if ($sv_id === Format::NULL_STRING_ID) {
                    continue;
                }
                $name = $dict->lookup($sv_id);
                if ($name === null || $name === '') {
                    continue;
                }
                foreach ($defs_for_child[$node_id] as $def_id) {
                    $canonical_names[$def_id] = $name;
                }
            }
        } else {
            $data = $reader->getSectionData(Format::SECTION_LOCATIONS);
            for ($i = 0; $i < $loc_count; $i++) {
                $off = $i * Format::LOCATION_ROW_SIZE;
                $node_id = unpack('V', $data, $off)[1];
                if (!isset($defs_for_child[$node_id])) {
                    continue;
                }
                // string_value_id offset: node_id u32 + type_id u32 +
                // class_id u32 + address u64 + size u64 = 28
                $sv_id = unpack('V', $data, $off + 28)[1];
                if ($sv_id === Format::NULL_STRING_ID) {
                    continue;
                }
                $name = $dict->lookup($sv_id);
                if ($name === null || $name === '') {
                    continue;
                }
                foreach ($defs_for_child[$node_id] as $def_id) {
                    $canonical_names[$def_id] = $name;
                }
            }
        }

        return $canonical_names;
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
}
