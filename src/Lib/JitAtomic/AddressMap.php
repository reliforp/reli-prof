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
 * Address -> node_id map used by MemoryLocationsCollector for dedup
 * across the heap walk. Two implementations:
 *
 *   PhpArrayAddressMap  — wraps a plain array<int,int>; available
 *                          everywhere; not safe for cross-thread use.
 *
 *   JitAtomicAddressMap — backed by JitAtomicHashMap (LOCK-prefixed
 *                          atomics on FFI-managed memory); requires
 *                          Linux x86_64 + executable mmap; safe for
 *                          parallel\Runtime workers to share.
 *
 * Use AddressMapFactory::create() to pick the right implementation
 * automatically. Both implementations satisfy the same observable
 * semantics for the existing single-thread collector path:
 *
 *   - keys are non-negative ints (real memory addresses; 0 is reserved
 *     by the JIT impl as empty-slot sentinel and is never a real address)
 *   - values are non-negative ints fitting in 63 bits (reli node_ids)
 *   - set() with an already-present key overwrites. (When migrating to
 *     parallel workers, callers should switch to a "set if absent"
 *     primitive on the concrete JitAtomicHashMap to detect races.)
 */
interface AddressMap
{
    public function has(int $address): bool;

    public function get(int $address): ?int;

    public function set(int $address, int $node_id): void;
}
