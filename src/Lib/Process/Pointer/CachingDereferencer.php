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
 * Caches deref results by (address, type) so that repeated dereferences
 * of the same pointer (e.g. 94K objects of the same class all dereffing
 * the same zend_class_entry address) return the same PHP object without
 * re-reading memory or re-allocating FFI buffers.
 */
final class CachingDereferencer implements Dereferencer
{
    /** @var array<string, mixed> "type:address" → result */
    private array $cache = [];

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
        $key = $pointer->type . ':' . $pointer->address;
        if (isset($this->cache[$key])) {
            /** @var T */
            return $this->cache[$key];
        }
        $result = $this->inner->deref($pointer);
        if (count($this->cache) >= $this->max_entries) {
            // Evict oldest quarter to avoid unbounded growth
            $this->cache = array_slice($this->cache, (int)($this->max_entries / 4), preserve_keys: true);
        }
        $this->cache[$key] = $result;
        return $result;
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }
}
