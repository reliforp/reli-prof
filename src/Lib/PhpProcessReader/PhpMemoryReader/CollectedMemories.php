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

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MemoryLocations;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\TopReferenceContext;

class CollectedMemories
{
    public function __construct(
        public MemoryLocations $chunk_memory_locations,
        public MemoryLocations $huge_memory_locations,
        public MemoryLocations $vm_stack_memory_locations,
        public MemoryLocations $compiler_arena_memory_locations,
        public int $cached_chunks_size,
        public MemoryLocations $memory_locations,
        public TopReferenceContext $top_reference_context,
        public int $memory_get_usage_size,
        public int $memory_get_usage_real_size,
    ) {
    }
}
