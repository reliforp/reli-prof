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

namespace PhpProfiler\Lib\Elf\Parser;

use PhpProfiler\Lib\Binary\IntegerByteSequence\LittleEndianReader;
use PhpProfiler\Lib\Binary\StringByteReader;
use PhpProfiler\Lib\Elf\Structure\Elf64\Elf64DynamicStructure;
use PhpProfiler\Lib\Elf\Structure\Elf64\Elf64Header;
use PhpProfiler\Lib\Elf\Structure\Elf64\Elf64ProgramHeaderEntry;
use PhpProfiler\Lib\Elf\Structure\Elf64\Elf64SectionHeaderEntry;
use PHPUnit\Framework\TestCase;

class Elf64ParserTest extends TestCase
{
    public function testParseElfHeader()
    {
        $parser = new Elf64Parser(new LittleEndianReader());
        $test_binary = $this->getTestBinary('test000.so');
        $elf_header = $parser->parseElfHeader($test_binary);

        $this->assertSame(0x7f, $elf_header->e_ident[Elf64Header::EI_MAG0]);
        $this->assertSame(ord('E'), $elf_header->e_ident[Elf64Header::EI_MAG1]);
        $this->assertSame(ord('L'), $elf_header->e_ident[Elf64Header::EI_MAG2]);
        $this->assertSame(ord('F'), $elf_header->e_ident[Elf64Header::EI_MAG3]);
        $this->assertSame(Elf64Header::ELFCLASS64, $elf_header->e_ident[Elf64Header::EI_CLASS]);
        $this->assertSame(Elf64Header::ELFDATA2LSB, $elf_header->e_ident[Elf64Header::EI_DATA]);
        $this->assertSame(Elf64Header::EV_CURRENT, $elf_header->e_ident[Elf64Header::EI_VERSION]);
        $this->assertSame(Elf64Header::ELFOSABI_NONE, $elf_header->e_ident[Elf64Header::EI_OSABI]);
        $this->assertSame(0, $elf_header->e_ident[Elf64Header::EI_ABIVERSION]);
        $this->assertSame(Elf64Header::ET_DYN, $elf_header->e_type);
        $this->assertSame(Elf64Header::EM_X86_64, $elf_header->e_machine);
        $this->assertSame(Elf64Header::EV_CURRENT, $elf_header->e_version);
        $this->assertSame(4096, $elf_header->e_entry->toInt());
        $this->assertSame(64, $elf_header->e_phoff->toInt());
        $this->assertSame(12752, $elf_header->e_shoff->toInt());
        $this->assertSame(0, $elf_header->e_flags);
        $this->assertSame(64, $elf_header->e_ehsize);
        $this->assertSame(56, $elf_header->e_phentsize);
        $this->assertSame(7, $elf_header->e_phnum);
        $this->assertSame(64, $elf_header->e_shentsize);
        $this->assertSame(12, $elf_header->e_shnum);
        $this->assertSame(11, $elf_header->e_shstrndx);
    }

