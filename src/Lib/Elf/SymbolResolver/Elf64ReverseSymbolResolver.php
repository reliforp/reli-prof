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
use Reli\Lib\ByteStream\StringByteReader;
use Reli\Lib\Elf\Parser\Elf64Parser;
use Reli\Lib\Elf\Structure\Elf64\Elf64StringTable;
use Reli\Lib\Elf\Structure\Elf64\Elf64SymbolTable;
use Reli\Lib\Elf\Structure\Elf64\Elf64SymbolTableEntry;

final class Elf64ReverseSymbolResolver
{
    /** @var array<array{int, int, string}> sorted by address: [address, size, name] */
    private array $sortedSymbols = [];

    private function __construct()
    {
    }

    public static function fromSymbolTableAndStringTable(
        Elf64SymbolTable $symbolTable,
        Elf64StringTable $stringTable,
    ): self {
        $resolver = new self();
        $symbols = [];

        foreach ($symbolTable->findAll() as $entry) {
            if ($entry->isUndefined()) {
                continue;
            }
            $type = $entry->getType();
            if ($type !== Elf64SymbolTableEntry::STT_FUNC && $type !== Elf64SymbolTableEntry::STT_NOTYPE) {
                continue;
            }
            $address = $entry->st_value->toInt();
            $size = $entry->st_size->toInt();
            $name = $stringTable->lookup($entry->st_name);
            if ($name === '' || $address === 0) {
                continue;
            }
            $symbols[] = [$address, $size, $name];
        }

        // Sort by address
        usort(
            $symbols,
            /** @param array{int, int, string} $a @param array{int, int, string} $b */
            fn(array $a, array $b) => $a[0] <=> $b[0]
        );
        $resolver->sortedSymbols = $symbols;
        return $resolver;
    }

    public static function loadFromBinary(Elf64Parser $parser, ByteReaderInterface $data): ?self
    {
        try {
            $elf_header = $parser->parseElfHeader($data);
            if ($elf_header->e_shnum === 0 || $elf_header->e_shoff->toInt() === 0) {
                return self::loadDynamicOnly($parser, $data);
            }

            $section_headers = $parser->parseSectionHeader($data, $elf_header);

            // Try .symtab first (has more symbols)
            $symtab_entry = $section_headers->findSymbolTableEntry();
            $strtab_entry = $section_headers->findStringTableEntry();

            if ($symtab_entry !== null && $strtab_entry !== null) {
                $symbol_table = $parser->parseSymbolTableFromSectionHeader($data, $symtab_entry);
                $string_table = $parser->parseStringTableFromSectionHeader($data, $strtab_entry);
                return self::fromSymbolTableAndStringTable($symbol_table, $string_table);
            }

            // Fallback to .dynsym
            return self::loadDynamicOnly($parser, $data);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function loadDynamicOnly(Elf64Parser $parser, ByteReaderInterface $data): ?self
    {
        try {
            $elf_header = $parser->parseElfHeader($data);
            $program_header = $parser->parseProgramHeader($data, $elf_header);
            $base_address = $program_header->findBaseAddress();
            $dynamic_entries = $program_header->findDynamic();
            if ($dynamic_entries === []) {
                return null;
            }
            $dynamic_entry = $dynamic_entries[0];
            $dynamic_array = $parser->parseDynamicStructureArray(
                $data,
                $dynamic_entry->p_offset,
                $dynamic_entry->p_vaddr,
            );
            $string_table = $parser->parseStringTable($data, $base_address, $dynamic_array);
            $gnu_hash = $parser->parseGnuHashTable($data, $base_address, $dynamic_array);
            if ($gnu_hash === null) {
                return null;
            }
            $symbol_table = $parser->parseSymbolTableFromDynamic(
                $data,
                $base_address,
                $dynamic_array,
                $gnu_hash->getNumberOfSymbols(),
            );
            return self::fromSymbolTableAndStringTable($symbol_table, $string_table);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<array{int, int, string}> */
    public function toArray(): array
    {
        return $this->sortedSymbols;
    }

    /** @param array<array{int, int, string}> $symbols */
    public static function fromArray(array $symbols): self
    {
        $resolver = new self();
        $resolver->sortedSymbols = $symbols;
        return $resolver;
    }

    /**
     * @return array{string, int}|null [symbolName, offsetFromSymbolStart] or null
     */
    public function resolve(int $address): ?array
    {
        if ($this->sortedSymbols === []) {
            return null;
        }

        // Binary search for the nearest symbol whose address <= target
        $lo = 0;
        $hi = count($this->sortedSymbols) - 1;
        $candidate = null;

        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $sym_addr = $this->sortedSymbols[$mid][0];
            if ($sym_addr <= $address) {
                $candidate = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        if ($candidate === null) {
            return null;
        }

        [$sym_addr, $sym_size, $sym_name] = $this->sortedSymbols[$candidate];
        $offset = $address - $sym_addr;

        // If symbol has a size, check the address is within range
        if ($sym_size > 0 && $offset >= $sym_size) {
            return null;
        }

        // If no size info, accept if offset is reasonable (< 64KB)
        if ($sym_size === 0 && $offset > 0x10000) {
            return null;
        }

        return [$sym_name, $offset];
    }
}
