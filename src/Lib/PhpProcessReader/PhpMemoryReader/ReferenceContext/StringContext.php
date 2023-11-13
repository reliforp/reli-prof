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

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;

class StringContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public function __construct(
        public ZendStringMemoryLocation $memory_location,
    ) {
    }

    public function add(string $link_name, ReferenceContext $reference_context): void
    {
        throw new \LogicException("StringContext cannot have reference to another context");
    }

    public function getLocations(): iterable
    {
        return [$this->memory_location];
    }
}
