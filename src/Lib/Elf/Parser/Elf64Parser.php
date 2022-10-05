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

namespace Reli\Lib\Elf\Parser;

use Reli\Lib\ByteStream\IntegerByteSequence\IntegerByteSequenceReader;
use Reli\Lib\ByteStream\ByteReaderInterface;
use Reli\Lib\Elf\Structure\Elf64\Elf64DynamicStructure;
use Reli\Lib\Elf\Structure\Elf64\Elf64DynamicStructureArray;
use Reli\Lib\Elf\Structure\Elf64\Elf64GnuHashTable;
use Reli\Lib\Elf\Structure\Elf64\Elf64Header;
use Reli\Lib\Elf\Structure\Elf64\Elf64ProgramHeaderEntry;
use Reli\Lib\Elf\Structure\Elf64\Elf64ProgramHeaderTable;
use Reli\Lib\Elf\Structure\Elf64\Elf64SectionHeaderEntry;
use Reli\Lib\Elf\Structure\Elf64\Elf64SectionHeaderTable;
use Reli\Lib\Elf\Structure\Elf64\Elf64StringTable;
use Reli\Lib\Elf\Structure\Elf64\Elf64SymbolTable;
use Reli\Lib\Elf\Structure\Elf64\Elf64SymbolTableEntry;

final class Elf64Parser
{
    public function __construct(
        private IntegerByteSequenceReader $integer_reader
    ) {
    }

    public function parseElfHeader(ByteReaderInterface $data): Elf64Header
    {
        $e_ident = [
            $this->integer_reader->read8($data, 0),
            $this->integer_reader->read8($data, 1),
            $this->integer_reader->read8($data, 2),
            $this->integer_reader->read8($data, 3),
            $this->integer_reader->read8($data, 4),
            $this->integer_reader->read8($data, 5),
            $this->integer_reader->read8($data, 6),
            $this->integer_reader->read8($data, 7),
            $this->integer_reader->read8($data, 8),
            $this->integer_reader->read8($data, 9),
        ];
        $e_type = $this->integer_reader->read16($data, 16);
        $e_machine = $this->integer_reader->read16($data, 18);
        $e_version = $this->integer_reader->read32($data, 20);
        $e_entry = $this->integer_reader->read64($data, 24);
        $e_phoff = $this->integer_reader->read64($data, 32);
        $e_shoff = $this->integer_reader->read64($data, 40);
        $e_flags = $this->integer_reader->read32($data, 48);
        $e_ehsize = $this->integer_reader->read16($data, 52);
        $e_phentsize = $this->integer_reader->read16($data, 54);
        $e_phnum = $this->integer_reader->read16($data, 56);
        $e_shentsize = $this->integer_reader->read16($data, 58);
        $e_shnum = $this->integer_reader->read16($data, 60);
        $e_shstrndx = $this->integer_reader->read16($data, 62);

        return new Elf64Header(
            $e_ident,
            $e_type,
            $e_machine,
            $e_version,
            $e_entry,
            $e_phoff,
            $e_shoff,
            $e_flags,
            $e_ehsize,
            $e_phentsize,
            $e_phnum,
            $e_shentsize,
            $e_shnum,
            $e_shstrndx
        );
    }

    public function parseProgramHeader(ByteReaderInterface $data, Elf64Header $elf_header): Elf64ProgramHeaderTable
    {
        $program_header_table = [];

        for ($i = 0; $i < $elf_header->e_phnum; $i++) {
            $offset = $elf_header->e_phoff->toInt() + $elf_header->e_phentsize * $i;
            $p_type = $this->integer_reader->read32($data, $offset);
            $p_flags = $this->integer_reader->read32($data, $offset + 4);
            $p_offset = $this->integer_reader->read64($data, $offset + 8);
            $p_vaddr = $this->integer_reader->read64($data, $offset + 16);
            $p_paddr = $this->integer_reader->read64($data, $offset + 24);
            $p_filesz = $this->integer_reader->read64($data, $offset + 32);
            $p_memsz = $this->integer_reader->read64($data, $offset + 40);
            $p_align = $this->integer_reader->read64($data, $offset + 48);
            $program_header_table[] = new Elf64ProgramHeaderEntry(
                $p_type,
                $p_flags,
                $p_offset,
                $p_vaddr,
                $p_paddr,
                $p_filesz,
                $p_memsz,
                $p_align
            );
        }

        return new Elf64ProgramHeaderTable(...$program_header_table);
    }

