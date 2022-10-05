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

namespace PhpProfiler\Lib\PhpProcessReader;

use PhpProfiler\Lib\Process\Pointer\Dereferencer;
use PhpProfiler\Lib\Process\Pointer\Pointer;

final class TraceCache
{
    /** @param array<string, array<int, \PhpProfiler\Lib\Process\Pointer\Dereferencable>> $cache */
    public function __construct(
        private float $key = 0,
        private array $cache = [],
    ) {
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }

    public function updateCacheKey(float $key): void
    {
        if ($this->key !== $key) {
            $this->key = $key;
            $this->clearCache();
        }
    }

    public function getDereferencer(Dereferencer $dereferencer): Dereferencer
    {
        return new class ($dereferencer, $this) implements Dereferencer {
            public function __construct(
                private Dereferencer $dereferencer,
                private TraceCache $trace_cache,
            ) {
            }

            public function deref(Pointer $pointer): mixed
            {
                $item = $this->trace_cache->getCache($pointer);
                if (is_null($item)) {
                    $item = $this->dereferencer->deref($pointer);
                    $this->trace_cache->setCache(
                        $pointer,
                        $item,
                    );
                }
                return $item;
            }
        };
    }

    /**
     * @template T of \PhpProfiler\Lib\Process\Pointer\Dereferencable
     * @param Pointer<T> $pointer
     * @param T $item
     */
    public function setCache(Pointer $pointer, mixed $item): void
    {
        $this->cache[$pointer->type][$pointer->address] = $item;
    }

    /**
     * @template T of \PhpProfiler\Lib\Process\Pointer\Dereferencable
     * @param Pointer<T> $pointer
     * @return T|null
     */
    public function getCache(Pointer $pointer): mixed
    {
        /** @var T|null */
        return $this->cache[$pointer->type][$pointer->address] ?? null;
    }
}
