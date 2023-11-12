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

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectHandlersMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;

class ObjectContextPool
{
    /** @var array<int, ObjectContext> */
    private array $contexts = [];

    /** @var array<int, ObjectHandlersContext> */
    private array $handlers_contexts = [];

    public function getContextForLocation(
        ZendObjectMemoryLocation $memory_location,
    ): ObjectContext {
        if (isset($this->contexts[$memory_location->address])) {
            return $this->contexts[$memory_location->address];
        }

        $context = new ObjectContext($memory_location);
        $this->contexts[$memory_location->address] = $context;
        return $context;
    }

    public function getHandlersContextForLocation(
        ZendObjectHandlersMemoryLocation $memory_location,
    ): ObjectHandlersContext {
        if (isset($this->handlers_contexts[$memory_location->address])) {
            return $this->handlers_contexts[$memory_location->address];
        }

        $context = new ObjectHandlersContext($memory_location);
        $this->handlers_contexts[$memory_location->address] = $context;
        return $context;
    }
}
