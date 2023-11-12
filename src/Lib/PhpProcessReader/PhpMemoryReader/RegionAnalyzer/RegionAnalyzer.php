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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer;

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MemoryLocations;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableOverheadMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendClassEntryMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendMmChunkMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendOpArrayHeaderMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\Result\RegionalMemoryLocations;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\Result\RegionAnalyzerResult;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\Result\RegionsSummary;
use Reli\Lib\Process\MemoryLocation;

final class RegionAnalyzer
{
    public function __construct(
        private MemoryLocations $chunk_memory_locations,
        private MemoryLocations $huge_memory_locations,
        private MemoryLocations $vm_stack_memory_locations,
        private MemoryLocations $compiler_arena_memory_locations,
    ) {
    }

    public function analyze(MemoryLocations $memory_locations): RegionAnalyzerResult
    {
        $heap_memory_total = 0;
        $huge_memory_total = 0;
        $heap_memory_usage = 0;
        $huge_memory_usage = 0;
        $vm_stack_memory_total = 0;
        $compiler_arena_memory_total = 0;
        $vm_stack_memory_usage = 0;
        $compiler_arena_memory_usage = 0;
        $possible_allocation_overhead_total = 0;
        $possible_array_overhead_total = 0;
        $per_class_objects = [];

        $regional_memory_locations = RegionalMemoryLocations::createDefault();

        foreach ($this->chunk_memory_locations->memory_locations as $memory_location) {
            $heap_memory_total += $memory_location->size;
        }

        foreach ($this->huge_memory_locations->memory_locations as $memory_location) {
            $huge_memory_total += $memory_location->size;
        }

        foreach ($this->vm_stack_memory_locations->memory_locations as $vm_stack_memory_location) {
            $vm_stack_memory_total += $vm_stack_memory_location->size;
            $chunk = $this->chunk_memory_locations->getContainingMemoryLocation($vm_stack_memory_location);
            if (!is_null($chunk)) {
                assert($chunk instanceof ZendMmChunkMemoryLocation);
                $overhead = $chunk->getOverhead($vm_stack_memory_location);
                if (!is_null($overhead)) {
                    $possible_allocation_overhead_total += $overhead->size;
                }
            }
        }

        foreach ($this->compiler_arena_memory_locations->memory_locations as $memory_location) {
            $compiler_arena_memory_total += $memory_location->size;
            $chunk = $this->chunk_memory_locations->getContainingMemoryLocation($memory_location);
            if (!is_null($chunk)) {
                assert($chunk instanceof ZendMmChunkMemoryLocation);
                $overhead = $chunk->getOverhead($memory_location);
                if (!is_null($overhead)) {
                    $possible_allocation_overhead_total += $overhead->size;
                }
            }
        }

        $filtered_locations = $this->filterOverlappingLocations($memory_locations);

        foreach ($filtered_locations as $memory_location) {
            $chunk = $this->chunk_memory_locations->getContainingMemoryLocation($memory_location);
            if (!is_null($chunk)) {
                if ($this->vm_stack_memory_locations->contains($memory_location)) {
                    $vm_stack_memory_usage += $memory_location->size;
                    $regional_memory_locations->locations_in_vm_stack->add($memory_location);
                } elseif ($this->compiler_arena_memory_locations->contains($memory_location)) {
                    $compiler_arena_memory_usage += $memory_location->size;
                    $regional_memory_locations->locations_in_compiler_arena->add($memory_location);
                } else {
                    $heap_memory_usage += $memory_location->size;
                    assert($chunk instanceof ZendMmChunkMemoryLocation);
                    if (!$memory_location instanceof ZendArrayTableMemoryLocation) {
                        $overhead = $chunk->getOverhead($memory_location);
                        if (!is_null($overhead)) {
                            $possible_allocation_overhead_total += $overhead->size;
                        }
                    }
                }
                $regional_memory_locations->locations_in_zend_mm_heap->add($memory_location);
                if ($memory_location instanceof ZendObjectMemoryLocation) {
                    $per_class_objects[$memory_location->class_name] ??= [
                        'count' => 0,
                        'total_size' => 0,
                    ];
                    $per_class_objects[$memory_location->class_name]['count']++;
                    $per_class_objects[$memory_location->class_name]['total_size'] += $memory_location->size;
                }
            } elseif ($this->huge_memory_locations->contains($memory_location)) {
                $huge_memory_usage += $memory_location->size;
                $regional_memory_locations->locations_in_zend_mm_heap->add($memory_location);
            } else {
                $regional_memory_locations->locations_outside_of_zend_mm_heap->add($memory_location);
            }
            if ($memory_location instanceof ZendArrayTableOverheadMemoryLocation) {
                $possible_array_overhead_total += $memory_location->size;
            }
        }

        uasort(
            $per_class_objects,
            fn (array $a, array $b) => $b['count'] <=> $a['count']
        );

        $heap_memory_usage += $possible_allocation_overhead_total;
        $heap_memory_usage += $vm_stack_memory_total;
        $heap_memory_usage += $compiler_arena_memory_total;

        $summary = new RegionsSummary(
            $heap_memory_total + $huge_memory_total,
            $heap_memory_usage + $huge_memory_usage,
            $heap_memory_total,
            $heap_memory_usage,
            $huge_memory_total,
            $huge_memory_usage,
            $vm_stack_memory_total,
            $vm_stack_memory_usage,
            $compiler_arena_memory_total,
            $compiler_arena_memory_usage,
            $possible_allocation_overhead_total,
            $possible_array_overhead_total,
        );
        return new RegionAnalyzerResult(
            $summary,
            $regional_memory_locations,
        );
    }

    /** @return array<MemoryLocation> */
    private function filterOverlappingLocations(MemoryLocations $memory_locations): array
    {
        $locations = $memory_locations->memory_locations;

        usort($locations, function (MemoryLocation $a, MemoryLocation $b) {
            return $a->address <=> $b->address;
        });

        $filtered_locations = [];
        foreach ($locations as $location) {
            $last_key = array_key_last($filtered_locations);
            if (is_null($last_key)) {
                $filtered_locations[] = $location;
                continue;
            }
            $filtered_last = $filtered_locations[$last_key];
            if (empty($filtered_locations) || $location->address >= ($filtered_last->address + $filtered_last->size)) {
                $filtered_locations[] = $location;
            } elseif (
                $filtered_last instanceof ZendClassEntryMemoryLocation
                and $location instanceof ZendArrayMemoryLocation
            ) {
                continue;
            } elseif (
                $filtered_last instanceof ZendArrayTableOverheadMemoryLocation
            ) {
                $filtered_locations[$last_key] = $location;
            } elseif (
                $filtered_last instanceof ZendObjectMemoryLocation
                and $location instanceof ZendOpArrayHeaderMemoryLocation
                and $filtered_last->class_name === \Closure::class
            ) {
                continue;
            } else {
//                var_dump([$filtered_last, $location]);
            }
        }

        return $filtered_locations;
    }
}
