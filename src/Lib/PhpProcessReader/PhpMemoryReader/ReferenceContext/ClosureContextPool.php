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

final class ClosureContextPool
{
    /** @var array<int, ClosureContext> */
    private array $contexts = [];

    public function getContextForAddress(int $address): ?ClosureContext
    {
        return $this->contexts[$address] ?? null;
    }

    public function register(int $address, ClosureContext $context): void
    {
        $this->contexts[$address] = $context;
    }

    public function clear(): void
    {
        $this->contexts = [];
    }

    /** @return \Generator<int, ClosureContext> */
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
     * @return \Generator<int, ClosureContext>
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
