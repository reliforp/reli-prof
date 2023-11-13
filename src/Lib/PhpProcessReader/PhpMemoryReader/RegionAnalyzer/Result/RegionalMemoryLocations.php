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

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MemoryLocations;

final class RegionalMemoryLocations
{
    public function __construct(
        public MemoryLocations $locations_in_zend_mm_heap,
        public MemoryLocations $locations_in_vm_stack,
        public MemoryLocations $locations_in_compiler_arena,
        public MemoryLocations $locations_outside_of_zend_mm_heap,
    ) {
    }

    public static function createDefault(): self
    {
        return new self(
            new MemoryLocations(),
            new MemoryLocations(),
            new MemoryLocations(),
            new MemoryLocations(),
        );
    }
}