    public function testParseProgramHeader()
    {
        $parser = new Elf64Parser(new LittleEndianReader());
        $test_binary = $this->getTestBinary('test000.so');
        $elf_header = $parser->parseElfHeader($test_binary);
        $program_header_table = $parser->parseProgramHeader($test_binary, $elf_header);
        $this->assertSame(0, $program_header_table->findBaseAddress()->toInt());
        $load_segments = $program_header_table->findLoad();

        $this->assertCount(4, $load_segments);

        $this->assertSame(Elf64ProgramHeaderEntry::PT_LOAD, $load_segments[0]->p_type);
        $this->assertSame(Elf64ProgramHeaderEntry::PF_R, $load_segments[0]->p_flags);
        $this->assertSame(0, $load_segments[0]->p_offset->toInt());
        $this->assertSame(0, $load_segments[0]->p_paddr->toInt());
        $this->assertSame(0, $load_segments[0]->p_vaddr->toInt());
        $this->assertSame(574, $load_segments[0]->p_filesz->toInt());
        $this->assertSame(574, $load_segments[0]->p_memsz->toInt());
        $this->assertSame(4096, $load_segments[0]->p_align->toInt());

        $this->assertSame(Elf64ProgramHeaderEntry::PT_LOAD, $load_segments[1]->p_type);
        $this->assertSame(
            Elf64ProgramHeaderEntry::PF_R | Elf64ProgramHeaderEntry::PF_X,
            $load_segments[1]->p_flags
        );
        $this->assertSame(4096, $load_segments[1]->p_offset->toInt());
        $this->assertSame(4096, $load_segments[1]->p_paddr->toInt());
        $this->assertSame(4096, $load_segments[1]->p_vaddr->toInt());
        $this->assertSame(11, $load_segments[1]->p_filesz->toInt());
        $this->assertSame(11, $load_segments[1]->p_memsz->toInt());
        $this->assertSame(4096, $load_segments[1]->p_align->toInt());

        $this->assertSame(Elf64ProgramHeaderEntry::PT_LOAD, $load_segments[2]->p_type);
        $this->assertSame(
            Elf64ProgramHeaderEntry::PF_R,
            $load_segments[2]->p_flags
        );
        $this->assertSame(8192, $load_segments[2]->p_offset->toInt());
        $this->assertSame(8192, $load_segments[2]->p_paddr->toInt());
        $this->assertSame(8192, $load_segments[2]->p_vaddr->toInt());
        $this->assertSame(56, $load_segments[2]->p_filesz->toInt());
        $this->assertSame(56, $load_segments[2]->p_memsz->toInt());
        $this->assertSame(4096, $load_segments[2]->p_align->toInt());

        $this->assertSame(Elf64ProgramHeaderEntry::PT_LOAD, $load_segments[3]->p_type);
        $this->assertSame(
            Elf64ProgramHeaderEntry::PF_R | Elf64ProgramHeaderEntry::PF_W,
            $load_segments[3]->p_flags
        );
        $this->assertSame(12096, $load_segments[3]->p_offset->toInt());
        $this->assertSame(16192, $load_segments[3]->p_paddr->toInt());
        $this->assertSame(16192, $load_segments[3]->p_vaddr->toInt());
        $this->assertSame(192, $load_segments[3]->p_filesz->toInt());
        $this->assertSame(192, $load_segments[3]->p_memsz->toInt());
        $this->assertSame(4096, $load_segments[3]->p_align->toInt());

        $dynamic_indicator = $program_header_table->findDynamic();
        $this->assertCount(1, $dynamic_indicator);
        $this->assertSame(Elf64ProgramHeaderEntry::PT_DYNAMIC, $dynamic_indicator[0]->p_type);
        $this->assertSame(
            Elf64ProgramHeaderEntry::PF_R | Elf64ProgramHeaderEntry::PF_W,
            $dynamic_indicator[0]->p_flags
        );
        $this->assertSame(12096, $dynamic_indicator[0]->p_offset->toInt());
        $this->assertSame(16192, $dynamic_indicator[0]->p_paddr->toInt());
        $this->assertSame(16192, $dynamic_indicator[0]->p_vaddr->toInt());
        $this->assertSame(192, $dynamic_indicator[0]->p_filesz->toInt());
        $this->assertSame(192, $dynamic_indicator[0]->p_memsz->toInt());
        $this->assertSame(8, $dynamic_indicator[0]->p_align->toInt());
    }

    public function testParseDynamicArray()
    {
        $parser = new Elf64Parser(new LittleEndianReader());
        $test_binary = $this->getTestBinary('test000.so');
        $elf_header = $parser->parseElfHeader($test_binary);
        $program_header_table = $parser->parseProgramHeader($test_binary, $elf_header);
        $dynamic_array = $parser->parseDynamicStructureArray($test_binary, $program_header_table->findDynamic()[0]);
        $this->assertCount(7, $dynamic_array->findAll());

        $actual = [];
        foreach ($dynamic_array->findAll() as $item) {
            $actual[] = [$item->d_tag->toInt(), $item->d_un->toInt()];
        }
        $this->assertSame(
            [
                [Elf64DynamicStructure::DT_HASH, 456],
                [Elf64DynamicStructure::DT_GNU_HASH, 480],
                [Elf64DynamicStructure::DT_STRTAB, 568],
                [Elf64DynamicStructure::DT_SYMTAB, 520],
                [Elf64DynamicStructure::DT_STRSZ, 6],
                [Elf64DynamicStructure::DT_SYMENT, 24],
                [Elf64DynamicStructure::DT_NULL, 0],
            ],
            $actual
        );
    }

    public function testParseStringTable()
    {
        $parser = new Elf64Parser(new LittleEndianReader());
        $test_binary = $this->getTestBinary('test000.so');
        $elf_header = $parser->parseElfHeader($test_binary);
        $program_header_table = $parser->parseProgramHeader($test_binary, $elf_header);
        $dynamic_array = $parser->parseDynamicStructureArray($test_binary, $program_header_table->findDynamic()[0]);
        $string_table = $parser->parseStringTable($test_binary, $dynamic_array);
        $this->assertSame(
            'main',
            $string_table->lookup(1)
        );
    }

