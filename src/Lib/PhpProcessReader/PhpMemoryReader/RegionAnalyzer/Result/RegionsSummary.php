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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\Result;

final class RegionsSummary
{
    public function __construct(
        public int $zend_mm_heap_total,
        public int $zend_mm_heap_usage,
        public int $zend_mm_chunk_total,
        public int $zend_mm_chunk_usage,
        public int $zend_mm_huge_total,
        public int $zend_mm_huge_usage,
        public int $vm_stack_total,
        public int $vm_stack_usage,
        public int $compiler_arena_total,
        public int $compiler_arena_usage,
        public int $possible_allocation_overhead_total,
        public int $possible_array_overhead_total,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'zend_mm_heap_total' => $this->zend_mm_heap_total,
            'zend_mm_heap_usage' => $this->zend_mm_heap_usage,
            'zend_mm_chunk_total' => $this->zend_mm_chunk_total,
            'zend_mm_chunk_usage' => $this->zend_mm_chunk_usage,
            'zend_mm_huge_total' => $this->zend_mm_huge_total,
            'zend_mm_huge_usage' => $this->zend_mm_huge_usage,
            'vm_stack_total' => $this->vm_stack_total,
            'vm_stack_usage' => $this->vm_stack_usage,
            'compiler_arena_total' => $this->compiler_arena_total,
            'compiler_arena_usage' => $this->compiler_arena_usage,
            'possible_allocation_overhead_total' => $this->possible_allocation_overhead_total,
            'possible_array_overhead_total' => $this->possible_array_overhead_total,
        ];
    }
}
