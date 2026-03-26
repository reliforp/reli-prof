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
        // Use intval with base 16 to avoid hexdec returning float for
        // addresses with the high bit set (e.g. vsyscall 0xffffffffff600000)
        $index = [];
        foreach ($memory_areas as $i => $area) {
            $index[] = [self::hexToSignedInt($area->begin), self::hexToSignedInt($area->end), $i];
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

        // Scan backward from candidate to find all matching regions
        $start = $candidate;
        for ($i = $candidate - 1; $i >= 0; $i--) {
            if ($this->sortedIndex[$i][1] < $address) { // end < address
                break;
            }
            $start = $i;
        }

        // Collect matching regions in address order
        $result = [];
        for ($i = $start; $i < count($this->sortedIndex); $i++) {
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

    /**
     * Convert hex string to signed int without hexdec() float overflow.
     * Addresses above 0x7fffffffffffffff (e.g. vsyscall at 0xffffffffff600000)
     * become negative ints in two's complement. This is fine for comparison
     * with <=> as long as both sides use the same encoding.
     */
    private static function hexToSignedInt(string $hex): int
    {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            $hex = substr($hex, 2);
        }
        $hex = str_pad($hex, 16, '0', STR_PAD_LEFT);
        $bin = hex2bin($hex);
        if ($bin === false) {
            return 0;
        }
        /** @var array{val: int} */
        $result = unpack('Jval', $bin);
        return $result['val'];
    }
}
