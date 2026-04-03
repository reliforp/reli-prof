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

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendResourceMemoryLocation;

final class ResourceContextPool
{
    /** @var array<int, ResourceContext> */
    private array $contexts = [];

    public function getContextForLocation(ZendResourceMemoryLocation $memory_location): ResourceContext
    {
        if (isset($this->contexts[$memory_location->address])) {
            return $this->contexts[$memory_location->address];
        }

        $context = new ResourceContext($memory_location);
        $this->contexts[$memory_location->address] = $context;
        return $context;
    }

    public function getContextByAddress(int $address): ?ResourceContext
    {
        return $this->contexts[$address] ?? null;
    }

    public function clear(): void
    {
        $this->contexts = [];
    }

    /** @return \Generator<int, ResourceContext> */
    public function drainWithAddresses(): \Generator
    {
        foreach ($this->contexts as $address => $context) {
            yield $address => $context;
        }
        $this->contexts = [];
    }

    /**
     * @param \WeakMap<ReferenceContext, int> $memo
     * @return \Generator<int, ResourceContext>
     */
    public function drainEmittedWithAddresses(\WeakMap $memo): \Generator
    {
        $remaining = [];
        foreach ($this->contexts as $address => $context) {
            if (isset($memo[$context])) {
                yield $address => $context;
            } else {
                $remaining[$address] = $context;
            }
        }
        $this->contexts = $remaining;
    }
}
