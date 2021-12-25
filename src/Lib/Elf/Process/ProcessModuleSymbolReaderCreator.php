<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\Elf\Process;

use PhpProfiler\Lib\Elf\Parser\ElfParserException;
use PhpProfiler\Lib\Elf\SymbolResolver\SymbolResolverCreatorInterface;
use PhpProfiler\Lib\Process\MemoryMap\ProcessMemoryMap;
use PhpProfiler\Lib\Process\MemoryMap\ProcessModuleMemoryMap;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderInterface;

final class ProcessModuleSymbolReaderCreator
{
    public function __construct(
        private SymbolResolverCreatorInterface $symbol_resolver_creator,
        private MemoryReaderInterface $memory_reader
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

        $module_name = current($memory_areas)->name;
        $path = $binary_path ?? $this->createContainerAwarePath($pid, $module_name);
        try {
            $symbol_resolver = $this->symbol_resolver_creator->createLinearScanResolverFromPath($path);
            return new ProcessModuleSymbolReader(
                $pid,
                $symbol_resolver,
                $module_memory_map,
                $this->memory_reader,
                $tls_block_address
            );
        } catch (ElfParserException $e) {
            try {
                $symbol_resolver = $this->symbol_resolver_creator->createDynamicResolverFromPath($path);
                return new ProcessModuleSymbolReader(
                    $pid,
                    $symbol_resolver,
                    $module_memory_map,
                    $this->memory_reader,
                    $tls_block_address
                );
            } catch (ElfParserException $e) {
                $symbol_resolver = $this->symbol_resolver_creator->createDynamicResolverFromProcessMemory(
                    $this->memory_reader,
                    $pid,
                    $module_memory_map
                );
                return new ProcessModuleSymbolReader(
                    $pid,
                    $symbol_resolver,
                    $module_memory_map,
                    $this->memory_reader,
                    $tls_block_address
                );
            }
        }
    }

    private function createContainerAwarePath(int $pid, string $path): string
    {
        return "/proc/{$pid}/root{$path}";
    }
}
