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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation;

use Reli\Lib\Process\MemoryLocation;

final class MemoryLocations
{
    /** @param array<MemoryLocation> $memory_locations */
    public function __construct(
        public array $memory_locations = [],
        private bool $lightweight = false,
    ) {
    }

    /**
     * Create a lightweight instance that stores only a set of seen
     * addresses instead of full MemoryLocation objects.
     * For streaming mode where location data has already been emitted to the DB.
     */
    public static function createLightweight(): self
    {
        return new self(lightweight: true);
    }

    /** @var array<int, true> seen addresses for lightweight mode */
    private array $seen = [];

    /** @var list<MemoryLocation>|null sorted index for binary search (lazily built) */
    private ?array $sorted_index = null;

    public function add(MemoryLocation $memory_location): void
    {
        $this->sorted_index = null;
        if ($this->lightweight) {
            $this->seen[$memory_location->address] = true;
            return;
        }
        if ($this->has($memory_location->address)) {
            $recorded_memory_location = $this->get($memory_location->address);
            if ($recorded_memory_location instanceof ZendArrayTableOverheadMemoryLocation) {
                $this->memory_locations[$memory_location->address] = $memory_location;
                return;
            } elseif ($memory_location instanceof ZendArrayTableOverheadMemoryLocation) {
                return;
            }
            if ($recorded_memory_location instanceof ZendStringReservedCapacityMemoryLocation) {
                // Reserved-capacity placeholder yields to a concrete
                // location landing at the same address (e.g. opcache
                // trimming a string's reserved tail and writing a real
                // entry there). Mirror of the array-overhead branch
                // above.
                $this->memory_locations[$memory_location->address] = $memory_location;
                return;
            } elseif ($memory_location instanceof ZendStringReservedCapacityMemoryLocation) {
                return;
            }
            if ($memory_location != $recorded_memory_location) {
                if ($memory_location->size < $this->get($memory_location->address)->size) {
                    return;
                }
            }
        }
        $this->memory_locations[$memory_location->address] = $memory_location;
    }

    /**
     * Register an additional address as an alias for an existing location.
     * In lightweight mode this records the address in the seen set;
     * in normal mode it stores the full MemoryLocation under the alias.
     */
    public function addAlias(int $address, MemoryLocation $memory_location): void
    {
        $this->sorted_index = null;
        if ($this->lightweight) {
            $this->seen[$address] = true;
            return;
        }
        $this->memory_locations[$address] = $memory_location;
    }

    public function has(int $address): bool
    {
        if ($this->lightweight) {
            return isset($this->seen[$address]);
        }
        return isset($this->memory_locations[$address]);
    }

    public function get(int $address): MemoryLocation
    {
        return $this->memory_locations[$address];
    }

    public function contains(MemoryLocation $memory_location): bool
    {
        return !is_null($this->getContainingMemoryLocation($memory_location));
    }

    public function getContainingMemoryLocation(MemoryLocation $memory_location): ?MemoryLocation
    {
        if ($this->sorted_index === null) {
            $this->buildSortedIndex();
        }
        assert($this->sorted_index !== null);

        $index = $this->sorted_index;
        $count = count($index);
        if ($count === 0) {
            return null;
        }

        $target = $memory_location->address;

        // Binary search: find the rightmost location with address <= target
        $lo = 0;
        $hi = $count - 1;
        $result = -1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($index[$mid]->address <= $target) {
                $result = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        if ($result === -1) {
            return null;
        }

        // All entries 0..$result have address <= target.
        // Any of them could contain the query if they are large enough,
        // so scan all candidates (safe for overlapping regions).
        for ($i = $result; $i >= 0; $i--) {
            if ($index[$i]->contains($memory_location)) {
                return $index[$i];
            }
        }

        return null;
    }

    private function buildSortedIndex(): void
    {
        $this->sorted_index = array_values($this->memory_locations);
        usort(
            $this->sorted_index,
            static fn (MemoryLocation $a, MemoryLocation $b) => $a->address <=> $b->address,
        );
    }

    public function getTotalSize(): int
    {
        $total = 0;
        foreach ($this->memory_locations as $location) {
            $total += $location->size;
        }
        return $total;
    }
}
