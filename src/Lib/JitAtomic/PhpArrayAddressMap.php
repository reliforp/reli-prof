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
 * Plain PHP array<int,int>-backed AddressMap. Used as the universal
 * fallback when JitAtomicAddressMap can't initialise (non-x86_64,
 * FFI disabled, executable mmap denied by sandbox/PaX, etc.).
 *
 * Single-thread-only. Sharing this instance across parallel\Runtime
 * workers is not supported.
 */
final class PhpArrayAddressMap implements AddressMap
{
    /** @var array<int, int> */
    private array $arr = [];

    #[\Override]
    public function has(int $address): bool
    {
        return isset($this->arr[$address]);
    }

    #[\Override]
    public function get(int $address): ?int
    {
        return $this->arr[$address] ?? null;
    }

    #[\Override]
    public function set(int $address, int $node_id): void
    {
        $this->arr[$address] = $node_id;
    }
}
