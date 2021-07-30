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

namespace PhpProfiler\Lib\Elf\SymbolResolver;

use PhpProfiler\Lib\Elf\Structure\Elf64\Elf64StringTable;
use PhpProfiler\Lib\Elf\Structure\Elf64\Elf64SymbolTable;
use PhpProfiler\Lib\Elf\Structure\Elf64\Elf64SymbolTableEntry;

/**
 * Class Elf64LinearScanSymbolResolver
 * @package PhpProfiler\Lib\Elf
 */
final class Elf64LinearScanSymbolResolver implements Elf64AllSymbolResolver
{
    /**
     * Elf64LinearScanSymbolResolver constructor.
     */
    public function __construct(
        private Elf64SymbolTable $symbol_table,
        private Elf64StringTable $string_table,
    ) {
    }

    /**
     * @param string $symbol_name
     * @return Elf64SymbolTableEntry
     */
    public function resolve(string $symbol_name): Elf64SymbolTableEntry
    {
        $all_symbols = $this->symbol_table->findAll();
        foreach ($all_symbols as $entry) {
            $name = $this->string_table->lookup($entry->st_name);
            if ($symbol_name === $name) {
                return $entry;
            }
        }
        return $all_symbols[Elf64SymbolTable::STN_UNDEF];
    }
}
