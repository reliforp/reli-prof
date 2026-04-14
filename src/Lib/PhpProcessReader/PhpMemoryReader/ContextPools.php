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
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\StringContextPool;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\UserFunctionDefinitionContextPool;

final class ContextPools
{
    public function __construct(
        public StringContextPool $string_context_pool,
        public ArrayContextPool $array_context_pool,
        public ObjectContextPool $object_context_pool,
        public PhpReferenceContextPool $php_reference_context_pool,
        public ResourceContextPool $resource_context_pool,
        public UserFunctionDefinitionContextPool $user_function_definition_context_pool,
        public ClosureContextPool $closure_context_pool = new ClosureContextPool(),
    ) {
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
     * Drain all pool entries (regardless of emit state) and record
     * their address→node_id mappings in the given map.
     *
     * @param array<int, int> $address_map
     */
    public function drainToAddressMap(array &$address_map): void
    {
        $this->drainPoolToAddressMap($this->string_context_pool->drainWithAddresses(), $address_map);
        $this->drainPoolToAddressMap($this->array_context_pool->drainWithAddresses(), $address_map);
        $this->drainPoolToAddressMap($this->object_context_pool->drainWithAddresses(), $address_map);
        $this->drainPoolToAddressMap(
            $this->php_reference_context_pool->drainWithAddresses(),
            $address_map,
        );
        $this->drainPoolToAddressMap($this->resource_context_pool->drainWithAddresses(), $address_map);
        $this->drainPoolToAddressMap(
            $this->user_function_definition_context_pool->drainWithAddresses(),
            $address_map,
        );
        $this->drainPoolToAddressMap($this->closure_context_pool->drainWithAddresses(), $address_map);
    }

    /**
     * Drain only emitted pool entries and record their address→node_id
     * mappings. Unemitted entries remain in the pools.
     *
     * @param array<int, int> $address_map
     */
    public function drainEmittedToAddressMap(array &$address_map): void
    {
        $this->drainPoolToAddressMap($this->string_context_pool->drainEmittedWithAddresses(), $address_map);
        $this->drainPoolToAddressMap($this->array_context_pool->drainEmittedWithAddresses(), $address_map);
        $this->drainPoolToAddressMap($this->object_context_pool->drainEmittedWithAddresses(), $address_map);
        $this->drainPoolToAddressMap(
            $this->php_reference_context_pool->drainEmittedWithAddresses(),
            $address_map,
        );
        $this->drainPoolToAddressMap($this->resource_context_pool->drainEmittedWithAddresses(), $address_map);
        $this->drainPoolToAddressMap(
            $this->user_function_definition_context_pool->drainEmittedWithAddresses(),
            $address_map,
        );
        $this->drainPoolToAddressMap($this->closure_context_pool->drainEmittedWithAddresses(), $address_map);
    }

    /**
     * @param iterable<int, ReferenceContext> $entries address => context
     * @param array<int, int>                 $address_map
     */
    private function drainPoolToAddressMap(
        iterable $entries,
        array &$address_map,
    ): void {
        foreach ($entries as $address => $context) {
            $node_id = $context->getMemoNodeId();
            if ($node_id !== null) {
                // Decode negative memo values (reserved but emitted)
                $address_map[$address] = $node_id < 0 ? -$node_id - 1 : $node_id;
            }
        }
    }
}
