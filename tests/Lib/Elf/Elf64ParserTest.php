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
use PhpProfiler\ProcessReader\PhpBinaryFinder;
use PHPUnit\Framework\TestCase;

class Elf64ParserTest extends TestCase
{

    public function testParseElfHeader()
    {
        $parser = new Elf64Parser(new BinaryReader());
        $php_binary = file_get_contents((new PhpBinaryFinder())->findByProcessId(getmypid()));
        $elf_header = $parser->parseElfHeader($php_binary);
        var_dump($elf_header);
    }

    public function testParseProgramHeader()
    {
        $parser = new Elf64Parser(new BinaryReader());
        $php_binary = file_get_contents((new PhpBinaryFinder())->findByProcessId(getmypid()));
        $elf_header = $parser->parseElfHeader($php_binary);
        $program_header_table = $parser->parseProgramHeader($php_binary, $elf_header);
        var_dump($program_header_table);
    }

    public function testParseDynamicArray()
    {
        $parser = new Elf64Parser(new BinaryReader());
        $php_binary = file_get_contents((new PhpBinaryFinder())->findByProcessId(getmypid()));
        $elf_header = $parser->parseElfHeader($php_binary);
        $program_header_table = $parser->parseProgramHeader($php_binary, $elf_header);
        $dynamic_array = $parser->parseDynamicStructureArray($php_binary, $program_header_table->findDynamic()[0]);
        foreach ($dynamic_array->findAll() as $item) {
            echo $item->d_tag . "\n";
        }
    }

    public function testParseStringTable()
    {
        $parser = new Elf64Parser(new BinaryReader());
        $php_binary = file_get_contents((new PhpBinaryFinder())->findByProcessId(getmypid()));
        $elf_header = $parser->parseElfHeader($php_binary);
        $program_header_table = $parser->parseProgramHeader($php_binary, $elf_header);
        $dynamic_array = $parser->parseDynamicStructureArray($php_binary, $program_header_table->findDynamic()[0]);
        $string_table = $parser->parseStringTable($php_binary, $dynamic_array);
        var_dump($string_table);
    }

    public function testParseGnuHashTable()
    {
        $parser = new Elf64Parser(new BinaryReader());
        $php_binary = file_get_contents((new PhpBinaryFinder())->findByProcessId(getmypid()));
        $elf_header = $parser->parseElfHeader($php_binary);
        $program_header_table = $parser->parseProgramHeader($php_binary, $elf_header);
        $dynamic_array = $parser->parseDynamicStructureArray($php_binary, $program_header_table->findDynamic()[0]);
        $gnu_hash_table = $parser->parseGnuHashTable($php_binary, $dynamic_array);
        $number_of_symbols = $gnu_hash_table->getNumberOfSymbols();
        $index = $gnu_hash_table->lookup('zend_class_implements', fn ($unused) => true);
        $symbol_table_array = $parser->parseSymbolTable($php_binary, $dynamic_array, $number_of_symbols)->entries;
        $string_table = $parser->parseStringTable($php_binary, $dynamic_array);
        $this->assertSame('zend_class_implements', $string_table->lookup($symbol_table_array[$index]->st_name));
    }

    public function testParseSymbolTable()
    {
        $parser = new Elf64Parser(new BinaryReader());
        $php_binary = file_get_contents((new PhpBinaryFinder())->findByProcessId(getmypid()));
        $elf_header = $parser->parseElfHeader($php_binary);
        $program_header_table = $parser->parseProgramHeader($php_binary, $elf_header);
        $dynamic_array = $parser->parseDynamicStructureArray($php_binary, $program_header_table->findDynamic()[0]);
        $symbol_table_array = $parser->parseSymbolTable($php_binary, $dynamic_array, 2000);
        $string_table = $parser->parseStringTable($php_binary, $dynamic_array);

        foreach ($symbol_table_array as $index => $symbol_table_entry) {
            echo $string_table->lookup($symbol_table_entry->st_name) . "\n";
            echo $index. "\n";
            var_dump($symbol_table_entry);
        }
    }

    public function testParseSectionHeader()
    {
        $parser = new Elf64Parser(new BinaryReader());
        $php_binary = file_get_contents((new PhpBinaryFinder())->findByProcessId(getmypid()));
        $elf_header = $parser->parseElfHeader($php_binary);
        $section_header = $parser->parseSectionHeader($php_binary, $elf_header);
        var_dump($section_header->findSymbolTableEntry());
    }
}
