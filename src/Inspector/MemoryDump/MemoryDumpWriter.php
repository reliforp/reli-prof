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
    private const MAGIC = "RDUMP\0\0\0";
    private const FORMAT_VERSION = 3;

    /**
     * @param array<array{address: int, size: int, data: string}> $regions
     * @param ProcessMemoryArea[] $memory_areas
     * @param int|null $rss_bytes RSS in bytes captured at dump time, or
     *     null when /proc/<pid>/statm was unreadable.
     * @param array<string, int> $module_globals Per-module globals
     *     addresses resolved at dump time. Pre-seeded with
     *     `basic_globals` so `EmitModulesJob` can walk the
     *     shutdown-function table offline. New module-globals walkers
     *     add their key without a further format bump.
     */
    public function write(
        string $output_path,
        int $pid,
        string $php_version,
        int $eg_address,
        int $cg_address,
        array $memory_areas,
        array $regions,
        ?int $rss_bytes = null,
        array $module_globals = [],
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
            $rss_bytes,
            $module_globals,
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
     * @param int|null $rss_bytes RSS in bytes captured at dump time, or
     *     null when /proc/<pid>/statm was unreadable.
     * @param array<string, int> $module_globals see write()
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
        ?int $rss_bytes = null,
        array $module_globals = [],
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
                $rss_bytes,
                $module_globals,
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
     * @param array<string, int> $module_globals
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
        ?int $rss_bytes,
        array $module_globals,
    ): int {
        $buf = self::MAGIC;
        $buf .= pack('V', self::FORMAT_VERSION);
        $buf .= self::packString($php_version);
        $buf .= pack('P', $pid);
        $buf .= pack('P', $eg_address);
        $buf .= pack('P', $cg_address);
        // v2: rss_bytes (int64, signed). -1 = unavailable.
        $buf .= pack('q', $rss_bytes ?? -1);
        // v3: module-globals map. Extensible string→address table so
        // new walkers (basic_globals first, future curl/mysqlnd/...
        // resource registries next) land without another format bump.
        // EmitModulesJob short-circuits on a null bg_address, so an
        // empty map degrades gracefully to "shutdown-function walk
        // skipped" — same observable behaviour as v1/v2 dumps.
        $buf .= pack('V', count($module_globals));
        foreach ($module_globals as $key => $address) {
            $buf .= self::packString($key);
            $buf .= pack('P', $address);
        }
        $buf .= pack('V', $memory_map_count);
        $region_count_offset = strlen($buf);
        $buf .= pack('V', $region_count);
        fwrite($fp, $buf);
        return $region_count_offset;
    }

    /**
     * @param resource $fp
     * @param ProcessMemoryArea[] $memory_areas
     */
    private function writeMemoryMap($fp, array $memory_areas): void
    {
        $buf = '';
        foreach ($memory_areas as $area) {
            $buf .= self::packString($area->begin);
            $buf .= self::packString($area->end);
            $buf .= self::packString($area->file_offset);
            $buf .= pack(
                'CCCC',
                $area->attribute->read ? 1 : 0,
                $area->attribute->write ? 1 : 0,
                $area->attribute->execute ? 1 : 0,
                $area->attribute->protected ? 1 : 0,
            );
            $buf .= self::packString($area->device_id);
            $buf .= pack('P', $area->inode_num);
            $buf .= self::packString($area->name);
        }
        fwrite($fp, $buf);
    }

    private static function packString(string $value): string
    {
        return pack('V', strlen($value)) . $value;
    }
}
