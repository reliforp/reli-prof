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
use Reli\Lib\Elf\SymbolResolver\Elf64CachedSymbolResolver;
use Reli\Lib\Elf\SymbolResolver\SymbolResolverCreatorInterface;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;
use Reli\Lib\Process\MemoryMap\ProcessModuleMemoryMap;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

final class ProcessModuleSymbolReaderCreator
{
    public function __construct(
        private SymbolResolverCreatorInterface $symbol_resolver_creator,
        private MemoryReaderInterface $memory_reader,
        private PerBinarySymbolCacheRetriever $per_binary_symbol_cache_retriever,
    ) {
    }

    public function createModuleReaderByNameRegex(
        int $pid,
        ProcessMemoryMap $process_memory_map,
        string $regex,
        ?string $binary_path,
        ?int $tls_block_address = null
    ): ?ProcessModuleSymbolReader {
        $memory_areas = $process_memory_map->findByNameRegex($regex);
        if ($memory_areas === []) {
            return null;
        }
        $module_memory_map = new ProcessModuleMemoryMap($memory_areas);

        $module_name = $module_memory_map->getModuleName();
        $path = $binary_path ?? $this->createContainerAwarePath($pid, $module_name);

        $symbol_resolver = new Elf64CachedSymbolResolver(
            new Elf64LazyParseSymbolResolver(
                $path,
                $this->memory_reader,
                $pid,
                $module_memory_map,
                $this->symbol_resolver_creator,
            ),
            $this->per_binary_symbol_cache_retriever->get(
                BinaryFingerprint::fromProcessModuleMemoryMap($module_memory_map)
            ),
        );
        return new ProcessModuleSymbolReader(
            $pid,
            $symbol_resolver,
            $module_memory_map,
            $this->memory_reader,
            $tls_block_address
        );
    }

    private function createContainerAwarePath(int $pid, string $path): string
    {
        return "/proc/{$pid}/root{$path}";
    }
}
