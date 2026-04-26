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

use FFI;
use FFI\CData;

/**
 * Concurrent open-addressing hashmap (uint64_t key -> uint64_t value).
 *
 * @psalm-suppress InvalidFunctionCall
 * @psalm-suppress UndefinedPropertyAssignment
 * @psalm-suppress UndefinedPropertyFetch
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedOperand
 * @psalm-suppress MixedReturnStatement
 * @psalm-suppress NullableReturnStatement
 * @psalm-suppress PossiblyNullPropertyAssignment
 * @psalm-suppress PossiblyNullPropertyAssignmentValue
 * @psalm-suppress PossiblyNullPropertyFetch
 * @psalm-suppress PossiblyInvalidArgument
 * @psalm-suppress PossiblyNullArrayAccess
 * @psalm-suppress PossiblyNullReference
 * @psalm-suppress InaccessibleMethod
 *
 * Backing storage is an FFI-allocated `uint64_t[2*capacity]` buffer,
 * laid out as paired (key, value) slots. Capacity is fixed at
 * construction time and must be a power of 2; load factor of ~0.5 is
 * recommended (so capacity = 2 * expected key count, rounded up to
 * power of 2).
 *
 * All hot-path operations dispatch into 193 bytes of position-independent
 * x86_64 machine code (see AtomicCodeRegion). Each call uses LOCK-prefixed
 * CMPXCHG / acquire-release atomic loads / stores, so concurrent writers
 * from multiple threads (e.g. parallel\Runtime under ffi-zts-parallel)
 * are safe.
 *
 * Sentinels:
 *   - key=0 is reserved as "empty slot". Never insert key=0.
 *     Memory addresses are never NULL, so this is natural for reli's use.
 *   - value's high bit is reserved by the machine code as a return-flag;
 *     stored values must satisfy `value & PHP_INT_MIN === 0`.
 *     Reli node_ids are non-negative and well below this limit.
 *
 * NOT supported (intentionally minimal): resize, removal, iteration.
 * Use a sufficiently large capacity for the expected workload.
 */
final class JitAtomicHashMap
{
    private CData $getOrInsert;
    private CData $lookupOnly;
    private CData $buckets;
    private CData $bucketsBase; // uint64_t* view for passing to the JIT'd code
    private int $capacity;
    private int $mask;
    private FFI $ffi;

    /**
     * @param int $capacity Number of slots; must be a power of 2 and > 0.
     *                      Recommended: roughly 2x expected unique key count.
     */
    public function __construct(int $capacity, ?AtomicCodeRegion $region = null)
    {
        if ($capacity <= 0 || ($capacity & ($capacity - 1)) !== 0) {
            throw new \InvalidArgumentException(
                "JitAtomicHashMap capacity must be a positive power of 2 (got {$capacity})",
            );
        }
        $region = $region ?? AtomicCodeRegion::instance();
        $this->ffi = $region->ffi();

        $this->capacity = $capacity;
        $this->mask = $capacity - 1;
        $this->buckets = $this->ffi->new("uint64_t[" . (2 * $capacity) . "]", false);
        // The (key, value) buffer lives in the script's PHP arena via
        // FFI::new(..., owned=false) — meaning we manage its lifetime
        // via the parent PHP refcount (the $buckets property), not via
        // automatic CData destruction. This matters when sharing across
        // parallel\Runtime workers, who only see the raw pointer.

        $this->bucketsBase = $this->ffi->cast('uint64_t*', FFI::addr($this->buckets[0]));

        $this->getOrInsert = $region->getOrInsert();
        $this->lookupOnly  = $region->lookupOnly();
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    /**
     * Pointer (as integer address) to the bucket array, suitable for
     * passing to a parallel\Runtime worker that will recreate a CData
     * view via the same union-cast trick used in AtomicCodeRegion.
     *
     * The bucket buffer remains alive for the lifetime of $this, so
     * the parent process must keep this object alive for the duration
     * of any worker that uses the address.
     */
    public function bucketsAddress(): int
    {
        $u = $this->ffi->new('union reli_addr_u');
        $u->p = $this->ffi->cast('void*', FFI::addr($this->buckets[0]));
        return $u->i;
    }

    public function mask(): int
    {
        return $this->mask;
    }

    public function has(int $key): bool
    {
        $r = ($this->lookupOnly)($this->bucketsBase, $this->mask, $key);
        return $r !== 0;
    }

    /**
     * @return int|null  null if absent, otherwise the stored value
     *                   (range 0 .. PHP_INT_MAX).
     */
    public function get(int $key): ?int
    {
        $r = ($this->lookupOnly)($this->bucketsBase, $this->mask, $key);
        if ($r === 0) {
            return null;
        }
        return $r & PHP_INT_MAX;
    }

    /**
     * Insert (key, value) if key is absent. Returns:
     *   - null if newly inserted (caller's value was stored).
     *   - the existing value if key was already present (caller's
     *     value is discarded).
     *
     * This is the race-free MT-safe insertion primitive. In single-thread
     * use, the return value is also useful as a "did this exist?" signal.
     *
     * @return int|null
     */
    public function setIfAbsent(int $key, int $value): ?int
    {
        $r = ($this->getOrInsert)($this->bucketsBase, $this->mask, $key, $value);
        // High bit set => newly inserted; we return null to signal "no prior".
        // High bit clear => key existed; the low bits are the existing value.
        if (($r & PHP_INT_MIN) !== 0) {
            return null;
        }
        return $r & PHP_INT_MAX;
    }

    /**
     * Convenience for "I already checked this address is absent and want
     * to record its mapping" — the existing reli pattern. Discards the
     * race-detection signal.
     *
     * For MT use, prefer setIfAbsent() so you can detect collisions.
     */
    public function set(int $key, int $value): void
    {
        ($this->getOrInsert)($this->bucketsBase, $this->mask, $key, $value);
    }
}