    public function parseDynamicStructureArray(
        ByteReaderInterface $data,
        Elf64ProgramHeaderEntry $pt_dynamic
    ): Elf64DynamicStructureArray {
        $dynamic_array = [];
        $offset = $pt_dynamic->p_offset->lo;
        do {
            $d_tag = $this->integer_reader->read64($data, $offset);
            $d_un = $this->integer_reader->read64($data, $offset + 8);
            $dynamic_structure = new Elf64DynamicStructure($d_tag, $d_un);
            $dynamic_array[] = $dynamic_structure;
            $offset += 16;
        } while (!$dynamic_structure->isEnd());

        return new Elf64DynamicStructureArray(...$dynamic_array);
    }

    public function parseStringTable(
        ByteReaderInterface $data,
        Elf64DynamicStructureArray $dynamic_structure_array
    ): Elf64StringTable {
        /**
         * @var Elf64DynamicStructure $dt_strtab
         * @var Elf64DynamicStructure $dt_strsz
         */
        [
            Elf64DynamicStructure::DT_STRTAB => $dt_strtab,
            Elf64DynamicStructure::DT_STRSZ => $dt_strsz
        ] = $dynamic_structure_array->findStringTableEntries();
        $offset = $dt_strtab->d_un->toInt();
        $size = $dt_strsz->d_un->toInt();
        $string_table_region = $data->createSliceAsString($offset, $size);

        return new Elf64StringTable($string_table_region);
    }

    public function parseStringTableFromSectionHeader(
        ByteReaderInterface $data,
        Elf64SectionHeaderEntry $section_header_entry
    ): Elf64StringTable {
        $string_table_region = $data->createSliceAsString(
            $section_header_entry->sh_offset->toInt(),
            $section_header_entry->sh_size->toInt()
        );

        return new Elf64StringTable($string_table_region);
    }

    public function parseSymbolTableFromDynamic(
        ByteReaderInterface $data,
        Elf64DynamicStructureArray $dynamic_structure_array,
        int $number_of_symbols
    ): Elf64SymbolTable {
        /**
         * @var Elf64DynamicStructure $dt_symtab
         * @var Elf64DynamicStructure $dt_syment
         */
        [
            Elf64DynamicStructure::DT_SYMTAB => $dt_symtab,
            Elf64DynamicStructure::DT_SYMENT => $dt_syment
        ] = $dynamic_structure_array->findSymbolTablEntries();

        $start_offset = $dt_symtab->d_un->toInt();
        $entry_size = $dt_syment->d_un->toInt();

        return $this->parseSymbolTable($data, $start_offset, $number_of_symbols, $entry_size);
    }

    public function parseSymbolTableFromSectionHeader(
        ByteReaderInterface $data,
        Elf64SectionHeaderEntry $section
    ): Elf64SymbolTable {
        return $this->parseSymbolTable(
            $data,
            $section->sh_offset->toInt(),
            (int)($section->sh_size->toInt() / $section->sh_entsize->toInt()),
            $section->sh_entsize->toInt()
        );
    }

    public function parseSymbolTable(
        ByteReaderInterface $data,
        int $start_offset,
        int $number_of_symbols,
        int $entry_size
    ): Elf64SymbolTable {
        $symbol_table_array = [];
        for ($i = 0; $i < $number_of_symbols; $i++) {
            $offset = $start_offset + $i * $entry_size;
            $st_name = $this->integer_reader->read32($data, $offset);
            $st_info = $this->integer_reader->read8($data, $offset + 4);
            $st_other = $this->integer_reader->read8($data, $offset + 5);
            $st_shndx = $this->integer_reader->read16($data, $offset + 6);
            $st_value = $this->integer_reader->read64($data, $offset + 8);
            $st_size = $this->integer_reader->read64($data, $offset + 16);
            $symbol_table_array[] = new Elf64SymbolTableEntry(
                $st_name,
                $st_info,
                $st_other,
                $st_shndx,
                $st_value,
                $st_size
            );
        }
        return new Elf64SymbolTable(...$symbol_table_array);
    }

