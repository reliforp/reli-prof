<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpProfiler\Lib\Elf\Process;

use PhpProfiler\Lib\Elf\Parser\ElfParserException;
use PhpProfiler\Lib\Elf\SymbolResolver\SymbolResolverCreator;
use PhpProfiler\Lib\Process\MemoryMap\ProcessMemoryMap;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderInterface;

/**
 * Class ProcessModuleSymbolReaderCreator
 * @package PhpProfiler\ProcessReader
 */
final class ProcessModuleSymbolReaderCreator
{
    private SymbolResolverCreator $symbol_resolver_creator;
    private MemoryReaderInterface $memory_reader;

    /**
     * ProcessSymbolReaderCreator constructor.
     *
     * @param SymbolResolverCreator $symbol_resolver_creator
     * @param MemoryReaderInterface $memory_reader
     */
    public function __construct(
        SymbolResolverCreator $symbol_resolver_creator,
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

        $module_name = current($memory_areas)->name;
        $symbol_resolver = $this->symbol_resolver_creator->createLinearScanResolverFromPath($module_name);
        return new ProcessModuleSymbolReader(
            $pid,
            $symbol_resolver,
            $memory_areas,
            $this->memory_reader,
            $tls_block_address
        );
    }
}
