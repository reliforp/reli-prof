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


use PhpProfiler\Lib\Binary\BinaryReader;

/**
 * Class Elf64Parser
 * @package PhpProfiler\Lib\Elf
 */
class Elf64Parser
{
    /**
     * @var BinaryReader
     */
    private BinaryReader $binary_reader;

    /**
     * Elf64Parser constructor.
     * @param BinaryReader $binary_reader
     */
    public function __construct(BinaryReader $binary_reader)
    {
        $this->binary_reader = $binary_reader;
    }

    /**
     * @param string $data
     * @return Elf64Header
     */
    public function parseElfHeader(string $data): Elf64Header
    {
        $header = new Elf64Header();
        $header->e_ident = [
            $this->binary_reader->read8($data, 0),
            $this->binary_reader->read8($data, 1),
            $this->binary_reader->read8($data, 2),
            $this->binary_reader->read8($data, 3),
            $this->binary_reader->read8($data, 4),
            $this->binary_reader->read8($data, 5),
            $this->binary_reader->read8($data, 6),
            $this->binary_reader->read8($data, 7),
            $this->binary_reader->read8($data, 8),
            $this->binary_reader->read8($data, 9),
        ];
        $header->e_type = $this->binary_reader->read16($data, 16);
        $header->e_machine = $this->binary_reader->read16($data, 18);
        $header->e_version = $this->binary_reader->read32($data, 20);
        $header->e_entry = $this->binary_reader->read64($data, 24);
        $header->e_phoff = $this->binary_reader->read64($data, 32);
        $header->e_shoff = $this->binary_reader->read64($data, 40);
        $header->e_flags = $this->binary_reader->read32($data, 48);
        $header->e_ehsize = $this->binary_reader->read16($data, 52);
        $header->e_phentsize = $this->binary_reader->read16($data, 54);
        $header->e_phnum = $this->binary_reader->read16($data, 56);
        $header->e_shentsize = $this->binary_reader->read16($data, 58);
        $header->e_shnum = $this->binary_reader->read16($data, 60);
        $header->e_shstrndx = $this->binary_reader->read16($data, 62);
        return $header;
    }

    /**
     * @param string $data
     * @param Elf64Header $elf_header
     * @return Elf64ProgramHeaderEntry[]
     */
    public function parseProgramHeader(string $data, Elf64Header $elf_header): Elf64ProgramHeaderTable
    {
        $program_header_table = [];

        for ($i = 0; $i < $elf_header->e_phnum; $i++) {
            $program_header = new Elf64ProgramHeaderEntry();
            // ToDo: handle 64 bit offset correctly
            $offset = $elf_header->e_phoff->lo + $elf_header->e_phentsize * $i;
            $program_header->p_type = $this->binary_reader->read32($data, $offset);
            $program_header->p_flags = $this->binary_reader->read32($data, $offset + 4);
            $program_header->p_offset = $this->binary_reader->read64($data, $offset + 8);
            $program_header->p_vaddr = $this->binary_reader->read64($data, $offset + 16);
            $program_header->p_paddr = $this->binary_reader->read64($data, $offset + 24);
            $program_header->p_filesz = $this->binary_reader->read64($data, $offset + 32);
            $program_header->p_memsz = $this->binary_reader->read64($data, $offset + 40);
            $program_header->p_align = $this->binary_reader->read64($data, $offset + 48);
            $program_header_table[] = $program_header;
        }

        return new Elf64ProgramHeaderTable(...$program_header_table);
    }

    /**
     * @param string $data
     * @param Elf64ProgramHeaderEntry $pt_dynamic
     * @return Elf64DynamicStructureArray
     */
    public function parseDynamicStructureArray(string $data, Elf64ProgramHeaderEntry $pt_dynamic): Elf64DynamicStructureArray
    {
        $dynamic_array = [];
        $offset = $pt_dynamic->p_offset->lo;
        do {
            $dynamic_structure = new Elf64DynamicStructure();
            $dynamic_structure->d_tag = $this->binary_reader->read64($data, $offset);
            $dynamic_structure->d_un = $this->binary_reader->read64($data, $offset + 8);
            $dynamic_array[] = $dynamic_structure;
            $offset += 16;
        } while (!$dynamic_structure->isEnd());

        return new Elf64DynamicStructureArray(...$dynamic_array);
    }

    /**
     * @param string $data
     * @param Elf64DynamicStructureArray $dynamic_structure_array
     * @return Elf64StringTable
     */
    public function parseStringTable(string $data, Elf64DynamicStructureArray $dynamic_structure_array): Elf64StringTable
    {
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
        $string_table_region = substr($data, $offset, $size);

        return new Elf64StringTable($string_table_region);
    }

