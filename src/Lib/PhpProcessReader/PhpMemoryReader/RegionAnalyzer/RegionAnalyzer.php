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
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\UnusedLargeRunSlackMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\UnusedVmStackMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\VmStackArenaHeaderMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableOverheadMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendClassEntryMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendMmChunkMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendOpArrayHeaderMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringSlotTailMemoryLocation;
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

    /**
     * Decide whether a heap-resident location should contribute to
     * `possible_allocation_overhead_total` via
     * {@see ZendMmChunkMemoryLocation::getOverhead}.
     *
     * `ZendArrayTable` and `ZendString` both publish a dedicated
     * "reserved-but-unused tail" location of their own
     * ({@see ZendArrayTableOverheadMemoryLocation},
     * {@see ZendStringSlotTailMemoryLocation}) that already covers
     * the slot beyond `location.size`. Calling getOverhead for those
     * types would book the same bytes a second time as generic ZendMM
     * slot-rounding overhead.
     *
     * The slot-tail placeholder itself must also be skipped:
     * `getOverhead` assumes the location's address is the start of an
     * allocation slot and computes `bin_size − location.size`. A
     * placeholder sitting at `base + len + 24` (mid-slot) does not
     * satisfy that precondition, and the result would be garbage
     * `bin_size − slot_tail.size` bytes attributed to the next slot.
     * The array-overhead case avoids the same trap by special-casing
     * `ZendArrayTableOverheadMemoryLocation` inside
     * `ZendMmChunkMemoryLocation::getOverhead`; strings don't need
     * that because the slot tail already captures
     * `bin_size − (len + 24)` exactly, with no further rounding layer
     * to compute.
     *
     * Pulled out of `analyze()` so the rule is unit-testable without
     * standing up a real chunk and so the production class
     * `ZendMmChunkMemoryLocation` can stay `final`.
     *
     * VM-stack-arena and large-run slack types are also skipped:
     *
     * - `VmStackArenaHeaderMemoryLocation` is a 32 B marker at the
     *   start of a 256 KB arena slot. `getOverhead` would compute
     *   `bin_size − 32 ≈ 256 KB` per arena (≈ 50 MB across a
     *   typical 192-arena process), wildly mis-attributing the
     *   arena's payload as slot-rounding overhead.
     * - `UnusedVmStackMemoryLocation` and
     *   `UnusedLargeRunSlackMemoryLocation` already represent
     *   allocator-side reserved-but-not-substrate-covered bytes. A
     *   second `getOverhead` pass would compute slot rounding
     *   relative to a mid-arena / mid-run address — garbage.
     */
    public static function shouldComputeGenericChunkOverhead(MemoryLocation $memory_location): bool
    {
        return !$memory_location instanceof ZendArrayTableMemoryLocation
            && !$memory_location instanceof ZendStringMemoryLocation
            && !$memory_location instanceof ZendStringSlotTailMemoryLocation
            && !$memory_location instanceof VmStackArenaHeaderMemoryLocation
            && !$memory_location instanceof UnusedVmStackMemoryLocation
            && !$memory_location instanceof UnusedLargeRunSlackMemoryLocation;
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
        $possible_string_overhead_total = 0;
        // Tracks the per-arena unused tail (UnusedVmStackMemoryLocation)
        // size so we can later move it from vm_stack_memory_total (which
        // sums full arena sizes including the slack) into the
        // overhead bucket. Same invariant as the
        // UnusedLargeRunSlackMemoryLocation routing above: heap_usage
        // ends up unchanged.
        $unused_vm_stack_total = 0;
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
                    if ($memory_location instanceof UnusedVmStackMemoryLocation) {
                        // Don't roll the per-arena unused tail into
                        // `vm_stack_memory_usage`: that metric
                        // measures substrate-tracked frame coverage
                        // within an arena, and the tail is, by
                        // construction, the bytes that coverage did
                        // not reach. The tail goes into the overhead
                        // bucket below; we still record it as a
                        // vm_stack-region location for the address
                        // bookkeeping the downstream sink relies on.
                        $unused_vm_stack_total += $memory_location->size;
                    } else {
                        $vm_stack_memory_usage += $memory_location->size;
                    }
                    $regional_memory_locations->locations_in_vm_stack->add($memory_location);
                } elseif ($this->compiler_arena_memory_locations->contains($memory_location)) {
                    $compiler_arena_memory_usage += $memory_location->size;
                    $regional_memory_locations->locations_in_compiler_arena->add($memory_location);
                } else {
                    if ($memory_location instanceof UnusedLargeRunSlackMemoryLocation) {
                        // Treat the slack as allocator overhead rather
                        // than a regular heap location: its bytes ARE
                        // the page-rounding / capacity-reserved tail
                        // the substrate could not reach. Routing them
                        // through `possible_allocation_overhead_total`
                        // (which is added back into `heap_memory_usage`
                        // below) keeps the existing math invariant
                        // — `chunk_usage` ends up identical — while
                        // surfacing the bytes under the overhead
                        // bucket instead of as an opaque type row.
                        $possible_allocation_overhead_total += $memory_location->size;
                    } else {
                        $heap_memory_usage += $memory_location->size;
                        assert($chunk instanceof ZendMmChunkMemoryLocation);
                        if (self::shouldComputeGenericChunkOverhead($memory_location)) {
                            $overhead = $chunk->getOverhead($memory_location);
                            if (!is_null($overhead)) {
                                $possible_allocation_overhead_total += $overhead->size;
                            }
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
            if ($memory_location instanceof ZendStringSlotTailMemoryLocation) {
                $possible_string_overhead_total += $memory_location->size;
            }
        }

        uasort(
            $per_class_objects,
            fn (array $a, array $b) => $b['total_size'] <=> $a['total_size']
        );

        // Move the per-arena unused tail out of `vm_stack_memory_total`
        // and into the allocation-overhead bucket. The two sides
        // cancel in the `heap_memory_usage` sum below, so the
        // chunk_usage / heap_usage totals stay identical to the
        // pre-fix numbers; only the categorisation changes — those
        // bytes ARE allocator-reserved slack on top of the live
        // frame coverage, and routing them as overhead lets the
        // summary report them alongside the LargeRunSlack case.
        $vm_stack_memory_total -= $unused_vm_stack_total;
        $possible_allocation_overhead_total += $unused_vm_stack_total;

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
            $possible_string_overhead_total,
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

        $addresses = array_map(fn (MemoryLocation $l) => $l->address, $locations);
        array_multisort($addresses, SORT_ASC, SORT_NUMERIC, $locations);

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
                $filtered_last instanceof ZendStringSlotTailMemoryLocation
            ) {
                // The reserved-capacity tail is a placeholder for "the
                // string's slot extends to here, no one should have
                // written here". opcache, in particular, copies class
                // tables and similar structures into its own SHM with
                // reserved tails trimmed, so a real location turning up
                // inside the placeholder range is the authoritative
                // claim — yield to it, mirror of the array-overhead
                // case above.
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
