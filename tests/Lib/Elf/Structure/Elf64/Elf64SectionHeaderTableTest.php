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

namespace Reli\Lib\Elf\Structure\Elf64;

use Reli\BaseTestCase;
use Reli\Lib\Integer\UInt64;

class Elf64SectionHeaderTableTest extends BaseTestCase
{
    private function makeEntry(
        int $name_index,
        int $type,
    ): Elf64SectionHeaderEntry {
        return new Elf64SectionHeaderEntry(
            $name_index,
            $type,
            new UInt64(0, 0),
            new UInt64(0, 0),
            new UInt64(0, 0),
            new UInt64(0, 0),
            0,
            0,
            new UInt64(0, 0),
            new UInt64(0, 0),
        );
    }

    public function testFindSectionByName(): void
    {
        // String table: "\0.text\0.eh_frame\0.strtab\0"
        $str = "\0.text\0.eh_frame\0.strtab\0";
        $string_table = new Elf64StringTable($str);

        $table = new Elf64SectionHeaderTable(
            $string_table,
            $this->makeEntry(0, Elf64SectionHeaderEntry::SHT_NULL),   // null
            $this->makeEntry(1, Elf64SectionHeaderEntry::SHT_PROGBITS), // .text at index 1
            $this->makeEntry(7, Elf64SectionHeaderEntry::SHT_PROGBITS), // .eh_frame at index 7
            $this->makeEntry(17, Elf64SectionHeaderEntry::SHT_STRTAB), // .strtab at index 17
        );

        $result = $table->findSectionByName('.eh_frame');
        $this->assertNotNull($result);
        $this->assertSame(7, $result->sh_name);

        $result = $table->findSectionByName('.text');
        $this->assertNotNull($result);
        $this->assertSame(1, $result->sh_name);

        $result = $table->findSectionByName('.nonexistent');
        $this->assertNull($result);
    }

    public function testFindSymbolTableEntry(): void
    {
        $str = "\0.symtab\0";
        $string_table = new Elf64StringTable($str);

        $table = new Elf64SectionHeaderTable(
            $string_table,
            $this->makeEntry(0, Elf64SectionHeaderEntry::SHT_NULL),
            $this->makeEntry(1, Elf64SectionHeaderEntry::SHT_SYMTAB),
        );

        $result = $table->findSymbolTableEntry();
        $this->assertNotNull($result);
        $this->assertSame(Elf64SectionHeaderEntry::SHT_SYMTAB, $result->sh_type);
    }

    public function testFindStringTableEntry(): void
    {
        $str = "\0.strtab\0.shstrtab\0";
        $string_table = new Elf64StringTable($str);

        $table = new Elf64SectionHeaderTable(
            $string_table,
            $this->makeEntry(0, Elf64SectionHeaderEntry::SHT_NULL),
            $this->makeEntry(1, Elf64SectionHeaderEntry::SHT_STRTAB), // .strtab
            $this->makeEntry(9, Elf64SectionHeaderEntry::SHT_STRTAB), // .shstrtab
        );

        $result = $table->findStringTableEntry();
        $this->assertNotNull($result);
        // Should find .strtab, not .shstrtab
        $this->assertSame(1, $result->sh_name);
    }
}
