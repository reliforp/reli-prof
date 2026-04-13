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

namespace Reli\Lib\Process\Pointer;

/**
 * Caching decorator for Dereferencer.
 *
 * Caches deref results by (type, address) so that repeated dereferences
 * of the same pointer (e.g. 94K objects of the same class all dereffing
 * the same zend_class_entry address) return the same PHP object without
 * re-reading memory or re-allocating FFI buffers.
 *
 * The cache is two-level: a `type → address → result` nested map. This
 * avoids allocating a fresh `"type:address"` string per call (which an
 * earlier single-level version did) and lets the hot inner lookup hit
 * an int-keyed PHP array — no hash computation, no string comparison,
 * just a direct bucket index. The outer `type` map only has ~30 live
 * entries (one per distinct Dereferencable class) and every key is an
 * interned class-string with a pre-hashed zend_string, so the outer
 * lookup is nearly free too.
 */
final class CachingDereferencer implements Dereferencer
{
    /** @var array<class-string, array<int, mixed>> type → (address → result) */
    private array $cache = [];

    /** Running count of cached entries across all types, for eviction. */
    private int $count = 0;

    public function __construct(
        private Dereferencer $inner,
        private int $max_entries = 65536,
    ) {
    }

    /**
     * @template T of Dereferencable
     * @param Pointer<T> $pointer
     * @return T
     */
    #[\Override]
    public function deref(Pointer $pointer): mixed
    {
        $type = $pointer->type;
        $addr = $pointer->address;
        if (isset($this->cache[$type][$addr])) {
            /** @var T */
            return $this->cache[$type][$addr];
        }
        $result = $this->inner->deref($pointer);
        $this->cache[$type][$addr] = $result;
        $this->count++;
        if ($this->count >= $this->max_entries) {
            $this->evictQuarter();
        }
        return $result;
    }

    /**
     * A large cache is cheap in offline analysis, but walking every
     * per-type bucket to evict a quarter became a hotspot of its own.
     * Once the cache is full, clearing it outright is cheaper than
     * paying an O(types + entries) partial-eviction pass.
     */
    private function evictQuarter(): void
    {
        $this->cache = [];
        $this->count = 0;
    }

    public function clearCache(): void
    {
        $this->cache = [];
        $this->count = 0;
    }
}
