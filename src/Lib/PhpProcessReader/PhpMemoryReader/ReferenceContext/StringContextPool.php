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

final class StringContextPool
{
    /** @var array<int, StringContext> */
    private array $contexts = [];

    public function getContextForLocation(ZendStringMemoryLocation $memory_location): StringContext
    {
        if (isset($this->contexts[$memory_location->address])) {
            return $this->contexts[$memory_location->address];
        }

        $context = new StringContext($memory_location);
        $this->contexts[$memory_location->address] = $context;
        return $context;
    }

    public function clear(): void
    {
        $this->contexts = [];
    }

    /**
     * Yield all entries as address => context, then clear the pool.
     * @return \Generator<int, StringContext>
     */
    public function drainWithAddresses(): \Generator
    {
        foreach ($this->contexts as $address => $context) {
            yield $address => $context;
        }
        $this->contexts = [];
    }
}
