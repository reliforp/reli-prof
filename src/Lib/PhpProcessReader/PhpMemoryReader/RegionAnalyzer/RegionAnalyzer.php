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
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendMmChunkMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringSlotTailMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\Result\RegionAnalyzerResult;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\Result\RegionsSummary;
use Reli\Lib\Process\MemoryLocation;

/**
 * Computes per-region totals (chunk / huge / vm_stack / compiler_arena)
 * from the chunk-level location collections held by
 * {@see \Reli\Lib\PhpProcessReader\PhpMemoryReader\CollectedMemories}.
 *
 * **Scope:** totals only. Per-location categorisation (which row sits
 * in which region, per-class object counts, per-row allocation
 * overhead) is the streaming sink's job — see
 * {@see RegionBoundaries::classifyRegion} for the inline region
 * assignment, {@see RegionBoundaries::computeBinOverhead} for the
 * per-row overhead column, and the `class_objects_summary` table for
 * per-class aggregates. The analyzer used to do all of that itself, but
 * the loop body sat on `$memory_locations->memory_locations` which is
 * empty under `MemoryLocations::createLightweight()` — the mode the
 * production collector uses — so in practice nothing downstream ever
 * read those results. Keeping the loop around only confused the
 * mental model.
 *
 * What's left here is what genuinely runs against the chunk-level
 * collections (which are still populated even in lightweight mode):
 * the four total sums and the `possible_allocation_overhead_total`
 * contribution from VM-stack-arena and compiler-arena slot rounding
 * via {@see ZendMmChunkMemoryLocation::getOverhead}.
 */
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
     * Consumed by {@see RegionBoundaries::computeBinOverhead}, which
     * fills the per-row `bin_overhead` column at sink emit time. The
     * predicate stays on `RegionAnalyzer` as a static method so the
     * skip rule is unit-testable without standing up a real chunk and
     * so the production class `ZendMmChunkMemoryLocation` can stay
     * `final`.
     *
     * Skip rationale per type:
     *
     * - `ZendArrayTableMemoryLocation` (used part) and
     *   `ZendStringMemoryLocation` both publish a dedicated
     *   "reserved-but-unused tail" row of their own
     *   ({@see ZendArrayTableOverheadMemoryLocation},
     *   {@see ZendStringSlotTailMemoryLocation}) that already covers
     *   the bytes beyond `location.size`. Calling `getOverhead` on the
     *   used row would book the same bytes a second time as generic
     *   ZendMM slot-rounding overhead.
     * - `ZendStringSlotTailMemoryLocation` itself sits mid-slot at
     *   `base + len + 24`, which violates `getOverhead`'s "address is
     *   the start of an allocation slot" precondition; the result
     *   would be a garbage `bin_size − slot_tail.size` value
     *   attributed to the next slot. (The array-overhead case avoids
     *   the same trap by special-casing
     *   `ZendArrayTableOverheadMemoryLocation` inside `getOverhead`;
     *   strings don't need that because the slot tail already
     *   captures `bin_size − (len + 24)` exactly, with no further
     *   rounding layer to compute.)
     * - `VmStackArenaHeaderMemoryLocation` is a 32 B marker at the
     *   start of a 256 KB arena slot. `getOverhead` would compute
     *   `bin_size − 32 ≈ 256 KB` per arena (≈ 50 MB across a
     *   typical 192-arena process), wildly mis-attributing the
     *   arena's payload as slot-rounding overhead.
     * - `UnusedVmStackMemoryLocation` and
     *   `UnusedLargeRunSlackMemoryLocation` already represent
     *   allocator-side reserved-but-not-substrate-covered bytes
     *   ("the row IS the slack"). A second `getOverhead` pass would
     *   compute slot rounding relative to a mid-arena / mid-run
     *   address — garbage.
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

    public function analyze(): RegionAnalyzerResult
    {
        $heap_memory_total = 0;
        $huge_memory_total = 0;
        $vm_stack_memory_total = 0;
        $compiler_arena_memory_total = 0;
        $possible_allocation_overhead_total = 0;

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

        // `*_usage` fields are intentionally left at 0 / pre-overhead
        // sums here: in production the dump readers always call
        // `RegionsSummary::correctedToArray($region_sums)`, which
        // recomputes `zend_mm_chunk_usage` from the streaming sink's
        // SUM(size) GROUP BY region and overrides the analyzer-side
        // values entirely. The pre-overhead sum is only the fallback
        // shape returned by `toArray()` when `region_sums === []` (a
        // dump with no substrate rows).
        $heap_memory_usage = $possible_allocation_overhead_total
            + $vm_stack_memory_total
            + $compiler_arena_memory_total;

        $summary = new RegionsSummary(
            zend_mm_heap_total: $heap_memory_total + $huge_memory_total,
            zend_mm_heap_usage: $heap_memory_usage,
            zend_mm_chunk_total: $heap_memory_total,
            zend_mm_chunk_usage: $heap_memory_usage,
            zend_mm_huge_total: $huge_memory_total,
            zend_mm_huge_usage: 0,
            vm_stack_total: $vm_stack_memory_total,
            vm_stack_usage: 0,
            compiler_arena_total: $compiler_arena_memory_total,
            compiler_arena_usage: 0,
            possible_allocation_overhead_total: $possible_allocation_overhead_total,
            possible_array_overhead_total: 0,
            possible_string_overhead_total: 0,
        );
        return new RegionAnalyzerResult($summary);
    }
}
