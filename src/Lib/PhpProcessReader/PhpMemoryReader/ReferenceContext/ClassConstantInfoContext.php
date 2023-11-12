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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext;

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendClassConstantMemoryLocation;

class ClassConstantInfoContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public function __construct(
        public ZendClassConstantMemoryLocation $memory_location,
    ) {
    }

    public function getLocations(): array
    {
        return [$this->memory_location];
    }
}
