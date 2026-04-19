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

namespace Reli\Inspector\Output\MemoryOutput\BinaryFormat;

use Reli\Lib\FFI\FFIHelper;

/**
 * Open-addressing hash table backed by FFI int arrays.
 *
 * Each slot is 24 bytes stored across 4 parallel FFI arrays:
 *   hash:       int64  (8 bytes) — 0 means empty
 *   id:         int32  (4 bytes)
 *   diskOffset: uint64 (8 bytes)
 *   len:        uint32 (4 bytes)
 *
 * Linear probing with 75% max load factor. Grows 2x when the load
 * threshold is reached. hash=0 is remapped to 1 to preserve the
 * empty-slot sentinel.
 *
 * Memory: ~28 bytes/slot × capacity (with load factor overhead, effectively
 * ~32 bytes per stored entry). At 10M entries: ~320 MB vs ~4.6 GB for
 * nested PHP arrays.
 *
 * @psalm-suppress InaccessibleMethod
 * @psalm-suppress InvalidCast
 */
final class FfiHashTable
{
    private \FFI\CData $hashes;   // int64_t[capacity]
    private \FFI\CData $ids;      // int32_t[capacity]
    private \FFI\CData $offsets;  // uint64_t[capacity]
    private \FFI\CData $lengths;  // uint32_t[capacity]

    private int $capacity;
    private int $mask; // capacity - 1, for fast modulo
    private int $count = 0;
    private int $growThreshold;

    private const MAX_LOAD_FACTOR = 0.75;
    private const DEFAULT_INITIAL_CAPACITY = 1024; // must be power of 2

    public function __construct(int $initial_capacity = self::DEFAULT_INITIAL_CAPACITY)
    {
        // Round up to next power of 2
        $cap = 1;
        while ($cap < $initial_capacity) {
            $cap <<= 1;
        }
        $this->allocate($cap);
    }

    /**
     * Insert an entry. Does NOT check for duplicates — the caller
     * (DiskBackedStringDict) handles dedup by comparing bytes.
     */
    public function insert(int $hash, int $id, int $diskOffset, int $len): void
    {
        if ($this->count >= $this->growThreshold) {
            $this->grow();
        }

        $h = self::sanitizeHash($hash);
        $slot = $h & $this->mask;

        while (true) {
            if ((int)$this->hashes[$slot] === 0) {
                // Empty slot — insert here
                $this->hashes[$slot] = $h;
                $this->ids[$slot] = $id;
                $this->offsets[$slot] = $diskOffset;
                $this->lengths[$slot] = $len;
                $this->count++;
                return;
            }
            $slot = ($slot + 1) & $this->mask;
        }
    }

    /**
     * Find all entries matching the given hash.
     *
     * The common case is zero or one hit, so returning a tiny list is
     * cheaper than constructing a generator frame on every lookup.
     *
     * @return list<array{int, int, int}>
     */
    public function findByHash(int $hash): array
    {
        $h = self::sanitizeHash($hash);
        $slot = $h & $this->mask;
        $matches = [];

        while (true) {
            $stored = (int)$this->hashes[$slot];
            if ($stored === 0) {
                return $matches; // empty slot → end of probe chain
            }
            if ($stored === $h) {
                $matches[] = [(int)$this->ids[$slot], (int)$this->offsets[$slot], (int)$this->lengths[$slot]];
            }
            $slot = ($slot + 1) & $this->mask;
        }
    }

    /**
     * Iterate ALL entries in insertion-undefined order.
     * Yields [id, diskOffset, len] for each occupied slot.
     *
     * @return \Generator<int, array{int, int, int}>
     */
    public function iterateAll(): \Generator
    {
        for ($i = 0; $i < $this->capacity; $i++) {
            if ((int)$this->hashes[$i] !== 0) {
                yield [(int)$this->ids[$i], (int)$this->offsets[$i], (int)$this->lengths[$i]];
            }
        }
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * Ensure hash is never 0 (our empty-slot sentinel).
     */
    private static function sanitizeHash(int $hash): int
    {
        return $hash !== 0 ? $hash : 1;
    }

    private function allocate(int $capacity): void
    {
        $this->capacity = $capacity;
        $this->mask = $capacity - 1;
        $this->growThreshold = intdiv($capacity * 3, 4);

        $this->hashes = FFIHelper::new("int64_t[{$capacity}]");
        $this->ids = FFIHelper::new("int32_t[{$capacity}]");
        $this->offsets = FFIHelper::new("uint64_t[{$capacity}]");
        $this->lengths = FFIHelper::new("uint32_t[{$capacity}]");

        // hashes are zero-initialized by FFI::new (calloc semantics)
    }

    private function grow(): void
    {
        $oldCap = $this->capacity;
        $oldHashes = $this->hashes;
        $oldIds = $this->ids;
        $oldOffsets = $this->offsets;
        $oldLengths = $this->lengths;

        $this->allocate($oldCap * 2);
        $this->count = 0;

        for ($i = 0; $i < $oldCap; $i++) {
            $h = (int)$oldHashes[$i];
            if ($h !== 0) {
                $this->insertRaw($h, (int)$oldIds[$i], (int)$oldOffsets[$i], (int)$oldLengths[$i]);
            }
        }
    }

    /**
     * Insert with an already-sanitized hash. Used during grow().
     */
    private function insertRaw(int $sanitizedHash, int $id, int $diskOffset, int $len): void
    {
        $slot = $sanitizedHash & $this->mask;
        while ((int)$this->hashes[$slot] !== 0) {
            $slot = ($slot + 1) & $this->mask;
        }
        $this->hashes[$slot] = $sanitizedHash;
        $this->ids[$slot] = $id;
        $this->offsets[$slot] = $diskOffset;
        $this->lengths[$slot] = $len;
        $this->count++;
    }
}