    /**
     * @param ByteReaderInterface $data
     * @param Elf64DynamicStructureArray $dynamic_structure_array
     * @return Elf64GnuHashTable|null
     */
    public function parseGnuHashTable(
        ByteReaderInterface $data,
        Elf64DynamicStructureArray $dynamic_structure_array
    ): ?Elf64GnuHashTable {
        $dt_gnu_hash = $dynamic_structure_array->findGnuHashTableEntry();
        if (is_null($dt_gnu_hash)) {
            return null;
        }
        $offset = $dt_gnu_hash->d_un->toInt();
        $nbuckets = $this->integer_reader->read32($data, $offset);
        $symoffset = $this->integer_reader->read32($data, $offset + 4);
        $bloom_size = $this->integer_reader->read32($data, $offset + 8);
        $bloom_shift = $this->integer_reader->read32($data, $offset + 12);
        $bloom_offset = $offset + 16;
        $bloom = [];
        for ($i = 0; $i < $bloom_size; $i++) {
            $bloom[] = $this->integer_reader->read64($data, $bloom_offset + $i * 8);
        }
        $buckets_offset = $offset + 16 + $bloom_size * 8;
        $buckets = [];
        for ($i = 0; $i < $nbuckets; $i++) {
            $buckets[] = $this->integer_reader->read32($data, $buckets_offset + $i * 4);
        }

        $chain_offset = $offset + 16 + $bloom_size * 8 + $nbuckets * 4;

        if ($buckets === []) {
            return null;
        }

        $max_bucket_index = max($buckets);
        $last_chain_offset = $chain_offset + ($max_bucket_index - $symoffset) * 4;
        $last_chain_item = $this->integer_reader->read32($data, $last_chain_offset);
        for (; ($last_chain_item & 1) === 0; $last_chain_offset += 4) {
            $last_chain_item = $this->integer_reader->read32($data, $last_chain_offset);
        }

        $chain = [];
        for (; $chain_offset <= $last_chain_offset; $chain_offset += 4) {
            $chain[] = $this->integer_reader->read32($data, $chain_offset);
        }

        return new Elf64GnuHashTable(
            $nbuckets,
            $symoffset,
            $bloom_size,
            $bloom_shift,
            $bloom,
            $buckets,
            $chain
        );
    }

    public function parseSectionHeader(ByteReaderInterface $data, Elf64Header $elf_header): Elf64SectionHeaderTable
    {
        $section_header_array = [];

        $offset = $elf_header->e_shoff->toInt();
        for ($i = 0; $i < $elf_header->e_shnum; $i++) {
            $sh_name = $this->integer_reader->read32($data, $offset);
            $sh_type = $this->integer_reader->read32($data, $offset + 4);
            $sh_flags = $this->integer_reader->read64($data, $offset + 8);
            $sh_addr = $this->integer_reader->read64($data, $offset + 16);
            $sh_offset = $this->integer_reader->read64($data, $offset + 24);
            $sh_size = $this->integer_reader->read64($data, $offset + 32);
            $sh_link = $this->integer_reader->read32($data, $offset + 40);
            $sh_info = $this->integer_reader->read32($data, $offset + 44);
            $sh_addralign = $this->integer_reader->read64($data, $offset + 48);
            $sh_entsize = $this->integer_reader->read64($data, $offset + 56);
            $section_header_array[] = new Elf64SectionHeaderEntry(
                $sh_name,
                $sh_type,
                $sh_flags,
                $sh_addr,
                $sh_offset,
                $sh_size,
                $sh_link,
                $sh_info,
                $sh_addralign,
                $sh_entsize
            );

            $offset += $elf_header->e_shentsize;
        }

        return new Elf64SectionHeaderTable(
            $this->parseStringTableFromSectionHeader(
                $data,
                $section_header_array[$elf_header->e_shstrndx]
            ),
            ...$section_header_array
        );
    }
}
