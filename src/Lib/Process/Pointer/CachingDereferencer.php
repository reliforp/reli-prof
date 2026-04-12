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
        private int $max_entries = 4096,
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
     * Drop roughly the oldest quarter of cached entries. PHP arrays
     * preserve insertion order, so `array_slice($entries, $drop,
     * preserve_keys: true)` keeps the most recently inserted 75% per
     * type — a rough LRU approximation that's good enough for the
     * dereffer's temporal locality without needing a real LRU list.
     */
    private function evictQuarter(): void
    {
        $evicted = 0;
        foreach ($this->cache as $t => $entries) {
            $n = count($entries);
            if ($n === 0) {
                continue;
            }
            $drop = (int)($n / 4);
            if ($drop > 0) {
                $this->cache[$t] = array_slice($entries, $drop, preserve_keys: true);
                $evicted += $drop;
            }
        }
        $this->count -= $evicted;
    }

    public function clearCache(): void
    {
        $this->cache = [];
        $this->count = 0;
    }
}
