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

namespace PhpProfiler\Lib\Elf\Structure\Elf64;

final class Elf64SectionHeaderTable
{
    /** @var Elf64SectionHeaderEntry[] */
    private array $entries;

    public function __construct(
        private Elf64StringTable $section_name_table,
        Elf64SectionHeaderEntry ...$entries
    ) {
        $this->entries = $entries;
    }

    public function findSymbolTableEntry(): ?Elf64SectionHeaderEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->isSymbolTable()) {
                return $entry;
            }
        }
        return null;
    }

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
