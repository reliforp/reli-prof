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

class MemoryLocations
{
    /** @param array<MemoryLocation> $memory_locations */
    public function __construct(
        public array $memory_locations = []
    ) {
    }

    /** @var list<MemoryLocation>|null */
    private ?array $sorted_for_search = null;

    public function add(MemoryLocation $memory_location): void
    {
        if ($this->has($memory_location->address)) {
            $recorded_memory_location = $this->get($memory_location->address);
            if ($recorded_memory_location instanceof ZendArrayTableOverheadMemoryLocation) {
                $this->memory_locations[$memory_location->address] = $memory_location;
                $this->sorted_for_search = null;
                return;
            } elseif ($memory_location instanceof ZendArrayTableOverheadMemoryLocation) {
                return;
            }
            if ($memory_location != $recorded_memory_location) {
                if ($memory_location->size < $this->get($memory_location->address)->size) {
                    return;
                }
            }
        }
        $this->memory_locations[$memory_location->address] = $memory_location;
        $this->sorted_for_search = null;
    }

    public function has(int $address): bool
    {
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

    private function buildSortedIndex(): void
    {
        if ($this->sorted_for_search !== null) {
            return;
        }
        $this->sorted_for_search = array_values($this->memory_locations);
        usort(
            $this->sorted_for_search,
            fn (MemoryLocation $a, MemoryLocation $b) => $a->address <=> $b->address
        );
    }

    public function getContainingMemoryLocation(MemoryLocation $memory_location): ?MemoryLocation
    {
        $this->buildSortedIndex();
        $sorted = $this->sorted_for_search;
        $target_address = $memory_location->address;
        $target_end = $target_address + $memory_location->size;

        $lo = 0;
        $hi = count($sorted) - 1;

        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            $candidate = $sorted[$mid];
            if ($candidate->address > $target_address) {
                $hi = $mid - 1;
            } elseif ($candidate->address + $candidate->size < $target_end) {
                $lo = $mid + 1;
            } else {
                return $candidate;
            }
        }

        return null;
    }
}
