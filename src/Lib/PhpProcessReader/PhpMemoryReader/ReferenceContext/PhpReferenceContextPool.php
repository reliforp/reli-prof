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

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendReferenceMemoryLocation;

class PhpReferenceContextPool
{
    /** @var array<int, PhpReferenceContext> */
    private array $contexts = [];

    public function getContextForLocation(ZendReferenceMemoryLocation $memory_location): PhpReferenceContext
    {
        if (isset($this->contexts[$memory_location->address])) {
            return $this->contexts[$memory_location->address];
        }

        $context = new PhpReferenceContext($memory_location);
        $this->contexts[$memory_location->address] = $context;
        return $context;
    }
}
