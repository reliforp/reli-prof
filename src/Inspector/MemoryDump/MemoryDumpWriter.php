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

namespace Reli\Inspector\MemoryDump;

use Reli\Lib\Process\MemoryMap\ProcessMemoryArea;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;

final class MemoryDumpWriter
{
    private const MAGIC = "RELIMEM\0";
    private const FORMAT_VERSION = 1;

    /**
     * @param array<array{address: int, size: int, data: string}> $regions
     * @param ProcessMemoryArea[] $memory_areas
     */
    public function write(
        string $output_path,
        int $pid,
        string $php_version,
        int $eg_address,
        int $cg_address,
        array $memory_areas,
        array $regions,
    ): void {
        $fp = fopen($output_path, 'wb');
        if ($fp === false) {
            throw new \RuntimeException("failed to open file for writing: {$output_path}");
        }
        try {
            $this->writeHeader(
                $fp,
                $pid,
                $php_version,
                $eg_address,
                $cg_address,
                count($memory_areas),
                count($regions),
            );
            $this->writeMemoryMap($fp, $memory_areas);
            $this->writeRegions($fp, $regions);
        } finally {
            fclose($fp);
        }
    }

    /** @param resource $fp */
    private function writeHeader(
        $fp,
        int $pid,
        string $php_version,
        int $eg_address,
        int $cg_address,
        int $memory_map_count,
        int $region_count,
    ): void {
        fwrite($fp, self::MAGIC);
        fwrite($fp, pack('V', self::FORMAT_VERSION));
        $this->writeString($fp, $php_version);
        fwrite($fp, pack('P', $pid));
        fwrite($fp, pack('P', $eg_address));
        fwrite($fp, pack('P', $cg_address));
        fwrite($fp, pack('V', $memory_map_count));
        fwrite($fp, pack('V', $region_count));
    }

    /**
     * @param resource $fp
     * @param ProcessMemoryArea[] $memory_areas
     */
    private function writeMemoryMap($fp, array $memory_areas): void
    {
        foreach ($memory_areas as $area) {
            $this->writeString($fp, $area->begin);
            $this->writeString($fp, $area->end);
            $this->writeString($fp, $area->file_offset);
            fwrite($fp, pack(
                'CCCC',
                $area->attribute->read ? 1 : 0,
                $area->attribute->write ? 1 : 0,
                $area->attribute->execute ? 1 : 0,
                $area->attribute->protected ? 1 : 0,
            ));
            $this->writeString($fp, $area->device_id);
            fwrite($fp, pack('P', $area->inode_num));
            $this->writeString($fp, $area->name);
        }
    }

    /**
     * @param resource $fp
     * @param array<array{address: int, size: int, data: string}> $regions
     */
    private function writeRegions($fp, array $regions): void
    {
        foreach ($regions as $region) {
            fwrite($fp, pack('P', $region['address']));
            fwrite($fp, pack('P', $region['size']));
            fwrite($fp, $region['data']);
        }
    }

    /** @param resource $fp */
    private function writeString($fp, string $value): void
    {
        fwrite($fp, pack('V', strlen($value)));
        if (strlen($value) > 0) {
            fwrite($fp, $value);
        }
    }
}
