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
    private int $max_region_size = 0;

    /**
     * @param list<array{address: int, size: int, file_offset: int}> $region_index
     */
    public function __construct(
        private string $file_path,
        private array $region_index,
        private ProcessMemoryMap $process_memory_map,
        private MappedPathResolver $path_resolver,
    ) {
        usort(
            $this->region_index,
            static fn (array $a, array $b): int => $a['address'] <=> $b['address'],
        );
        foreach ($this->region_index as $region) {
            if ($region['size'] > $this->max_region_size) {
                $this->max_region_size = $region['size'];
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
        $region = $this->findContainingRegion($remote_address, $size);
        if ($region !== null) {
            $region_start = $region['address'];
            $offset_in_region = $remote_address - $region_start;
            $file_offset = $region['file_offset'] + $offset_in_region;

            $fp = $this->openCached($this->file_path);
            if ($fp === null) {
                throw new \RuntimeException("failed to open dump file: {$this->file_path}");
            }
            fseek($fp, $file_offset);
            $data = fread($fp, $size);
            if ($data === false || strlen($data) !== $size) {
                throw new \RuntimeException(
                    "failed to read {$size} bytes at file offset {$file_offset}"
                );
            }
            $cdata_buffer = FFIHelper::new("unsigned char[$size]");
            if (is_null($cdata_buffer)) {
                throw new \RuntimeException("failed to allocate memory");
            }
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
                    if (is_null($cdata_buffer)) {
                        throw new \RuntimeException("failed to allocate memory");
                    }
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
     * @return array{address: int, size: int, file_offset: int}|null
     */
    private function findContainingRegion(int $remote_address, int $size): ?array
    {
        $lo = 0;
        $hi = count($this->region_index) - 1;
        $candidate = -1;

        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            $region = $this->region_index[$mid];
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

        $remote_end = $remote_address + $size;
        for ($i = $candidate; $i >= 0; $i--) {
            $region = $this->region_index[$i];
            $region_start = $region['address'];
            if (($remote_address - $region_start) > $this->max_region_size) {
                break;
            }
            $region_end = $region_start + $region['size'];
            if ($remote_address >= $region_start && $remote_end <= $region_end) {
                return $region;
            }
        }

        return null;
    }
}
