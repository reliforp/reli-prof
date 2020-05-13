<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\Process\MemoryMap;

/**
 * Class ProcessModuleMemoryMap
 * @package PhpProfiler\Lib\Process\MemoryMap
 */
final class ProcessModuleMemoryMap
{
    /** @var ProcessMemoryArea[] */
    private array $memory_areas;

    /**
     * ProcessModuleMemoryMap constructor.
     * @param ProcessMemoryArea[] $memory_areas
     */
    public function __construct(array $memory_areas)
    {
        $this->memory_areas = $memory_areas;
    }

    public function getBegin(): int
    {
        $begin = PHP_INT_MAX;
        foreach ($this->memory_areas as $memory_area) {
            $begin = min($begin, hexdec($memory_area->begin));
        }
        return $begin;
    }

    public function getMemoryAddressFromOffset(int $offset): int
    {
        $ranges = [];
        foreach ($this->memory_areas as $memory_area) {
            $ranges[hexdec($memory_area->file_offset)] = hexdec($memory_area->begin);
        }
        ksort($ranges);
        $file_offset_decided = 0;
        foreach ($ranges as $file_offset => $memory_begin) {
            if ($file_offset <= $offset) {
                $file_offset_decided = $file_offset;
            }
        }
        return $ranges[$file_offset_decided] + ($offset - $file_offset_decided);
    }
}
