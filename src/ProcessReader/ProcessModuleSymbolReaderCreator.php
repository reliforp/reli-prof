<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */


namespace PhpProfiler\ProcessReader;

use PhpProfiler\Lib\Elf\SymbolResolverCreator;
use PhpProfiler\Lib\Process\MemoryReader;

/**
 * Class ProcessModuleSymbolReaderCreator
 * @package PhpProfiler\ProcessReader
 */
final class ProcessModuleSymbolReaderCreator
{
    private ProcessMemoryMap $process_memory_map;
    private SymbolResolverCreator $symbol_resolver_creator;
    private MemoryReader $memory_reader;
    private int $pid;

    /**
     * ProcessSymbolReaderCreator constructor.
     *
     * @param int $pid
     * @param ProcessMemoryMap $process_memory_map
     * @param SymbolResolverCreator $symbol_resolver_creator
     * @param MemoryReader $memory_reader
     */
    public function __construct(int $pid, ProcessMemoryMap $process_memory_map, SymbolResolverCreator $symbol_resolver_creator, MemoryReader $memory_reader)
    {
        $this->pid = $pid;
        $this->process_memory_map = $process_memory_map;
        $this->symbol_resolver_creator = $symbol_resolver_creator;
        $this->memory_reader = $memory_reader;
    }

    /**
     * @param string $regex
     * @param int|null $tls_block_address
     * @return ProcessModuleSymbolReader|null
     */
    public function createModuleReaderByNameRegex(string $regex, ?int $tls_block_address = null): ?ProcessModuleSymbolReader
    {
        $memory_areas = $this->process_memory_map->findByNameRegex($regex);
        if ($memory_areas === []) {
            return null;
        }

        $module_name = current($memory_areas)->name;
        $symbol_resolver = $this->symbol_resolver_creator->createLinearScanResolverFromPath($module_name);
        return new ProcessModuleSymbolReader($this->pid, $symbol_resolver, $memory_areas, $this->memory_reader, $tls_block_address);
    }
}