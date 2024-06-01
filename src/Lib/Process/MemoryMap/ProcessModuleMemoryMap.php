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

final class ProcessModuleMemoryMap implements ProcessModuleMemoryMapInterface
{
    private ?int $base_address = null;
    /** @var array<int,int>|null  */
    private ?array $sorted_offset_to_memory_map = null;

    /** @param ProcessMemoryArea[] $memory_areas */
    public function __construct(
        private array $memory_areas
    ) {
    }

    public function getBaseAddress(): int
    {
        $base_area = null;
        if (!isset($this->base_address)) {
            $base_address = PHP_INT_MAX;
            foreach ($this->memory_areas as $memory_area) {
                if ($base_address > hexdec($memory_area->begin)) {
                    $base_address = hexdec($memory_area->begin);
                    $base_area = $memory_area;
                }
            }
            $this->base_address = hexdec($base_area->begin) - hexdec($base_area->file_offset);
        }
        return $this->base_address;
    }

    public function getMemoryAddressFromOffset(int $offset): int
    {
        $ranges = $this->getSortedOffsetToMemoryAreaMap();
        $file_offset_decided = 0;
        foreach ($ranges as $file_offset => $_memory_begin) {
            if ($file_offset <= $offset) {
                $file_offset_decided = $file_offset;
            }
        }
        return $ranges[$file_offset_decided] + ($offset - $file_offset_decided);
    }

    public function isInRange(int $address): bool
    {
        foreach ($this->memory_areas as $memory_area) {
            if ($memory_area->isInRange($address)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int, int> */
    private function getSortedOffsetToMemoryAreaMap(): array
    {
        if (!isset($this->sorted_offset_to_memory_map)) {
            $ranges = [];
            foreach ($this->memory_areas as $memory_area) {
                $ranges[hexdec($memory_area->file_offset)] = hexdec($memory_area->begin);
            }
            ksort($ranges);
            $this->sorted_offset_to_memory_map = $ranges;
        }
        return $this->sorted_offset_to_memory_map;
    }

    public function getDeviceId(): string
    {
        return $this->memory_areas[0]->device_id;
    }

    public function getInodeNumber(): int
    {
        return $this->memory_areas[0]->inode_num;
    }

    public function getModuleName(): string
    {
        return $this->memory_areas[0]->name;
    }
}
