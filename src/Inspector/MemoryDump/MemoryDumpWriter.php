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
        // Delegate to the streaming path so there is a single code path.
        $this->writeStreaming(
            $output_path,
            $pid,
            $php_version,
            $eg_address,
            $cg_address,
            $memory_areas,
            count($regions),
            (static function () use ($regions) {
                foreach ($regions as $region) {
                    yield $region;
                }
            })(),
        );
    }

    /**
     * Streaming writer. The caller passes an iterable of regions that is
     * consumed lazily so each region can be read from the target process
     * and immediately flushed to disk without accumulating the whole dump
     * in PHP memory.
     *
     * `$estimated_region_count` is written up-front in the header; if the
     * iterable yields fewer regions (e.g. a remote read failed), we seek
     * back and patch the count before closing the file so the format
     * stays valid.
     *
     * @param ProcessMemoryArea[] $memory_areas
     * @param iterable<array{address: int, size: int, data: string}> $regions
     * @return array{region_count: int, total_bytes: int}
     */
    public function writeStreaming(
        string $output_path,
        int $pid,
        string $php_version,
        int $eg_address,
        int $cg_address,
        array $memory_areas,
        int $estimated_region_count,
        iterable $regions,
    ): array {
        $fp = fopen($output_path, 'wb');
        if ($fp === false) {
            throw new \RuntimeException("failed to open file for writing: {$output_path}");
        }
        try {
            $region_count_offset = $this->writeHeader(
                $fp,
                $pid,
                $php_version,
                $eg_address,
                $cg_address,
                count($memory_areas),
                $estimated_region_count,
            );
            $this->writeMemoryMap($fp, $memory_areas);

            $written_count = 0;
            $total_bytes = 0;
            foreach ($regions as $region) {
                fwrite($fp, pack('P', $region['address']));
                fwrite($fp, pack('P', $region['size']));
                fwrite($fp, $region['data']);
                $written_count++;
                $total_bytes += $region['size'];
            }

            if ($written_count !== $estimated_region_count) {
                // Patch the region_count field so the format stays valid
                // even when some streamed reads were skipped.
                if (fseek($fp, $region_count_offset) !== 0) {
                    throw new \RuntimeException(
                        'failed to seek back to fix up region_count',
                    );
                }
                fwrite($fp, pack('V', $written_count));
            }

            return [
                'region_count' => $written_count,
                'total_bytes' => $total_bytes,
            ];
        } finally {
            fclose($fp);
        }
    }

    /**
     * @param resource $fp
     * @return int file offset of the region_count field, for later fix-up.
     */
    private function writeHeader(
        $fp,
        int $pid,
        string $php_version,
        int $eg_address,
        int $cg_address,
        int $memory_map_count,
        int $region_count,
    ): int {
        fwrite($fp, self::MAGIC);
        fwrite($fp, pack('V', self::FORMAT_VERSION));
        $this->writeString($fp, $php_version);
        fwrite($fp, pack('P', $pid));
        fwrite($fp, pack('P', $eg_address));
        fwrite($fp, pack('P', $cg_address));
        fwrite($fp, pack('V', $memory_map_count));
        $region_count_offset = ftell($fp);
        if ($region_count_offset === false) {
            throw new \RuntimeException('failed to ftell for region_count offset');
        }
        fwrite($fp, pack('V', $region_count));
        return $region_count_offset;
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

    /** @param resource $fp */
    private function writeString($fp, string $value): void
    {
        fwrite($fp, pack('V', strlen($value)));
        if (strlen($value) > 0) {
            fwrite($fp, $value);
        }
    }
}
