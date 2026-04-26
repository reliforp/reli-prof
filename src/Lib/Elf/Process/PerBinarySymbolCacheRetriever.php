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

namespace Reli\Lib\Elf\Process;

use Reli\Lib\Elf\SymbolResolver\Elf64SymbolResolver;
use Reli\Lib\Elf\SymbolResolver\Elf64SymbolCache;

final class PerBinarySymbolCacheRetriever
{
    /** @var array<string, Elf64SymbolCache> */
    private array $cache = [];

    /**
     * Holds the parsed inner resolver per binary fingerprint so that a
     * cold attach which constructs several Elf64LazyParseSymbolResolver
     * instances against the same libphp.so reuses one parse instead of
     * re-reading 19 MB off disk per instance.
     *
     * @var array<string, Elf64SymbolResolver>
     */
    private array $resolver_cache = [];

    public function get(BinaryFingerprint $binary_fingerprint): Elf64SymbolCache
    {
        if (!isset($this->cache[(string)$binary_fingerprint])) {
            $this->cache[(string)$binary_fingerprint] = new Elf64SymbolCache();
        }
        return $this->cache[(string)$binary_fingerprint];
    }

    public function getResolver(BinaryFingerprint $binary_fingerprint): ?Elf64SymbolResolver
    {
        return $this->resolver_cache[(string)$binary_fingerprint] ?? null;
    }

    public function setResolver(
        BinaryFingerprint $binary_fingerprint,
        Elf64SymbolResolver $resolver,
    ): void {
        $this->resolver_cache[(string)$binary_fingerprint] = $resolver;
    }
}
