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

use Reli\Lib\Elf\Structure\Elf64\Elf64StringTable;
use Reli\Lib\Elf\Structure\Elf64\Elf64SymbolTable;
use Reli\Lib\Elf\Structure\Elf64\Elf64SymbolTableEntry;
use Reli\Lib\Integer\UInt64;

final class Elf64LinearScanSymbolResolver implements Elf64AllSymbolResolver
{
    public function __construct(
        private Elf64SymbolTable $symbol_table,
        private Elf64StringTable $string_table,
        private UInt64 $base_address,
        private ?int $dt_debug_address,
    ) {
    }

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

    public function getDtDebugAddress(): ?int
    {
        return $this->dt_debug_address;
    }

    public function getBaseAddress(): UInt64
    {
        return $this->base_address;
    }
}
