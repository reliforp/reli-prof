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

use FFI\CData;
use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\File\PathResolver\MappedPathResolver;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;
use Reli\Lib\Process\MemoryReader\MemoryAddressNotInDumpException;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

/**
 * Offline `MemoryReaderInterface` backed by a RELIMEM dump file.
 *
 * Serves remote memory reads out of the dump's region index with a
 * small cache of open file descriptors. When an address is not present
 * in any captured region, falls back to reading the file-backed
 * segments via the original binaries on disk (for .rodata / .text
 * content that was not included in the dump itself).
 *
 * When neither the dump nor the binary fallback can resolve the
 * address, raises {@see MemoryAddressNotInDumpException} so callers can
 * distinguish "this dump does not cover that address" from genuine
 * infrastructure failures (broken dump file, failed FFI allocation).
 */
final class DumpFileMemoryReader implements MemoryReaderInterface
{
    /** @var array<string, resource> path => cached file handle */
    private array $fp_cache = [];
    /**
     * Disjoint regions sorted by address — used for binary search.
     * @var list<array{address: int, size: int, file_offset: int}>
     */
    private array $disjoint_regions = [];

    /**
     * Overlapping regions that were removed from the disjoint set.
     * Checked linearly only when binary search misses.
     * @var list<array{address: int, size: int, file_offset: int}>
     */
    private array $overlap_regions = [];

    /** Read-ahead buffer: cached chunk of the dump file */
    private string $read_buffer = '';
    private int $read_buffer_address = 0;
    private int $read_buffer_length = 0;

    /**
     * Per-region full cache. Populated lazily when read_buffer_size
     * is large enough to cover an entire region.
     * @var array<string, string> region cache key => region data
     */
    private array $region_cache = [];

    /**
     * @param list<array{address: int, size: int, file_offset: int}> $region_index
     * @param int $read_buffer_size Read-ahead buffer size in bytes.
     *     Larger values reduce fseek/fread calls at the cost of memory.
     *     0 disables buffering. Default 256 KiB.
     */
    public function __construct(
        private string $file_path,
        private array $region_index,
        private ProcessMemoryMap $process_memory_map,
        private MappedPathResolver $path_resolver,
        private int $read_buffer_size = 256 * 1024,
    ) {
        $sorted = $this->region_index;
        usort(
            $sorted,
            static function (array $a, array $b): int {
                // Primary: by address ascending.
                // Secondary: by size descending — when multiple regions
                // share the same start address, the largest one should
                // be picked for the disjoint set so binary search finds
                // the widest coverage.
                return $a['address'] <=> $b['address']
                    ?: $b['size'] <=> $a['size'];
            },
        );
        // Partition into disjoint (binary-searchable) and overlapping regions.
        $previous_end = 0;
        foreach ($sorted as $region) {
            if ($region['address'] < $previous_end) {
                $this->overlap_regions[] = $region;
            } else {
                $this->disjoint_regions[] = $region;
            }
            $region_end = $region['address'] + $region['size'];
            if ($region_end > $previous_end) {
                $previous_end = $region_end;
            }
        }
    }

    public function __destruct()
    {
        foreach ($this->fp_cache as $fp) {
            fclose($fp);
        }
    }

    /** @return resource|null */
    private function openCached(string $path)
    {
        if (!isset($this->fp_cache[$path])) {
            $fp = fopen($path, 'rb');
            if ($fp === false) {
                return null;
            }
            $this->fp_cache[$path] = $fp;
        }
        return $this->fp_cache[$path];
    }

    #[\Override]
    public function read(int $pid, int $remote_address, int $size): CData
    {
        $region = $this->findContainingRegionSorted($remote_address, $size)
            ?? $this->findContainingRegionOverlap($remote_address, $size);
        if ($region !== null) {
            $data = $this->readFromRegion($region, $remote_address, $size);
            $cdata_buffer = FFIHelper::new("unsigned char[$size]");
            \FFI::memcpy($cdata_buffer, $data, $size);
            /** @var \FFI\CArray<int> */
            return $cdata_buffer;
        }

        // Fallback: try to read from binary files via memory map (for
        // read-only segments that were not included in the dump).
        $memory_areas = $this->process_memory_map->findByAddress($remote_address);
        foreach ($memory_areas as $memory_area) {
            if ($memory_area->name !== '' && !$memory_area->attribute->write) {
                $resolved_path = $this->path_resolver->resolve($pid, $memory_area->name);
                if (file_exists($resolved_path)) {
                    $file_fp = $this->openCached($resolved_path);
                    if ($file_fp === null) {
                        continue;
                    }
                    $offset = $remote_address - hexdec($memory_area->begin);
                    fseek($file_fp, (int)hexdec($memory_area->file_offset) + $offset);
                    $data = fread($file_fp, $size);
                    if ($data === false) {
                        continue;
                    }
                    $cdata_buffer = FFIHelper::new("unsigned char[$size]");
                    \FFI::memcpy($cdata_buffer, $data, $size);
                    /** @var \FFI\CArray<int> */
                    return $cdata_buffer;
                }
            }
        }

        throw new MemoryAddressNotInDumpException(
            "no memory region found for address: 0x" . dechex($remote_address) . " (size: {$size})"
        );
    }

