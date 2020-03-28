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
 * Class Elf64SectionHeaderTable
 * @package PhpProfiler\Lib\Elf
 */
class Elf64SectionHeaderTable
{
    /**
     * @var Elf64SectionHeaderEntry[]
     */
    private array $entries;
    /**
     * @var Elf64StringTable
     */
    private Elf64StringTable $section_name_table;

    /**
     * Elf64SectionHeaderTable constructor.
     * @param Elf64StringTable $section_name_table
     * @param Elf64SectionHeaderEntry ...$entries
     */
    public function __construct(Elf64StringTable $section_name_table, Elf64SectionHeaderEntry ...$entries)
    {
        $this->section_name_table = $section_name_table;
        $this->entries = $entries;
    }

    /**
     * @return Elf64SectionHeaderEntry|null
     */
    public function findSymbolTableEntry(): ?Elf64SectionHeaderEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->isSymbolTable()) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * @return Elf64SectionHeaderEntry|null
     */
    public function findStringTableEntry(): ?Elf64SectionHeaderEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->isStringTable() and $this->section_name_table->lookup($entry->sh_name) === '.strtab') {
                return $entry;
            }
        }
        return null;
    }
}