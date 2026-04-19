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

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendOpArrayHeaderMemoryLocation;

final class UserFunctionDefinitionContextPool
{
    /** @var array<int, UserFunctionDefinitionContext> */
    private array $contexts = [];

    public function getContextForLocation(
        ZendOpArrayHeaderMemoryLocation $memory_location
    ): UserFunctionDefinitionContext {
        if (isset($this->contexts[$memory_location->address])) {
            return $this->contexts[$memory_location->address];
        }

        $context = new UserFunctionDefinitionContext($memory_location);
        $this->contexts[$memory_location->address] = $context;
        return $context;
    }

    public function getContextByAddress(int $address): ?UserFunctionDefinitionContext
    {
        return $this->contexts[$address] ?? null;
    }

    public function clear(): void
    {
        $this->contexts = [];
    }

    /** @return \Generator<int, UserFunctionDefinitionContext> */
    public function drainWithAddresses(): \Generator
    {
        foreach ($this->contexts as $address => $context) {
            yield $address => $context;
        }
        $this->contexts = [];
    }

    /**
     * Drain contexts that have been emitted (memo_node_id set) and
     * keep unemitted ones in the pool.
     *
     * @return \Generator<int, UserFunctionDefinitionContext>
     */
    public function drainEmittedWithAddresses(): \Generator
    {
        $remaining = [];
        foreach ($this->contexts as $address => $context) {
            if ($context->getMemoNodeId() !== null) {
                yield $address => $context;
            } else {
                $remaining[$address] = $context;
            }
        }
        $this->contexts = $remaining;
    }
}
