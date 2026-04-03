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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader;

use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ArrayContextPool;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ClosureContextPool;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ObjectContextPool;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\PhpReferenceContextPool;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ResourceContextPool;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\SentinelContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\StringContextPool;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\UserFunctionDefinitionContextPool;

final class ContextPools
{
    /** @var array<int, int> address → node_id for cross-branch dedup */
    private array $sentinels = [];

    private SentinelContext $shared_sentinel;

    public function __construct(
        public StringContextPool $string_context_pool,
        public ArrayContextPool $array_context_pool,
        public ObjectContextPool $object_context_pool,
        public PhpReferenceContextPool $php_reference_context_pool,
        public ResourceContextPool $resource_context_pool,
        public UserFunctionDefinitionContextPool $user_function_definition_context_pool,
        public ClosureContextPool $closure_context_pool = new ClosureContextPool(),
    ) {
        $this->shared_sentinel = new SentinelContext(0);
    }

    /**
     * Check if the given address was already emitted to DB in a previous branch.
     * Returns a shared SentinelContext with the node_id set, or null.
     * The returned object is reused across calls — do not store it.
     */
    public function getSentinel(int $address): ?SentinelContext
    {
        $node_id = $this->sentinels[$address] ?? null;
        if ($node_id === null) {
            return null;
        }
        $this->shared_sentinel->node_id = $node_id;
        return $this->shared_sentinel;
    }

    public static function createDefault(): self
    {
        return new self(
            new StringContextPool(),
            new ArrayContextPool(),
            new ObjectContextPool(),
            new PhpReferenceContextPool(),
            new ResourceContextPool(),
            new UserFunctionDefinitionContextPool(),
        );
    }

    public function clear(): void
    {
        $this->string_context_pool->clear();
        $this->array_context_pool->clear();
        $this->object_context_pool->clear();
        $this->php_reference_context_pool->clear();
        $this->resource_context_pool->clear();
        $this->user_function_definition_context_pool->clear();
        $this->closure_context_pool->clear();
    }

    /**
     * Convert all pooled contexts to lightweight sentinels and clear the pools.
     * For each pooled Context that has a node_id in memo, record a
     * SentinelContext(node_id) keyed by address. The heavy Context objects
     * become eligible for GC, while subsequent cache hits return the sentinel.
     *
     * @param \WeakMap<ReferenceContext, int> $memo
     */
    public function convertToSentinels(\WeakMap $memo): void
    {
        $this->convertPoolToSentinels($this->string_context_pool->drainWithAddresses(), $memo);
        $this->convertPoolToSentinels($this->array_context_pool->drainWithAddresses(), $memo);
        $this->convertPoolToSentinels($this->object_context_pool->drainWithAddresses(), $memo);
        $this->convertPoolToSentinels($this->php_reference_context_pool->drainWithAddresses(), $memo);
        $this->convertPoolToSentinels($this->resource_context_pool->drainWithAddresses(), $memo);
        $this->convertPoolToSentinels($this->user_function_definition_context_pool->drainWithAddresses(), $memo);
        $this->convertPoolToSentinels($this->closure_context_pool->drainWithAddresses(), $memo);
    }

    /**
     * @param iterable<int, ReferenceContext> $entries address => context
     * @param \WeakMap<ReferenceContext, int> $memo
     */
    private function convertPoolToSentinels(iterable $entries, \WeakMap $memo): void
    {
        foreach ($entries as $address => $context) {
            $node_id = $memo[$context] ?? null;
            if ($node_id !== null) {
                $this->sentinels[$address] = $node_id;
            }
        }
    }
}
