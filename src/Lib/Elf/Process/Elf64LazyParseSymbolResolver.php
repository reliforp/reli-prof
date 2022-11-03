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

use Reli\Lib\Elf\Parser\ElfParserException;
use Reli\Lib\Elf\Structure\Elf64\Elf64SymbolTableEntry;
use Reli\Lib\Elf\SymbolResolver\Elf64SymbolResolver;
use Reli\Lib\Elf\SymbolResolver\SymbolResolverCreatorInterface;
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
    ) {
    }

    private function loadResolver(): Elf64SymbolResolver
    {
        try {
            return $this->symbol_resolver_creator->createLinearScanResolverFromPath($this->path);
        } catch (ElfParserException $e) {
            try {
                return $this->symbol_resolver_creator->createDynamicResolverFromPath($this->path);
            } catch (ElfParserException $e) {
                return $this->symbol_resolver_creator->createDynamicResolverFromProcessMemory(
                    $this->memory_reader,
                    $this->pid,
                    $this->module_memory_map
                );
            }
        }
    }

    public function resolve(string $symbol_name): Elf64SymbolTableEntry
    {
        if (!isset($this->resolver_cache)) {
            $this->resolver_cache = $this->loadResolver();
        }
        return $this->resolver_cache->resolve($symbol_name);
    }
}
