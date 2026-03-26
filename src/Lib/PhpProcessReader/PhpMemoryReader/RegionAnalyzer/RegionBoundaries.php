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
}
