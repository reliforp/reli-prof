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

namespace Reli\Inspector\Output\MemoryOutput\Comparison;

use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Format;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Inspector\Output\MemoryOutput\Report\ReportGenerator;
use Reli\Inspector\Output\MemoryOutput\Report\ReportResult;

final class BinaryComparisonDataProvider implements ComparisonDataProvider
{
    private BinaryReader $reader;

    /** @var array<string, array{count: int, memory_usage: int}>|null */
    private ?array $type_map_cache = null;

    /** @var array<string, array{count: int, memory_usage: int}>|null */
    private ?array $class_map_cache = null;

    public function __construct(
        private string $path,
    ) {
        $this->reader = BinaryReader::open($path);
    }

    /**
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    #[\Override]
    public function loadSummaryMap(): array
    {
        if (!$this->reader->hasSection(Format::SECTION_SUMMARY)) {
            return [];
        }
        $data = $this->reader->getSectionData(Format::SECTION_SUMMARY);
        $dict = $this->reader->getStringDict();

        $offset = 0;
        $entry_count = unpack('V', $data, $offset)[1];
        $offset += 4;

        $result = [];
        for ($i = 0; $i < $entry_count; $i++) {
            $key_id = unpack('V', $data, $offset)[1];
            $offset += 4;
            $value_id = unpack('V', $data, $offset)[1];
            $offset += 4;

            $key = $dict->lookup($key_id);
            $value = $dict->lookup($value_id);
            if ($key !== null && $value !== null && is_numeric($value)) {
                $result[$key] = str_contains($value, '.')
                    ? (float)$value
                    : (int)$value;
            }
        }
        return $result;
    }

    /**
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    #[\Override]
    public function loadTypeMap(): array
    {
        if ($this->type_map_cache !== null) {
            return $this->type_map_cache;
        }
        $this->computeLocationMaps();
        return $this->type_map_cache ?? [];
    }

    /**
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    #[\Override]
    public function loadClassMap(): array
    {
        if ($this->class_map_cache !== null) {
            return $this->class_map_cache;
        }
        $this->computeLocationMaps();
        return $this->class_map_cache ?? [];
    }

    #[\Override]
    public function generateReport(bool $full_analysis, ?bool $ffi_csr): ReportResult
    {
        $generator = new ReportGenerator();
        return $generator->generateFromBinary(
            $this->path,
            $ffi_csr,
        );
    }

    /**
     * Single pass over the locations section to compute both type and
     * class breakdowns. This avoids reading the section twice.
     *
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    private function computeLocationMaps(): void
    {
        $this->type_map_cache = [];
        $this->class_map_cache = [];

        if (!$this->reader->hasSection(Format::SECTION_LOCATIONS)) {
            return;
        }

        $data = $this->reader->getSectionData(Format::SECTION_LOCATIONS);
        $count = $this->reader->getSectionElementCount(Format::SECTION_LOCATIONS);
        $dict = $this->reader->getStringDict();

        $relevant_regions = ['zend_mm_heap', 'zend_mm_huge', 'vm_stack', 'compiler_arena'];

        $offset = 0;
        for ($i = 0; $i < $count; $i++) {
            // LocationRow layout (48 bytes):
            //   node_id(4), location_type_id(4), class_id(4), address(8),
            //   size(8), string_value_id(4), refcount(4), type_info(4),
            //   region_id(4), bin_overhead(4)
            $location_type_id = unpack('V', $data, $offset + 4)[1];
            $class_id = unpack('V', $data, $offset + 8)[1];
            $size = unpack('P', $data, $offset + 20)[1];
            $region_id = unpack('V', $data, $offset + 40)[1];
            $offset += Format::LOCATION_ROW_SIZE;

            $region = $dict->lookup($region_id);
            if ($region !== null && !in_array($region, $relevant_regions, true)) {
                continue;
            }

            // Type map
            $type = $dict->lookup($location_type_id);
            if ($type !== null) {
                if (!isset($this->type_map_cache[$type])) {
                    $this->type_map_cache[$type] = ['count' => 0, 'memory_usage' => 0];
                }
                $this->type_map_cache[$type]['count']++;
                $this->type_map_cache[$type]['memory_usage'] += $size;
            }

            // Class map
            if ($class_id !== Format::NULL_STRING_ID) {
                $class_name = $dict->lookup($class_id);
                if ($class_name !== null) {
                    if (!isset($this->class_map_cache[$class_name])) {
                        $this->class_map_cache[$class_name] = ['count' => 0, 'memory_usage' => 0];
                    }
                    $this->class_map_cache[$class_name]['count']++;
                    $this->class_map_cache[$class_name]['memory_usage'] += $size;
                }
            }
        }

        uasort(
            $this->type_map_cache,
            fn (array $a, array $b): int => $b['memory_usage'] <=> $a['memory_usage'],
        );
        uasort(
            $this->class_map_cache,
            fn (array $a, array $b): int => $b['memory_usage'] <=> $a['memory_usage'],
        );
    }
}
