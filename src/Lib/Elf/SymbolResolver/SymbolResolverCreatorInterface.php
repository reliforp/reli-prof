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

namespace Reli\Lib\Elf\SymbolResolver;

use Reli\Lib\Elf\Parser\ElfParserException;
use Reli\Lib\Process\MemoryMap\ProcessModuleMemoryMapInterface;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

interface SymbolResolverCreatorInterface
{
    /**
     * @throws ElfParserException
     */
    public function createLinearScanResolverFromPath(string $path): Elf64AllSymbolResolver;

    /**
     * @throws ElfParserException
     */
    public function createDynamicResolverFromPath(string $path): Elf64SymbolResolver;

    public function createDynamicResolverFromProcessMemory(
        MemoryReaderInterface $memory_reader,
        int $pid,
        ProcessModuleMemoryMapInterface $module_memory_map
    ): Elf64SymbolResolver;
}
