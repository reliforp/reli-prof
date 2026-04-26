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

use Reli\Lib\ByteStream\StringByteReader;
use Reli\Lib\Elf\Parser\ElfParserException;
use Reli\Lib\Elf\Structure\Elf64\Elf64SymbolTableEntry;
use Reli\Lib\Elf\SymbolResolver\Elf64SymbolResolver;
use Reli\Lib\Elf\SymbolResolver\Elf64SymbolResolverCreator;
use Reli\Lib\Elf\SymbolResolver\SymbolResolverCreatorInterface;
use Reli\Lib\File\FileReaderInterface;
use Reli\Lib\Integer\UInt64;
use Reli\Lib\Process\MemoryMap\ProcessModuleMemoryMap;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

final class Elf64LazyParseSymbolResolver implements Elf64SymbolResolver
{
    private ?Elf64SymbolResolver $resolver_cache = null;

    public function __construct(
        private string $path,
        private MemoryReaderInterface $memory_reader,
        private int $pid,
        private ProcessModuleMemoryMap $module_memory_map,
        private SymbolResolverCreatorInterface $symbol_resolver_creator,
        private ?PerBinarySymbolCacheRetriever $shared_resolver_cache = null,
        private ?BinaryFingerprint $fingerprint = null,
    ) {
    }

    private function loadResolver(): Elf64SymbolResolver
    {
        // Cold attach often constructs several Elf64LazyParseSymbolResolver
        // instances against the same libphp.so (PhpGlobalsFinder,
        // resolveTlsBlock, TsrmGlobalsResolver each create their own symbol
        // reader), and each instance would otherwise re-read and re-parse
        // the 19 MB binary. The shared resolver cache, keyed on the binary
        // fingerprint, lets the second-and-later instances skip straight
        // to the parsed inner resolver.
        if ($this->shared_resolver_cache !== null && $this->fingerprint !== null) {
            $shared = $this->shared_resolver_cache->getResolver($this->fingerprint);
            if ($shared !== null) {
                return $shared;
            }
        }
        $resolver = $this->loadResolverUncached();
        if ($this->shared_resolver_cache !== null && $this->fingerprint !== null) {
            $this->shared_resolver_cache->setResolver($this->fingerprint, $resolver);
        }
        return $resolver;
    }

    private function loadResolverUncached(): Elf64SymbolResolver
    {
        // Both the linear-scan and the dynamic-symbol resolver want the
        // libphp.so contents as a string. Reading the binary once and
        // letting the fallback chain reuse it shaves roughly half the
        // cold-attach disk-read cost on stripped builds (FrankenPHP), where
        // the linear-scan path is guaranteed to fail and we always have to
        // try the dynamic-symbol path next.
        if ($this->symbol_resolver_creator instanceof Elf64SymbolResolverCreator) {
            $binary = $this->readBinaryOnce();
            if ($binary !== null) {
                try {
                    return $this->symbol_resolver_creator->createLinearScanResolverFromBinary($binary);
                } catch (ElfParserException) {
                    try {
                        return $this->symbol_resolver_creator->createDynamicResolverFromBinary($binary);
                    } catch (ElfParserException) {
                        return $this->symbol_resolver_creator->createDynamicResolverFromProcessMemory(
                            $this->memory_reader,
                            $this->pid,
                            $this->module_memory_map
                        );
                    }
                }
            }
        }
        try {
            return $this->symbol_resolver_creator->createLinearScanResolverFromPath($this->path);
        } catch (ElfParserException) {
            try {
                return $this->symbol_resolver_creator->createDynamicResolverFromPath($this->path);
            } catch (ElfParserException) {
                return $this->symbol_resolver_creator->createDynamicResolverFromProcessMemory(
                    $this->memory_reader,
                    $this->pid,
                    $this->module_memory_map
                );
            }
        }
    }

    private function readBinaryOnce(): ?StringByteReader
    {
        if (!($this->symbol_resolver_creator instanceof Elf64SymbolResolverCreator)) {
            return null;
        }
        $reader = $this->symbol_resolver_creator->getFileReader();
        try {
            $raw = $reader->readAll($this->path);
        } catch (\Throwable) {
            return null;
        }
        if ($raw === '') {
            return null;
        }
        return new StringByteReader($raw);
    }

    #[\Override]
    public function resolve(string $symbol_name): Elf64SymbolTableEntry
    {
        if (!isset($this->resolver_cache)) {
            $this->resolver_cache = $this->loadResolver();
        }
        return $this->resolver_cache->resolve($symbol_name);
    }

    #[\Override]
    public function getDtDebugAddress(): ?int
    {
        if (!isset($this->resolver_cache)) {
            $this->resolver_cache = $this->loadResolver();
        }
        return $this->resolver_cache->getDtDebugAddress();
    }

    #[\Override]
    public function getBaseAddress(): UInt64
    {
        if (!isset($this->resolver_cache)) {
            $this->resolver_cache = $this->loadResolver();
        }
        return $this->resolver_cache->getBaseAddress();
    }
}
