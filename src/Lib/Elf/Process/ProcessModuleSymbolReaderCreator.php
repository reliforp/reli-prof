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

/**
 * Class ProcessModuleSymbolReaderCreator
 * @package PhpProfiler\ProcessReader
 */
final class ProcessModuleSymbolReaderCreator
{
    private SymbolResolverCreatorInterface $symbol_resolver_creator;
    private MemoryReaderInterface $memory_reader;

    /**
     * ProcessSymbolReaderCreator constructor.
     *
     * @param SymbolResolverCreatorInterface $symbol_resolver_creator
     * @param MemoryReaderInterface $memory_reader
     */
    public function __construct(
        SymbolResolverCreatorInterface $symbol_resolver_creator,
        MemoryReaderInterface $memory_reader
    ) {
        $this->symbol_resolver_creator = $symbol_resolver_creator;
        $this->memory_reader = $memory_reader;
    }

    /**
     * @param int $pid
     * @param ProcessMemoryMap $process_memory_map
     * @param string $regex
     * @param int|null $tls_block_address
     * @return ProcessModuleSymbolReader|null
     * @throws ElfParserException
     */
    public function createModuleReaderByNameRegex(
        int $pid,
        ProcessMemoryMap $process_memory_map,
        string $regex,
        ?int $tls_block_address = null
    ): ?ProcessModuleSymbolReader {
        $memory_areas = $process_memory_map->findByNameRegex($regex);
        if ($memory_areas === []) {
            return null;
        }
        $module_memory_map = new ProcessModuleMemoryMap($memory_areas);

        $module_name = current($memory_areas)->name;
        $container_aware_path = $this->createContainerAwarePath($pid, $module_name);
        try {
            $symbol_resolver = $this->symbol_resolver_creator->createLinearScanResolverFromPath($container_aware_path);
            return new ProcessModuleSymbolReader(
                $pid,
                $symbol_resolver,
                $module_memory_map,
                $this->memory_reader,
                $tls_block_address
            );
        } catch (ElfParserException $e) {
            try {
                $symbol_resolver = $this->symbol_resolver_creator->createDynamicResolverFromPath($container_aware_path);
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

    /**
     * @param int $pid
     * @param string $path
     * @return string
     */
    private function createContainerAwarePath(int $pid, string $path): string
    {
        return "/proc/{$pid}/root{$path}";
    }
}
