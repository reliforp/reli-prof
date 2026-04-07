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

    public function add(MemoryLocation $memory_location): void
    {
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
        foreach ($this->memory_locations as $memory_location_in_this) {
            if ($memory_location_in_this->contains($memory_location)) {
                return $memory_location_in_this;
            }
        }
        return null;
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
