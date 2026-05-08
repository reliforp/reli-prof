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
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendMmChunkMemoryLocation;
use Reli\Lib\Process\MemoryLocation;

final class RegionBoundaries
{
    public function __construct(
        public readonly MemoryLocations $chunk_memory_locations,
        public readonly MemoryLocations $huge_memory_locations,
        public readonly MemoryLocations $vm_stack_memory_locations,
        public readonly MemoryLocations $compiler_arena_memory_locations,
    ) {
    }

    public function classifyRegion(MemoryLocation $location): string
    {
        if ($this->chunk_memory_locations->contains($location)) {
            if ($this->vm_stack_memory_locations->contains($location)) {
                return 'vm_stack';
            }
            if ($this->compiler_arena_memory_locations->contains($location)) {
                return 'compiler_arena';
            }
            return 'zend_mm_heap';
        }
        if ($this->huge_memory_locations->contains($location)) {
            return 'zend_mm_huge';
        }
        return 'outside';
    }

    /**
     * Compute ZendMM bin alignment overhead for a location.
     * Returns the overhead in bytes, or 0 if not applicable.
     *
     * Types whose slot tail is owned by a sibling location must
     * return 0 here, otherwise the bytes that already live in the
     * sibling's `size` row would be booked a second time as
     * generic ZendMM slot-rounding overhead in the sink's
     * `bin_overhead` column.
     *
     * - `ZendArrayTableMemoryLocation` (used part) and
     *   `ZendArrayTableOverheadMemoryLocation` (unused tail) share
     *   one emalloc: the overhead row carries the rounding so the
     *   used row returns 0.
     * - `ZendStringMemoryLocation` and
     *   `ZendStringSlotTailMemoryLocation` are the same shape: the
     *   slot tail row owns the bytes between `len + 24` and the
     *   bin slot end, so the string row contributes 0 overhead.
     *   The slot-tail row itself also returns 0 because its
     *   address sits mid-slot — calling
     *   `ZendMmChunkMemoryLocation::getOverhead` on it would
     *   produce a garbage `bin_size − slot_tail.size` value
     *   attributed to the *next* slot (this is the same trap
     *   `RegionAnalyzer::shouldComputeGenericChunkOverhead`
     *   already filters out for the offline path; mirroring it
     *   here keeps the live path's `allocation_overhead` aligned).
     *
     * The set of skipped types is centralised in
     * {@see RegionAnalyzer::shouldComputeGenericChunkOverhead}.
     */
    public function computeBinOverhead(MemoryLocation $location): int
    {
        if (!RegionAnalyzer::shouldComputeGenericChunkOverhead($location)) {
            return 0;
        }

        $chunk = $this->chunk_memory_locations->getContainingMemoryLocation($location);
        if ($chunk instanceof ZendMmChunkMemoryLocation) {
            $overhead = $chunk->getOverhead($location);
            if ($overhead !== null) {
                return $overhead->size;
            }
        }
        return 0;
    }

    /**
     * Back-fill the region column for rows that were inserted with NULL
     * (because RegionBoundaries was not yet available during streaming).
     */
    public function backfillRegions(\PDO $db, int $run_id): void
    {
        $select = $db->prepare(
            'SELECT id, address, size FROM context_node_locations'
            . ' WHERE run_id = ? AND region IS NULL'
        );
        $select->execute([$run_id]);

        $update = $db->prepare(
            'UPDATE context_node_locations SET region = ? WHERE id = ?'
        );

        /** @psalm-suppress MixedAssignment */
        while ($row = $select->fetch(\PDO::FETCH_ASSOC)) {
            /** @psalm-suppress MixedArrayAccess */
            $location = new MemoryLocation((int)$row['address'], (int)$row['size']);
            $region = $this->classifyRegion($location);
            /** @psalm-suppress MixedArrayAccess */
            $update->execute([$region, $row['id']]);
        }
    }
}
