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

use Reli\Lib\ByteStream\ByteReaderInterface;
use Reli\Lib\Elf\Parser\Elf64Parser;
use Reli\Lib\Elf\Parser\ElfParserException;
use Reli\Lib\Elf\Structure\Elf64\Elf64GnuHashTable;
use Reli\Lib\Elf\Structure\Elf64\Elf64StringTable;
use Reli\Lib\Elf\Structure\Elf64\Elf64SymbolTable;
use Reli\Lib\Elf\Structure\Elf64\Elf64SymbolTableEntry;
use Reli\Lib\Integer\UInt64;

final class Elf64DynamicSymbolResolver implements Elf64SymbolResolver
{
    /**
     * @throws ElfParserException
     */
    public static function load(Elf64Parser $parser, ByteReaderInterface $php_binary): self
    {
        $elf_header = $parser->parseElfHeader($php_binary);
        $elf_program_header = $parser->parseProgramHeader($php_binary, $elf_header);
        $base_address = $elf_program_header->findBaseAddress();
        $dynamic_entry = $elf_program_header->findDynamic()[0];
        $elf_dynamic_array = $parser->parseDynamicStructureArray(
            $php_binary,
            $dynamic_entry->p_offset,
            $dynamic_entry->p_vaddr,
        );
        $elf_string_table = $parser->parseStringTable($php_binary, $base_address, $elf_dynamic_array);
        $elf_gnu_hash_table = $parser->parseGnuHashTable($php_binary, $base_address, $elf_dynamic_array);
        if (is_null($elf_gnu_hash_table)) {
            throw new ElfParserException('cannot find gnu hash table');
        }
        $elf_symbol_table = $parser->parseSymbolTableFromDynamic(
            $php_binary,
            $base_address,
            $elf_dynamic_array,
            $elf_gnu_hash_table->getNumberOfSymbols()
        );

        $dt_debug_address = null;
        $debug_entry = $elf_dynamic_array->findDebugEntry();
        if (!is_null($debug_entry)) {
            $dt_debug_address = $debug_entry->v_addr;
        }

        return new self(
            $elf_symbol_table,
            $elf_gnu_hash_table,
            $elf_string_table,
            $base_address,
            $dt_debug_address
        );
    }

    public function __construct(
        private Elf64SymbolTable $symbol_table,
        private Elf64GnuHashTable $hash_table,
        private Elf64StringTable $string_table,
        private UInt64 $base_address,
        private ?int $dt_debug_address = null,
    ) {
    }

    public function resolve(string $symbol_name): Elf64SymbolTableEntry
    {
        $index = $this->hash_table->lookup($symbol_name, function (string $name, int $index) {
            $symbol = $this->symbol_table->lookup($index);
            return $name === $this->string_table->lookup($symbol->st_name);
        });
        return $this->symbol_table->lookup($index);
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
