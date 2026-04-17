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
    /** @var array<string, RegionBytes> region cache key => cached region */
    private array $cache = [];
    private int $cached_bytes = 0;

    /** Last-hit cache for consecutive accesses to the same region */
    private ?RegionBytes $last_hit = null;
    private ?string $last_hit_key = null;
    private bool $has_overlaps = false;

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
        $sorted = $this->region_index;
        usort(
            $sorted,
            static function (array $a, array $b): int {
                return $a['address'] <=> $b['address']
                    ?: $b['size'] <=> $a['size'];
            },
        );

        $previous_end = 0;
        foreach ($sorted as $region) {
            if ($region['address'] < $previous_end) {
                $this->overlap_regions[] = $region;
                $this->has_overlaps = true;
            } else {
                $this->disjoint_regions[] = $region;
            }
            $region_end = $region['address'] + $region['size'];
            if ($region_end > $previous_end) {
                $previous_end = $region_end;
            }
        }
    }

    /**
     * Get the RegionBytes containing the given address, or null if
     * the address is not in any dump region.
     */
    public function regionFor(int $address, int $size = 1): ?RegionBytes
    {
        if (!$this->has_overlaps && $this->last_hit !== null && $this->last_hit->contains($address, $size)) {
            return $this->last_hit;
        }

        $entry = $this->findContainingRegionSorted($address, $size)
            ?? $this->findContainingRegionOverlap($address, $size);
        if ($entry === null) {
            return null;
        }

        $cache_key = $this->cacheKey($entry);
        if (
            $this->last_hit !== null
            && $this->last_hit_key === $cache_key
            && $this->last_hit->contains($address, $size)
        ) {
            return $this->last_hit;
        }

        if (isset($this->cache[$cache_key])) {
            $this->last_hit = $this->cache[$cache_key];
            $this->last_hit_key = $cache_key;
            return $this->cache[$cache_key];
        }

        return $this->loadRegion($entry, $cache_key);
    }

    /**
     * @param array{address: int, size: int, file_offset: int} $entry
     */
    private function cacheKey(array $entry): string
    {
        return $entry['address'] . ':' . $entry['size'] . ':' . $entry['file_offset'];
    }

    /**
     * @return array{address: int, size: int, file_offset: int}|null
     */
    private function findContainingRegionSorted(int $address, int $size): ?array
    {
        $lo = 0;
        $hi = count($this->disjoint_regions) - 1;
        $candidate = -1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($this->disjoint_regions[$mid]['address'] <= $address) {
                $candidate = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        if ($candidate < 0) {
            return null;
        }

        $entry = $this->disjoint_regions[$candidate];
        $region_end = $entry['address'] + $entry['size'];
        return $address + $size <= $region_end ? $entry : null;
    }

    /**
     * @return array{address: int, size: int, file_offset: int}|null
     */
    private function findContainingRegionOverlap(int $address, int $size): ?array
    {
        $end = $address + $size;
        foreach ($this->overlap_regions as $entry) {
            $region_start = $entry['address'];
            $region_end = $region_start + $entry['size'];
            if ($address >= $region_start && $end <= $region_end) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * @param array{address: int, size: int, file_offset: int} $entry
     */
    private function loadRegion(array $entry, string $cache_key): RegionBytes
    {
        $addr = $entry['address'];

        // Evict if needed
        if ($this->max_cache_bytes > 0) {
            while (
                $this->cached_bytes + $entry['size'] > $this->max_cache_bytes
                && $this->cache !== []
            ) {
                $evict_key = array_key_first($this->cache);
                $this->cached_bytes -= strlen($this->cache[$evict_key]->bytes);
                unset($this->cache[$evict_key]);
                if ($this->last_hit_key === $evict_key) {
                    $this->last_hit = null;
                    $this->last_hit_key = null;
                }
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
        $this->cache[$cache_key] = $region;
        $this->cached_bytes += $entry['size'];
        $this->last_hit = $region;
        $this->last_hit_key = $cache_key;

        return $region;
    }
}
