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

namespace Reli\Lib\Process\MemoryMap;

final class ProcessMemoryMap
{
    /** @var ProcessMemoryArea[] */
    private array $memory_areas;

    /** @var array{int, int, int}[] sorted by begin: [begin, end, index into memory_areas] */
    private array $sortedIndex;

    /** @param ProcessMemoryArea[] $memory_areas */
    public function __construct(
        array $memory_areas,
    ) {
        $this->memory_areas = $memory_areas;

        // Build sorted index for binary search
        $index = [];
        foreach ($memory_areas as $i => $area) {
            $index[] = [hexdec($area->begin), hexdec($area->end), $i];
        }
        /** @var array{int, int, int}[] $index */
        usort($index, fn(array $a, array $b) => $a[0] <=> $b[0]);
        $this->sortedIndex = $index;
    }

    /** @return ProcessMemoryArea[] */
    public function findByNameRegex(string $regex): array
    {
        $result = [];
        foreach ($this->memory_areas as $memory_area) {
            if (preg_match('{' . $regex . '}', $memory_area->name)) {
                $result[] = $memory_area;
            }
        }
        return $result;
    }

    /** @return ProcessMemoryArea[] */
    public function findByAddress(int $address): array
    {
        // Binary search for the first region whose begin <= address
        $lo = 0;
        $hi = count($this->sortedIndex) - 1;
        $candidate = -1;

        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($this->sortedIndex[$mid][0] <= $address) {
                $candidate = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        if ($candidate < 0) {
            return [];
        }

        // Check candidate and nearby entries (regions can overlap)
        $result = [];
        for ($i = $candidate; $i >= 0; $i--) {
            [$begin, $end, $idx] = $this->sortedIndex[$i];
            if ($begin > $address) {
                continue;
            }
            if ($address > $end) {
                break;
            }
            $result[] = $this->memory_areas[$idx];
        }
        // Also check entries after candidate (overlapping regions)
        for ($i = $candidate + 1; $i < count($this->sortedIndex); $i++) {
            [$begin, $end, $idx] = $this->sortedIndex[$i];
            if ($begin > $address) {
                break;
            }
            if ($address <= $end) {
                $result[] = $this->memory_areas[$idx];
            }
        }

        return $result;
    }
}
