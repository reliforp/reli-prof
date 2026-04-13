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
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\StringDict;

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
        usort($candidates, fn ($a, $b) => $b['size'] <=> $a['size']);
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
        usort($results, fn ($a, $b) => $b['ref_count'] <=> $a['ref_count']);
        return array_slice($results, 0, $limit);
    }

    /**
     * Load frame labels (function_name:lineno) from the binary attributes section.
     *
     * Replaces NodeLabeler's SQL query on context_node_attributes.
     *
     * @return array<int, string> node_id => "function_name:lineno"
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