    public function testParseGnuHashTable()
    {
        $parser = new Elf64Parser(new LittleEndianReader());
        $test_binary = $this->getTestBinary('test000.so');
        $elf_header = $parser->parseElfHeader($test_binary);
        $program_header_table = $parser->parseProgramHeader($test_binary, $elf_header);
        $dynamic_array = $parser->parseDynamicStructureArray($test_binary, $program_header_table->findDynamic()[0]);
        $gnu_hash_table = $parser->parseGnuHashTable($test_binary, $dynamic_array);
        $this->assertNotNull($gnu_hash_table);
        $this->assertSame(2, $gnu_hash_table->getNumberOfSymbols());
        $index = $gnu_hash_table->lookup('main', fn ($unused) => true);
        $this->assertSame(
            1,
            $index
        );
    }

    public function testParseSymbolTable()
    {
        $parser = new Elf64Parser(new LittleEndianReader());
        $test_binary = $this->getTestBinary('test000.so');
        $elf_header = $parser->parseElfHeader($test_binary);
        $program_header_table = $parser->parseProgramHeader($test_binary, $elf_header);
        $dynamic_array = $parser->parseDynamicStructureArray($test_binary, $program_header_table->findDynamic()[0]);
        $gnu_hash_table = $parser->parseGnuHashTable($test_binary, $dynamic_array);
        $number_of_symbols = $gnu_hash_table->getNumberOfSymbols();
        $symbol_table = $parser->parseSymbolTableFromDynamic(
            $test_binary,
            $dynamic_array,
            $number_of_symbols
        );
        $all_symbols = $symbol_table->findAll();
        $this->assertCount(2, $symbol_table->findAll());
        $this->assertSame(0, $all_symbols[0]->st_name);
        $this->assertSame(0, $all_symbols[0]->st_info);
        $this->assertSame(0, $all_symbols[0]->st_other);
        $this->assertSame(0, $all_symbols[0]->st_shndx);
        $this->assertSame(0, $all_symbols[0]->st_value->toInt());
        $this->assertSame(0, $all_symbols[0]->st_size->toInt());

        $string_table = $parser->parseStringTable($test_binary, $dynamic_array);
        $index = $gnu_hash_table->lookup('main', fn ($unused) => true);
        $main_symbol = $symbol_table->lookup($index);
        $this->assertSame($all_symbols[1], $main_symbol);
        $this->assertSame('main', $string_table->lookup($main_symbol->st_name));
        $this->assertSame(18, $main_symbol->st_info);
        $this->assertSame(0, $main_symbol->st_other);
        $this->assertSame(5, $main_symbol->st_shndx);
        $this->assertSame(4096, $main_symbol->st_value->toInt());
        $this->assertSame(11, $main_symbol->st_size->toInt());
    }

    public function testParseSectionHeader()
    {
        $parser = new Elf64Parser(new LittleEndianReader());
        $test_binary = $this->getTestBinary('test000.so');
        $elf_header = $parser->parseElfHeader($test_binary);
        $section_header = $parser->parseSectionHeader($test_binary, $elf_header);
        $section_header_symbol_table_entry = $section_header->findSymbolTableEntry();
        $this->assertSame(1, $section_header_symbol_table_entry->sh_name);
        $this->assertSame(Elf64SectionHeaderEntry::SHT_SYMTAB, $section_header_symbol_table_entry->sh_type);
        $this->assertSame(0, $section_header_symbol_table_entry->sh_flags->toInt());
        $this->assertSame(0, $section_header_symbol_table_entry->sh_addr->toInt());
        $this->assertSame(12328, $section_header_symbol_table_entry->sh_offset->toInt());
        $this->assertSame(312, $section_header_symbol_table_entry->sh_size->toInt());
        $this->assertSame(10, $section_header_symbol_table_entry->sh_link);
        $this->assertSame(12, $section_header_symbol_table_entry->sh_info);
        $this->assertSame(8, $section_header_symbol_table_entry->sh_addralign->toInt());
        $this->assertSame(24, $section_header_symbol_table_entry->sh_entsize->toInt());

        $symbol_table = $parser->parseSymbolTableFromSectionHeader(
            $test_binary,
            $section_header_symbol_table_entry
        );
        $string_table = $parser->parseStringTableFromSectionHeader(
            $test_binary,
            $section_header->findStringTableEntry()
        );

        $all_symbols = $symbol_table->findAll();
        $this->assertCount(13, $all_symbols);
        $this->assertSame(
            'test000.c',
            $string_table->lookup($symbol_table->lookup(9)->st_name)
        );
        $this->assertSame(
            '_DYNAMIC',
            $string_table->lookup($symbol_table->lookup(11)->st_name)
        );
        $this->assertSame(
            'main',
            $string_table->lookup($symbol_table->lookup(12)->st_name)
        );
    }

    /**
     * @param string $name
     * @return StringByteReader
     */
    public function getTestBinary(string $name): StringByteReader
    {
        return new StringByteReader(
            file_get_contents(__DIR__ . "/TestCase/{$name}")
        );
    }
}
