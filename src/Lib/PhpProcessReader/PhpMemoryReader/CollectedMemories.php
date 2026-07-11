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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader;

use Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\BinWalkResult;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MemoryLocations;

final class CollectedMemories
{
    /** Region map computed lazily on first access; cached afterwards. */
    private ?RegionMap $region_map = null;

    public function __construct(
        public MemoryLocations $chunk_memory_locations,
        public MemoryLocations $huge_memory_locations,
        public MemoryLocations $vm_stack_memory_locations,
        public MemoryLocations $compiler_arena_memory_locations,
        public int $cached_chunks_size,
        public MemoryLocations $memory_locations,
        public int $memory_get_usage_size,
        public int $memory_get_usage_real_size,
        public int $memory_get_peak_usage,
        public int $memory_limit,
        public int $chunks_count = 0,
        public int $peak_chunks_count = 0,
        public int $cached_chunks_count = 0,
        public int $last_chunks_delete_boundary = 0,
        public int $last_chunks_delete_count = 0,
        public int $chunks_total_free_bytes = 0,
        public int $chunks_mostly_empty_count = 0,
        public ?BinWalkResult $bin_walk_result = null,
        public int $memory_get_peak_usage_real = 0,
    ) {
    }

    public function getRegionMap(): RegionMap
    {
        if ($this->region_map === null) {
            $this->region_map = RegionMap::fromCollectedMemories($this);
        }
        return $this->region_map;
    }
}