    /**
     * @param string $data
     * @param Elf64DynamicStructureArray $dynamic_structure_array
     * @param int $number_of_symbols
     * @return Elf64SymbolTable
     */
    public function parseSymbolTableFromDynamic(string $data, Elf64DynamicStructureArray $dynamic_structure_array, int $number_of_symbols): Elf64SymbolTable
    {
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

    /**
     * @param string $data
     * @param Elf64SectionHeaderEntry $section
     * @return Elf64SymbolTable
     */
    public function parseSymbolTableFromSectionHeader(string $data, Elf64SectionHeaderEntry $section): Elf64SymbolTable
    {
        return $this->parseSymbolTable(
            $data,
            $section->sh_offset->toInt(),
            $section->sh_size->toInt() / $section->sh_entsize->toInt(),
            $section->sh_entsize->toInt()
        );
    }

    /**
     * @param string $data
     * @param int $start_offset
     * @param int $number_of_symbols
     * @param int $entry_size
     * @return Elf64SymbolTable
     */
    public function parseSymbolTable(string $data, int $start_offset, int $number_of_symbols, int $entry_size): Elf64SymbolTable
    {
        $symbol_table_array = [];
        for ($i = 0; $i < $number_of_symbols; $i++) {
            $offset = $start_offset + $i * $entry_size;
            $symbol_table_entry = new Elf64SymbolTableEntry();
            $symbol_table_entry->st_name = $this->binary_reader->read32($data, $offset);
            $symbol_table_entry->st_info = $this->binary_reader->read8($data, $offset + 4);
            $symbol_table_entry->st_other = $this->binary_reader->read8($data, $offset + 5);
            $symbol_table_entry->st_shndx = $this->binary_reader->read16($data, $offset + 6);
            $symbol_table_entry->st_value = $this->binary_reader->read64($data, $offset + 8);
            $symbol_table_entry->st_size = $this->binary_reader->read64($data, $offset + 16);
            $symbol_table_array[] = $symbol_table_entry;
        }
        return new Elf64SymbolTable(...$symbol_table_array);
    }

    /**
     * @param string $data
     * @param Elf64DynamicStructureArray $dynamic_structure_array
     * @return Elf64GnuHashTable|null
     */
    public function parseGnuHashTable(string $data, Elf64DynamicStructureArray $dynamic_structure_array): ?Elf64GnuHashTable
    {
        $dt_gnu_hash = $dynamic_structure_array->findGnuHashTableEntry();
        if (is_null($dt_gnu_hash)) {
            return null;
        }
        $offset = $dt_gnu_hash->d_un->toInt();
        $gnu_hash_table = new Elf64GnuHashTable();
        $gnu_hash_table->nbuckets = $this->binary_reader->read32($data, $offset);
        $gnu_hash_table->symoffset = $this->binary_reader->read32($data, $offset + 4);
        $gnu_hash_table->bloom_size = $this->binary_reader->read32($data, $offset + 8);
        $gnu_hash_table->bloom_shift = $this->binary_reader->read32($data, $offset + 12);
        $bloom_offset = $offset + 16;
        $bloom = [];
        for ($i = 0; $i < $gnu_hash_table->bloom_size; $i++) {
            $bloom[] = $this->binary_reader->read64($data, $bloom_offset + $i * 8);
        }
        $gnu_hash_table->bloom = $bloom;
        $buckets_offset = $offset + 16 + $gnu_hash_table->bloom_size * 8;
        $buckets = [];
        for ($i = 0; $i < $gnu_hash_table->nbuckets; $i++) {
            $buckets[] = $this->binary_reader->read32($data, $buckets_offset + $i * 4);
        }
        $gnu_hash_table->buckets = $buckets;

        $chain_offset = $offset + 16 + $gnu_hash_table->bloom_size * 8 + $gnu_hash_table->nbuckets * 4;

        $max_bucket_index = max($buckets);
        $last_chain_offset = $chain_offset + ($max_bucket_index - $gnu_hash_table->symoffset) * 4;
        $last_chain_item = $this->binary_reader->read32($data, $last_chain_offset);
        for (; $last_chain_item & 1 === 0; $last_chain_offset += 4) {
            $last_chain_item = $this->binary_reader->read32($data, $last_chain_offset);
        }

        $chain = [];
        for (; $chain_offset <= $last_chain_offset; $chain_offset += 4) {
            $chain[] = $this->binary_reader->read32($data, $chain_offset);
        }
        $gnu_hash_table->chain = $chain;

        return $gnu_hash_table;
    }

    /**
     * @param string $data
     * @param Elf64Header $elf_header
     * @return Elf64SectionHeaderTable
     */
    public function parseSectionHeader(string $data, Elf64Header $elf_header): Elf64SectionHeaderTable
    {
        $section_header_array = [];

        $offset = $elf_header->e_shoff->toInt();
        for ($i = 0; $i < $elf_header->e_shnum; $i++) {
            $section_header_entry = new Elf64SectionHeaderEntry();
            $section_header_entry->sh_name = $this->binary_reader->read32($data, $offset);
            $section_header_entry->sh_type = $this->binary_reader->read32($data, $offset + 4);
            $section_header_entry->sh_flags = $this->binary_reader->read64($data, $offset + 8);
            $section_header_entry->sh_addr = $this->binary_reader->read64($data, $offset + 16);
            $section_header_entry->sh_offset = $this->binary_reader->read64($data, $offset + 24);
            $section_header_entry->sh_size = $this->binary_reader->read64($data, $offset + 32);
            $section_header_entry->sh_link = $this->binary_reader->read32($data, $offset + 40);
            $section_header_entry->sh_info = $this->binary_reader->read32($data, $offset + 44);
            $section_header_entry->sh_addralign = $this->binary_reader->read64($data, $offset + 48);
            $section_header_entry->sh_entsize = $this->binary_reader->read64($data, $offset + 56);
            $section_header_array[] = $section_header_entry;

            $offset += $elf_header->e_shentsize;
        }

        return new Elf64SectionHeaderTable(...$section_header_array);
    }
}