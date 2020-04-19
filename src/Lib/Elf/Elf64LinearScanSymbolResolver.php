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
 * Class Elf64LinearScanSymbolResolver
 * @package PhpProfiler\Lib\Elf
 */
final class Elf64LinearScanSymbolResolver implements Elf64AllSymbolResolver
{
    private Elf64SymbolTable $symbol_table;
    private Elf64StringTable $string_table;

    /**
     * Elf64LinearScanSymbolResolver constructor.
     * @param Elf64SymbolTable $symbol_table
     * @param Elf64StringTable $string_table
     */
    public function __construct(Elf64SymbolTable $symbol_table, Elf64StringTable $string_table)
    {
        $this->symbol_table = $symbol_table;
        $this->string_table = $string_table;
    }

    /**
     * @param string $symbol_name
     * @return Elf64SymbolTableEntry
     */
    public function resolve(string $symbol_name): Elf64SymbolTableEntry
    {
        foreach ($this->symbol_table->entries as $entry) {
            $name = $this->string_table->lookup($entry->st_name);
            if ($symbol_name === $name) {
                return $entry;
            }
        }
        return $this->symbol_table->entries[Elf64SymbolTable::STN_UNDEF];
    }
}