    /**
     * Read bytes from a dump region, using the read-ahead buffer when possible.
     *
     * @param array{address: int, size: int, file_offset: int} $region
     */
    private function readFromRegion(array $region, int $remote_address, int $size): string
    {
        $region_start = $region['address'];
        $region_size = $region['size'];

        // Check per-region full cache. Key includes size to distinguish
        // multiple regions sharing the same start address (e.g. a 2036K
        // process_vm_readv region and a 2048K ZendMM chunk region).
        $cache_key = $region_start . ':' . $region_size . ':' . $region['file_offset'];
        if (isset($this->region_cache[$cache_key])) {
            $offset = $remote_address - $region_start;
            return substr($this->region_cache[$cache_key], $offset, $size);
        }

        // Check read-ahead buffer hit
        if (
            $this->read_buffer_length > 0
            && $remote_address >= $this->read_buffer_address
            && ($remote_address + $size) <= ($this->read_buffer_address + $this->read_buffer_length)
        ) {
            $offset = $remote_address - $this->read_buffer_address;
            return substr($this->read_buffer, $offset, $size);
        }

        $fp = $this->openCached($this->file_path);
        if ($fp === null) {
            throw new \RuntimeException("failed to open dump file: {$this->file_path}");
        }

        // If the buffer is large enough for the whole region, cache it entirely
        if ($this->read_buffer_size > 0 && $region_size <= $this->read_buffer_size) {
            fseek($fp, $region['file_offset']);
            $region_data = fread($fp, $region_size);
            if ($region_data === false || strlen($region_data) !== $region_size) {
                throw new \RuntimeException(
                    "failed to read region of {$region_size} bytes"
                );
            }
            $this->region_cache[$cache_key] = $region_data;
            $offset = $remote_address - $region_start;
            return substr($region_data, $offset, $size);
        }

        // If buffering is enabled, read a larger chunk within the region
        if ($this->read_buffer_size > 0 && $size < $this->read_buffer_size) {
            $region_end = $region_start + $region_size;
            $buf_start = $remote_address;
            $buf_end = min($buf_start + $this->read_buffer_size, $region_end);
            $buf_size = $buf_end - $buf_start;

            $file_offset = $region['file_offset'] + ($buf_start - $region_start);
            fseek($fp, $file_offset);
            $buf_data = fread($fp, $buf_size);
            if ($buf_data === false || strlen($buf_data) !== $buf_size) {
                throw new \RuntimeException(
                    "failed to read {$buf_size} bytes at file offset {$file_offset}"
                );
            }

            $this->read_buffer = $buf_data;
            $this->read_buffer_address = $buf_start;
            $this->read_buffer_length = $buf_size;

            return substr($buf_data, 0, $size);
        }

        // No buffering: direct read
        $file_offset = $region['file_offset'] + ($remote_address - $region_start);
        fseek($fp, $file_offset);
        $data = fread($fp, $size);
        if ($data === false || strlen($data) !== $size) {
            throw new \RuntimeException(
                "failed to read {$size} bytes at file offset {$file_offset}"
            );
        }
        return $data;
    }

    /**
     * Slow path: linear scan over overlapping regions only.
     *
     * @return array{address: int, size: int, file_offset: int}|null
     */
    private function findContainingRegionOverlap(int $remote_address, int $size): ?array
    {
        $remote_end = $remote_address + $size;
        foreach ($this->overlap_regions as $region) {
            $region_start = $region['address'];
            $region_end = $region_start + $region['size'];
            if ($remote_address >= $region_start && $remote_end <= $region_end) {
                return $region;
            }
        }
        return null;
    }

    /**
     * Fast path: binary search over disjoint regions.
     *
     * @return array{address: int, size: int, file_offset: int}|null
     */
    private function findContainingRegionSorted(int $remote_address, int $size): ?array
    {
        $lo = 0;
        $hi = count($this->disjoint_regions) - 1;
        $candidate = -1;

        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            $region = $this->disjoint_regions[$mid];
            if ($region['address'] <= $remote_address) {
                $candidate = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        if ($candidate < 0) {
            return null;
        }

        $region = $this->disjoint_regions[$candidate];
        $region_start = $region['address'];
        $region_end = $region_start + $region['size'];
        $remote_end = $remote_address + $size;
        return $remote_address >= $region_start && $remote_end <= $region_end
            ? $region
            : null;
    }
}
