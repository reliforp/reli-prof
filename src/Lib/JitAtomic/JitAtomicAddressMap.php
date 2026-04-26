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

namespace Reli\Lib\JitAtomic;

/**
 * AddressMap backed by JitAtomicHashMap (LOCK-prefixed atomics on
 * FFI-managed memory). Capacity is fixed at construction time; no
 * resize. Pick a generous power-of-2 capacity for the expected
 * working-set size.
 *
 * Sharing the same instance across parallel\Runtime workers is the
 * point: the underlying buffer lives in the host's process heap
 * and worker threads see it via JitAtomicHashMap::bucketsAddress().
 */
final class JitAtomicAddressMap implements AddressMap
{
    private JitAtomicHashMap $map;

    public function __construct(int $capacity = 1 << 20)
    {
        $this->map = new JitAtomicHashMap($capacity);
    }

    public function inner(): JitAtomicHashMap
    {
        return $this->map;
    }

    #[\Override]
    public function has(int $address): bool
    {
        return $this->map->has($address);
    }

    #[\Override]
    public function get(int $address): ?int
    {
        return $this->map->get($address);
    }

    #[\Override]
    public function set(int $address, int $node_id): void
    {
        // setIfAbsent gives us race-free "first writer wins" semantics
        // even under cross-thread concurrent writes. The single-thread
        // collector never re-records the same address with a different
        // node_id, so the discarded-write branch (existing != incoming)
        // is unreachable today; once the heap walk goes parallel, this
        // is exactly the semantics we need.
        $this->map->setIfAbsent($address, $node_id);
    }
}
