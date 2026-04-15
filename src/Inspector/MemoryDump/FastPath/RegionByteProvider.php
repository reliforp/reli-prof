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

namespace Reli\Inspector\MemoryDump\FastPath;

/**
 * Provides RegionBytes for the fast path by loading dump regions
 * into PHP strings.
 *
 * Regions are loaded lazily on first access and cached. The cache
 * is bounded by the total byte size specified at construction.
 */
final class RegionByteProvider
{
    /** @var array<int, RegionBytes> region_address => cached region */
    private array $cache = [];
    private int $cached_bytes = 0;

    /**
     * @param list<array{address: int, size: int, file_offset: int}> $region_index
     * @param resource $fp file handle for the dump file
     * @param int $max_cache_bytes maximum total bytes to cache (0 = unlimited)
     */
    public function __construct(
        private array $region_index,
        private $fp,
        private int $max_cache_bytes = 0,
    ) {
        // Sort for binary search
        usort(
            $this->region_index,
            static fn(array $a, array $b): int => $a['address'] <=> $b['address'],
        );
    }

    /**
     * Get the RegionBytes containing the given address, or null if
     * the address is not in any dump region.
     */
    public function regionFor(int $address, int $size = 1): ?RegionBytes
    {
        // Check cache first
        foreach ($this->cache as $region) {
            if ($region->contains($address, $size)) {
                return $region;
            }
        }

        // Find the containing region via binary search
        $lo = 0;
        $hi = count($this->region_index) - 1;
        $candidate = -1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($this->region_index[$mid]['address'] <= $address) {
                $candidate = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        if ($candidate < 0) {
            return null;
        }

        $entry = $this->region_index[$candidate];
        $region_end = $entry['address'] + $entry['size'];
        if ($address + $size > $region_end) {
            return null;
        }

        // Load the region
        return $this->loadRegion($entry);
    }

    /**
     * @param array{address: int, size: int, file_offset: int} $entry
     */
    private function loadRegion(array $entry): RegionBytes
    {
        $addr = $entry['address'];
        if (isset($this->cache[$addr])) {
            return $this->cache[$addr];
        }

        // Evict if needed
        if ($this->max_cache_bytes > 0) {
            while (
                $this->cached_bytes + $entry['size'] > $this->max_cache_bytes
                && $this->cache !== []
            ) {
                $evict_key = array_key_first($this->cache);
                $this->cached_bytes -= strlen($this->cache[$evict_key]->bytes);
                unset($this->cache[$evict_key]);
            }
        }

        fseek($this->fp, $entry['file_offset']);
        $data = fread($this->fp, $entry['size']);
        if ($data === false || strlen($data) !== $entry['size']) {
            throw new \RuntimeException(
                "Failed to read region at 0x" . dechex($addr)
            );
        }

        $region = new RegionBytes($addr, $data);
        $this->cache[$addr] = $region;
        $this->cached_bytes += $entry['size'];

        return $region;
    }
}
