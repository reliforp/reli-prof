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

use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;

interface ProcessModuleSymbolReaderCreatorInterface
{
    public function createModuleReaderByNameRegex(
        int $pid,
        ProcessMemoryMap $process_memory_map,
        string $regex,
        ?string $binary_path,
        ?ProcessModuleSymbolReader $libpthread_symbol_reader = null,
        ?int $root_link_map_address = null,
    ): ?ProcessModuleSymbolReader;
}
