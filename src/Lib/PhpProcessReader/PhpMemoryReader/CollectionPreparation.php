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

use Reli\Lib\PhpInternals\Types\Zend\ZendArray;
use Reli\Lib\PhpInternals\Types\Zend\ZendExecutorGlobals;
use Reli\Lib\PhpInternals\Types\Zend\ZendCompilerGlobals;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MemoryLocations;
use Reli\Lib\Process\Pointer\Dereferencer;

/**
 * The result of collectAll()'s pre-processing phase: heap metadata,
 * dereferenced EG/CG, and region boundaries.
 *
 * This is everything that must be computed sequentially before the
 * per-branch collection can begin (potentially in parallel).
 */
final class CollectionPreparation
{
    /**
     * @param MemoryLocations $huge_list_memory_locations  ZendMmHugeListMemoryLocation entries for memory_locations
     */
    public function __construct(
        public readonly Dereferencer $dereferencer,
        public readonly ZendTypeReader $zend_type_reader,
        public readonly ZendExecutorGlobals $eg,
        public readonly ZendCompilerGlobals $cg,
        public readonly ZendArray $function_table,
        public readonly ZendArray $class_table,
        public readonly ZendArray $zend_constants,
        public readonly MemoryLocations $chunk_memory_locations,
        public readonly MemoryLocations $huge_memory_locations,
        public readonly MemoryLocations $huge_list_memory_locations,
        public readonly MemoryLocations $vm_stack_memory_locations,
        public readonly MemoryLocations $compiler_arena_memory_locations,
        public readonly int $cached_chunks_size,
        public readonly int $memory_get_usage_size,
        public readonly int $memory_get_usage_real_size,
    ) {
    }
}
