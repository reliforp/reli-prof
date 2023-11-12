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

    public function add(MemoryLocation $memory_location): void
    {
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

    public function getContainingMemoryLocation(MemoryLocation $memory_location): ?MemoryLocation
    {
        foreach ($this->memory_locations as $memory_location_in_this) {
            if ($memory_location_in_this->contains($memory_location)) {
                return $memory_location_in_this;
            }
        }
        return null;
    }

    public function getContainingMemoryLocations(MemoryLocation $memory_location): array
    {
        $result = [];
        foreach ($this->memory_locations as $memory_location_in_this) {
            if ($memory_location_in_this->contains($memory_location)) {
                $result[] = $memory_location_in_this;
            }
        }
        return $result;
    }
}
