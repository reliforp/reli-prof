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

namespace Reli\Lib\Process;

class MemoryLocation
{
    public function __construct(
        public int $address,
        public int $size,
    ) {
    }

    public function contains(MemoryLocation $memory_location): bool
    {
        return $this->address <= $memory_location->address
            and
                ($memory_location->address + $memory_location->size)
                <=
                ($this->address + $this->size)
        ;
    }
}
