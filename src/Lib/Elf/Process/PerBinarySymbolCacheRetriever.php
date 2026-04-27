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

use Reli\Lib\Elf\SymbolResolver\Elf64SymbolCache;

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
}
