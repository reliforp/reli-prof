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

}
