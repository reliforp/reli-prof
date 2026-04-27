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

use Reli\Lib\Debug\ResolverShareLogger;
use Reli\Lib\Elf\SymbolResolver\Elf64SymbolCache;
use Reli\Lib\Elf\SymbolResolver\Elf64SymbolResolver;

final class PerBinarySymbolCacheRetriever
{
    /** @var array<string, Elf64SymbolCache> */
    private array $cache = [];

    /**
     * Cache of raw binary bytes (as PHP strings) per binary fingerprint.
     * Cold attach often calls Elf64LazyParseSymbolResolver multiple times
     * for the same libphp.so (PhpGlobalsFinder, resolveTlsBlock,
     * TsrmGlobalsResolver each create their own symbol reader); each
     * instance would otherwise re-read the entire binary off disk.
     *
     * Earlier iterations of this branch tried caching the parsed
     * Elf64SymbolResolver itself, but that triggered ARM64
     * "zend_mm_heap corrupted" failures during MemoryDumpCommandTest
     * (bisect: PRs #669, #674, #675). The exact PHP / GC interaction is
     * still TBD — possibly the deep object graph (Elf64SymbolTable with
     * tens of thousands of Elf64SymbolTableEntry instances, each holding
     * UInt64 instances) accumulating across long-lived cache entries
     * stresses zend_mm in a way x86_64 tolerates and aarch64 does not.
     * Sharing a plain PHP string (a single zval, refcounted, no nested
     * objects) avoids that path entirely while still preventing the
     * disk re-read.
     *
     * @var array<string, string>
     */
    private array $binary_bytes_cache = [];

    public function get(BinaryFingerprint $binary_fingerprint): Elf64SymbolCache
    {
        if (!isset($this->cache[(string)$binary_fingerprint])) {
            $this->cache[(string)$binary_fingerprint] = new Elf64SymbolCache();
        }
        return $this->cache[(string)$binary_fingerprint];
    }

    public function getBinaryBytes(BinaryFingerprint $binary_fingerprint): ?string
    {
        return $this->binary_bytes_cache[(string)$binary_fingerprint] ?? null;
    }

    public function setBinaryBytes(
        BinaryFingerprint $binary_fingerprint,
        string $bytes,
    ): void {
        $this->binary_bytes_cache[(string)$binary_fingerprint] = $bytes;
    }

    /**
     * Cache of parsed Elf64SymbolResolver instances per binary fingerprint.
     *
     * THIS IS THE BROKEN PATH. Populated only when
     * RELI_DEBUG_FORCE_PARSED_RESOLVER_SHARE=1 is set in the environment;
     * otherwise the array stays empty and the resolver is re-parsed each
     * time from the cached bytes. Holding parsed Elf64SymbolResolver
     * instances alive across attaches is what tripped ARM64 zend_mm in
     * #669 / #674; this debug knob exists so we can re-trigger the
     * failure under USE_ZEND_ALLOC=0 / valgrind / etc. to localise the
     * actual write that corrupts the heap.
     *
     * @var array<string, Elf64SymbolResolver>
     */
    private array $resolver_cache = [];

    public function getResolver(BinaryFingerprint $binary_fingerprint): ?Elf64SymbolResolver
    {
        if (!ResolverShareLogger::forceParsedResolverShare()) {
            return null;
        }
        $cached = $this->resolver_cache[(string)$binary_fingerprint] ?? null;
        ResolverShareLogger::log('parsed_share.get', [
            'fp' => substr((string)$binary_fingerprint, 0, 80),
            'hit' => $cached !== null,
            'class' => $cached,
            'cache_size' => count($this->resolver_cache),
        ]);
        return $cached;
    }

    public function setResolver(
        BinaryFingerprint $binary_fingerprint,
        Elf64SymbolResolver $resolver,
    ): void {
        if (!ResolverShareLogger::forceParsedResolverShare()) {
            return;
        }
        ResolverShareLogger::snapshot('parsed_share.set.before');
        $this->resolver_cache[(string)$binary_fingerprint] = $resolver;
        ResolverShareLogger::log('parsed_share.set.after', [
            'fp' => substr((string)$binary_fingerprint, 0, 80),
            'class' => $resolver,
            'base_address' => sprintf('0x%x', $resolver->getBaseAddress()->toInt()),
            'cache_size_after' => count($this->resolver_cache),
        ]);
    }
}
