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

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendOpArrayBodyMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendOpArrayHeaderMemoryLocation;

class OpArrayContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public function __construct(
        public ZendOpArrayHeaderMemoryLocation $header_memory_location,
        public ZendOpArrayBodyMemoryLocation $body_memory_location,
    ) {
    }

    public function getLocations(): iterable
    {
        return [$this->header_memory_location, $this->body_memory_location];
    }
}
