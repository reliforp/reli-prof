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
use Reli\Lib\Process\MemoryMap\ProcessMemoryAttribute;

/**
 * Reads a RELIMEM dump, merges overlapping regions (last-writer-wins
 * for overlapping byte ranges), and writes a new dump whose region
 * list is strictly disjoint. This lets DumpFileMemoryReader always
 * use the binary-search fast path.
 */
final class MemoryDumpNormalizer
{
    private const MAGIC = "RELIMEM\0";

    /**
     * @return array{original_count: int, merged_count: int, total_bytes: int}
     */
    public function normalize(string $input_path, string $output_path): array
    {
        $in_fp = fopen($input_path, 'rb');
        if ($in_fp === false) {
            throw new \RuntimeException("Failed to open input: {$input_path}");
        }

        try {
            $header = $this->readHeader($in_fp);
            $memory_areas = $this->readMemoryMap($in_fp, $header['memory_map_count']);

            // Read all regions with their data
            /** @var list<array{address: int, size: int, data: string}> $regions */
            $regions = [];
            for ($i = 0; $i < $header['region_count']; $i++) {
                $address = $this->readInt64($in_fp);
                $size = $this->readInt64($in_fp);
                $data = fread($in_fp, $size);
                if ($data === false || strlen($data) !== $size) {
                    throw new \RuntimeException("Failed to read region data at index {$i}");
                }
                $regions[] = ['address' => $address, 'size' => $size, 'data' => $data];
            }
        } finally {
            fclose($in_fp);
        }

        $original_count = count($regions);
        $merged = $this->mergeRegions($regions);

        // Write to a temp file first, then rename (atomic for same-file overwrite)
        $temp_path = $output_path . '.tmp.' . (string)getmypid();
        try {
            $writer = new MemoryDumpWriter();
            $writer->write(
                $temp_path,
                $header['pid'],
                $header['php_version'],
                $header['eg_address'],
                $header['cg_address'],
                $memory_areas,
                $merged,
                $header['rss_bytes'],
            );
            rename($temp_path, $output_path);
        } catch (\Throwable $e) {
            @unlink($temp_path);
            throw $e;
        }

        $total_bytes = 0;
        foreach ($merged as $region) {
            $total_bytes += $region['size'];
        }

        return [
            'original_count' => $original_count,
            'merged_count' => count($merged),
            'total_bytes' => $total_bytes,
        ];
    }

    /**
     * Merge overlapping regions. For overlapping byte ranges, later
     * regions in the original list overwrite earlier ones (last-writer-wins),
     * matching the semantics of how the dump was captured.
     *
     * @param list<array{address: int, size: int, data: string}> $regions
     * @return list<array{address: int, size: int, data: string}>
     */
    private function mergeRegions(array $regions): array
    {
        if (count($regions) <= 1) {
            return $regions;
        }

        // Sort by address
        usort($regions, static fn(array $a, array $b): int => $a['address'] <=> $b['address']);

        /** @var list<array{address: int, size: int, data: string}> $merged */
        $merged = [];
        $cur = $regions[0];

        for ($i = 1, $n = count($regions); $i < $n; $i++) {
            $next = $regions[$i];
            $cur_end = $cur['address'] + $cur['size'];

            if ($next['address'] >= $cur_end) {
                // No overlap — flush current
                $merged[] = $cur;
                $cur = $next;
                continue;
            }

            // Overlap: merge into a single region
            $next_end = $next['address'] + $next['size'];
            $merged_end = max($cur_end, $next_end);
            $merged_size = $merged_end - $cur['address'];

            // Build merged data buffer
            $buf = $cur['data'];
            if ($merged_size > strlen($buf)) {
                $buf .= str_repeat("\0", $merged_size - strlen($buf));
            }

            // Overwrite with next region's data (last-writer-wins)
            $offset = $next['address'] - $cur['address'];
            $buf = substr($buf, 0, $offset)
                . $next['data']
                . substr($buf, $offset + $next['size']);

            $cur = [
                'address' => $cur['address'],
                'size' => $merged_size,
                'data' => substr($buf, 0, $merged_size),
            ];
        }
        $merged[] = $cur;

        return $merged;
    }

    /**
     * @param resource $fp
     * @return array{pid: int, php_version: string, eg_address: int,
     *     cg_address: int, rss_bytes: int|null,
     *     memory_map_count: int, region_count: int}
     */
    private function readHeader($fp): array
    {
        $magic = fread($fp, 8);
        if ($magic !== self::MAGIC) {
            throw new \RuntimeException('Invalid dump file: bad magic');
        }
        $version = $this->readUint32($fp);
        if ($version !== 1 && $version !== 2) {
            throw new \RuntimeException("Unsupported format version: {$version}");
        }
        $php_version = $this->readString($fp);
        $pid = $this->readInt64($fp);
        $eg_address = $this->readInt64($fp);
        $cg_address = $this->readInt64($fp);
        $rss_bytes = null;
        if ($version >= 2) {
            $raw = $this->readInt64($fp);
            $rss_bytes = $raw === -1 ? null : $raw;
        }
        return [
            'php_version' => $php_version,
            'pid' => $pid,
            'eg_address' => $eg_address,
            'cg_address' => $cg_address,
            'rss_bytes' => $rss_bytes,
            'memory_map_count' => $this->readUint32($fp),
            'region_count' => $this->readUint32($fp),
        ];
    }

    /**
     * @param resource $fp
     * @return list<ProcessMemoryArea>
     */
    private function readMemoryMap($fp, int $count): array
    {
        $areas = [];
        for ($i = 0; $i < $count; $i++) {
            $begin = $this->readString($fp);
            $end = $this->readString($fp);
            $file_offset = $this->readString($fp);
            $attrs = fread($fp, 4);
            if ($attrs === false || strlen($attrs) !== 4) {
                throw new \RuntimeException('Failed to read memory area attributes');
            }
            $device_id = $this->readString($fp);
            $inode = $this->readInt64($fp);
            $name = $this->readString($fp);

            $areas[] = new ProcessMemoryArea(
                $begin,
                $end,
                $file_offset,
                new ProcessMemoryAttribute(
                    ord($attrs[0]) !== 0,
                    ord($attrs[1]) !== 0,
                    ord($attrs[2]) !== 0,
                    ord($attrs[3]) !== 0,
                ),
                $device_id,
                $inode,
                $name,
            );
        }
        return $areas;
    }

    /** @param resource $fp */
    private function readUint32($fp): int
    {
        $data = fread($fp, 4);
        if ($data === false || strlen($data) !== 4) {
            throw new \RuntimeException('Failed to read uint32');
        }
        /** @var array{val: int} */
        $result = unpack('Vval', $data);
        return $result['val'];
    }

    /** @param resource $fp */
    private function readInt64($fp): int
    {
        $data = fread($fp, 8);
        if ($data === false || strlen($data) !== 8) {
            throw new \RuntimeException('Failed to read int64');
        }
        /** @var array{val: int} */
        $result = unpack('Pval', $data);
        return $result['val'];
    }

    /** @param resource $fp */
    private function readString($fp): string
    {
        $len = $this->readUint32($fp);
        if ($len === 0) {
            return '';
        }
        $data = fread($fp, $len);
        if ($data === false || strlen($data) !== $len) {
            throw new \RuntimeException("Failed to read string of length {$len}");
        }
        return $data;
    }
}
