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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation;

use Reli\Lib\FFI\FFIHelper;

/**
 * Open-addressing hash set for int64 keys, backed by FFI arrays.
 *
 * Replaces PHP's array<int, true> for the lightweight "seen" set
 * in MemoryLocations. Memory: ~8 bytes/slot vs ~68 bytes/entry
 * for PHP arrays.
 *
 * Uses 0 as empty sentinel (no valid memory address is 0).
 * Linear probing with 75% max load factor, grows 2x.
 *
 * The `(int)` casts on `$this->slots[...]` are deliberate: add()/has()
 * are called per memory location during analysis (millions of ops),
 * and the FFI int64_t[] element type is provably int at runtime.
 * PhpCast\Cast::toInt() would be safer in principle but its per-call
 * method dispatch is measurable here. The resulting InvalidCast entries
 * stay in psalm-baseline.xml on purpose.
 */
final class FfiIntSet
{
    private \FFI\CData $slots;
    private int $capacity;
    private int $mask;
    private int $count = 0;
    private int $grow_threshold;

    private const MAX_LOAD_FACTOR = 0.75;
    private const DEFAULT_INITIAL_CAPACITY = 4096;

    public function __construct(int $initial_capacity = self::DEFAULT_INITIAL_CAPACITY)
    {
        $cap = 1;
        while ($cap < $initial_capacity) {
            $cap <<= 1;
        }
        $this->allocate($cap);
    }

    public function add(int $key): void
    {
        if ($key === 0) {
            return; // 0 is sentinel, skip
        }
        if ($this->count >= $this->grow_threshold) {
            $this->grow();
        }

        $slot = $this->hash($key) & $this->mask;
        while (true) {
            $stored = (int)$this->slots[$slot];
            if ($stored === 0) {
                $this->slots[$slot] = $key;
                $this->count++;
                return;
            }
            if ($stored === $key) {
                return; // already present
            }
            $slot = ($slot + 1) & $this->mask;
        }
    }

    public function has(int $key): bool
    {
        if ($key === 0) {
            return false;
        }
        $slot = $this->hash($key) & $this->mask;
        while (true) {
            $stored = (int)$this->slots[$slot];
            if ($stored === 0) {
                return false;
            }
            if ($stored === $key) {
                return true;
            }
            $slot = ($slot + 1) & $this->mask;
        }
    }

    public function count(): int
    {
        return $this->count;
    }

    private function hash(int $key): int
    {
        // Fold the high 32 bits into the low word so that the
        // multiplications below stay within 64-bit int range.
        // Without this PHP would silently promote the product to a
        // float when the key has bits above 2^31 (any real pointer),
        // which emits an E_WARNING on PHP 8.4+.
        $key = (($key >> 32) ^ $key) & 0xFFFFFFFF;
        $key = ((($key >> 16) ^ $key) * 0x45d9f3b) & 0xFFFFFFFF;
        $key = ((($key >> 16) ^ $key) * 0x45d9f3b) & 0xFFFFFFFF;
        $key = ($key >> 16) ^ $key;
        return $key & 0x7FFFFFFF;
    }

    private function allocate(int $capacity): void
    {
        $this->capacity = $capacity;
        $this->mask = $capacity - 1;
        $this->grow_threshold = (int)($capacity * self::MAX_LOAD_FACTOR);
        $this->slots = FFIHelper::new("int64_t[{$capacity}]");
        // FFI::new uses calloc semantics — slots are zero-initialized
    }

    private function grow(): void
    {
        $old_cap = $this->capacity;
        $old_slots = $this->slots;

        $this->allocate($old_cap * 2);
        $this->count = 0;

        for ($i = 0; $i < $old_cap; $i++) {
            $key = (int)$old_slots[$i];
            if ($key !== 0) {
                $this->add($key);
            }
        }
    }
}
