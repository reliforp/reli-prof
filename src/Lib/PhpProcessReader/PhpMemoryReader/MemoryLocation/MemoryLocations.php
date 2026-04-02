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
     * Create a lightweight instance that stores only address → class-string
     * instead of full MemoryLocation objects. For streaming mode where
     * location data has already been emitted to the DB.
     */
    public static function createLightweight(): self
    {
        return new self(lightweight: true);
    }

    /** @var array<int, class-string<MemoryLocation>> */
    private array $class_map = [];

    /** @var array<int, int> address → size for dedup logic */
    private array $size_map = [];

    public function add(MemoryLocation $memory_location): void
    {
        if ($this->lightweight) {
            $this->addLightweight($memory_location);
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

    private function addLightweight(MemoryLocation $memory_location): void
    {
        $address = $memory_location->address;
        if (isset($this->class_map[$address])) {
            $recorded_class = $this->class_map[$address];
            if ($recorded_class === ZendArrayTableOverheadMemoryLocation::class) {
                // Override overhead with the real location
                $this->class_map[$address] = $memory_location::class;
                $this->size_map[$address] = $memory_location->size;
                return;
            } elseif ($memory_location instanceof ZendArrayTableOverheadMemoryLocation) {
                return;
            }
            if ($memory_location->size < ($this->size_map[$address] ?? 0)) {
                return;
            }
        }
        $this->class_map[$address] = $memory_location::class;
        $this->size_map[$address] = $memory_location->size;
    }

    public function has(int $address): bool
    {
        if ($this->lightweight) {
            return isset($this->class_map[$address]);
        }
        return isset($this->memory_locations[$address]);
    }

    public function get(int $address): MemoryLocation
    {
        return $this->memory_locations[$address];
    }

    /**
     * Get the class name of the MemoryLocation at the given address.
     * Works in both normal and lightweight modes.
     *
     * @return class-string<MemoryLocation>|null
     */
    public function getClass(int $address): ?string
    {
        if ($this->lightweight) {
            return $this->class_map[$address] ?? null;
        }
        $loc = $this->memory_locations[$address] ?? null;
        return $loc !== null ? $loc::class : null;
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
}
