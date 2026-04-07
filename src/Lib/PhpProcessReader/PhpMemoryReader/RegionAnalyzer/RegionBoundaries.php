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
<<<<<<< HEAD
=======
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableOverheadMemoryLocation;
>>>>>>> origin/claude/fix-memory-analysis-2WiRL
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
<<<<<<< HEAD
     */
    public function computeBinOverhead(MemoryLocation $location): int
    {
=======
     *
     * ZendArrayTableOverheadMemoryLocation is NOT a separate allocation —
     * it shares the same emalloc as ZendArrayTableMemoryLocation. The
     * getOverhead() in ZendMmChunkMemoryLocation already handles this by
     * combining both into the full table size. So we return 0 for the
     * overhead location to avoid double-counting.
     */
    public function computeBinOverhead(MemoryLocation $location): int
    {
        // ZendArrayTable (used part) and ZendArrayTableOverhead (unused part) share
        // one emalloc. The overhead is bin_size(full_table) - full_table. We compute
        // this on the Overhead location (which has access to the used_location) and
        // return 0 for the Table location to avoid double-counting.
        if ($location instanceof ZendArrayTableMemoryLocation) {
            return 0;
        }

>>>>>>> origin/claude/fix-memory-analysis-2WiRL
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
