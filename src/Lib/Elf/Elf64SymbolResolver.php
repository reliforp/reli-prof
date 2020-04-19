<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpProfiler\Lib\Elf;

/**
 * Interface Elf64SymbolResolver
 * @package PhpProfiler\Lib\Elf
 */
interface Elf64SymbolResolver
{
    /**
     * @param string $symbol_name
     * @return Elf64SymbolTableEntry
     */
    public function resolve(string $symbol_name): Elf64SymbolTableEntry;
}
