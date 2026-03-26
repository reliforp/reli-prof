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

use Reli\BaseTestCase;
use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\ByteStream\StringByteReader;
use Reli\Lib\Elf\Parser\Elf64Parser;
use Reli\Lib\Elf\Structure\Elf64\Elf64StringTable;
use Reli\Lib\Elf\Structure\Elf64\Elf64SymbolTable;
use Reli\Lib\Elf\Structure\Elf64\Elf64SymbolTableEntry;
use Reli\Lib\Integer\UInt64;

class Elf64ReverseSymbolResolverTest extends BaseTestCase
{
    private function makeEntry(
        int $name_offset,
        int $addr,
        int $size,
        int $type = Elf64SymbolTableEntry::STT_FUNC,
    ): Elf64SymbolTableEntry {
        return new Elf64SymbolTableEntry(
            $name_offset, // st_name
            Elf64SymbolTableEntry::createInfo(Elf64SymbolTableEntry::STB_GLOBAL, $type),
            0,
            1,  // valid section
            new UInt64(0, $addr),
            new UInt64(0, $size),
        );
    }

    public function testResolveExact(): void
    {
        $string_data = "\0func_a\0func_b\0";
        $string_table = new Elf64StringTable($string_data);
        $symbol_table = new Elf64SymbolTable(
            $this->makeEntry(1, 0x1000, 0x100),  // func_a at 0x1000, size 0x100
            $this->makeEntry(8, 0x2000, 0x80),    // func_b at 0x2000, size 0x80
        );

        $resolver = Elf64ReverseSymbolResolver::fromSymbolTableAndStringTable($symbol_table, $string_table);

        $result = $resolver->resolve(0x1000);
        $this->assertNotNull($result);
        $this->assertSame('func_a', $result[0]);
        $this->assertSame(0, $result[1]);
    }

    public function testResolveWithOffset(): void
    {
        $string_data = "\0func_a\0";
        $string_table = new Elf64StringTable($string_data);
        $symbol_table = new Elf64SymbolTable(
            $this->makeEntry(1, 0x1000, 0x100),
        );

        $resolver = Elf64ReverseSymbolResolver::fromSymbolTableAndStringTable($symbol_table, $string_table);

        $result = $resolver->resolve(0x1050);
        $this->assertNotNull($result);
        $this->assertSame('func_a', $result[0]);
        $this->assertSame(0x50, $result[1]);
    }

    public function testResolveOutOfRange(): void
    {
        $string_data = "\0func_a\0";
        $string_table = new Elf64StringTable($string_data);
        $symbol_table = new Elf64SymbolTable(
            $this->makeEntry(1, 0x1000, 0x100),
        );

        $resolver = Elf64ReverseSymbolResolver::fromSymbolTableAndStringTable($symbol_table, $string_table);

        // Past the end of func_a
        $result = $resolver->resolve(0x1100);
        $this->assertNull($result);
    }

    public function testResolveBeforeFirst(): void
    {
        $string_data = "\0func_a\0";
        $string_table = new Elf64StringTable($string_data);
        $symbol_table = new Elf64SymbolTable(
            $this->makeEntry(1, 0x1000, 0x100),
        );

        $resolver = Elf64ReverseSymbolResolver::fromSymbolTableAndStringTable($symbol_table, $string_table);
        $this->assertNull($resolver->resolve(0x500));
    }

    public function testResolveMultipleSymbols(): void
    {
        $string_data = "\0alpha\0beta\0gamma\0";
        $string_table = new Elf64StringTable($string_data);
        $symbol_table = new Elf64SymbolTable(
            $this->makeEntry(1, 0x3000, 0x50),     // alpha
            $this->makeEntry(7, 0x1000, 0x200),     // beta
            $this->makeEntry(12, 0x2000, 0x100),    // gamma
        );

        $resolver = Elf64ReverseSymbolResolver::fromSymbolTableAndStringTable($symbol_table, $string_table);

        $result = $resolver->resolve(0x1100);
        $this->assertNotNull($result);
        $this->assertSame('beta', $result[0]);
        $this->assertSame(0x100, $result[1]);

        $result = $resolver->resolve(0x2050);
        $this->assertNotNull($result);
        $this->assertSame('gamma', $result[0]);

        $result = $resolver->resolve(0x3000);
        $this->assertNotNull($result);
        $this->assertSame('alpha', $result[0]);
    }

    public function testLoadFromPhpBinary(): void
    {
        $php_path = PHP_BINARY;
        $binary = file_get_contents($php_path);
        if ($binary === false) {
            $this->markTestSkipped('Cannot read PHP binary');
        }

        $parser = new Elf64Parser(new LittleEndianReader());
        $resolver = Elf64ReverseSymbolResolver::loadFromBinary($parser, new StringByteReader($binary));
        $this->assertNotNull($resolver);

        // Find execute_ex address and resolve it
        $elf_header = $parser->parseElfHeader(new StringByteReader($binary));
        $program_header = $parser->parseProgramHeader(new StringByteReader($binary), $elf_header);
        $base_address = $program_header->findBaseAddress();
        $dynamic_entries = $program_header->findDynamic();
        if ($dynamic_entries === []) {
            $this->markTestSkipped('No dynamic section');
        }
        $dynamic_array = $parser->parseDynamicStructureArray(
            new StringByteReader($binary),
            $dynamic_entries[0]->p_offset,
            $dynamic_entries[0]->p_vaddr,
        );
        $string_table = $parser->parseStringTable(new StringByteReader($binary), $base_address, $dynamic_array);
        $gnu_hash = $parser->parseGnuHashTable(new StringByteReader($binary), $base_address, $dynamic_array);
        if ($gnu_hash === null) {
            $this->markTestSkipped('No GNU hash');
        }
        $symbol_table = $parser->parseSymbolTableFromDynamic(
            new StringByteReader($binary),
            $base_address,
            $dynamic_array,
            $gnu_hash->getNumberOfSymbols(),
        );

        foreach ($symbol_table->findAll() as $entry) {
            $name = $string_table->lookup($entry->st_name);
            if ($name === 'execute_ex') {
                $result = $resolver->resolve($entry->st_value->toInt());
                $this->assertNotNull($result);
                $this->assertSame('execute_ex', $result[0]);
                $this->assertSame(0, $result[1]);
                return;
            }
        }
        $this->markTestSkipped('execute_ex not found');
    }

    public function testSkipsNonFuncSymbols(): void
    {
        $string_data = "\0my_object\0my_func\0";
        $string_table = new Elf64StringTable($string_data);
        $symbol_table = new Elf64SymbolTable(
            $this->makeEntry(1, 0x1000, 0x100, Elf64SymbolTableEntry::STT_OBJECT), // should be skipped
            $this->makeEntry(11, 0x1000, 0x100, Elf64SymbolTableEntry::STT_FUNC),
        );

        $resolver = Elf64ReverseSymbolResolver::fromSymbolTableAndStringTable($symbol_table, $string_table);

        $result = $resolver->resolve(0x1050);
        $this->assertNotNull($result);
        $this->assertSame('my_func', $result[0]);
    }
}
