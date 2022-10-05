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

final class Elf64DynamicStructureArray
{
    /** @var Elf64DynamicStructure[] */
    private array $entries;

    public function __construct(Elf64DynamicStructure ...$entries)
    {
        $this->entries = $entries;
    }

    /**
     * @return Elf64DynamicStructure[]
     */
    public function findAll(): array
    {
        return $this->entries;
    }

    /**
     * @return Elf64DynamicStructure[]
     */
    public function findStringTableEntries(): array
    {
        $entries = [];
        foreach ($this->entries as $entry) {
            if ($entry->isStringTable()) {
                $entries[Elf64DynamicStructure::DT_STRTAB] = $entry;
            } elseif ($entry->isStringTableSize()) {
                $entries[Elf64DynamicStructure::DT_STRSZ] = $entry;
            }
        }
        return $entries;
    }

    /**
     * @return Elf64DynamicStructure[]
     */
    public function findSymbolTablEntries(): array
    {
        $entries = [];
        foreach ($this->entries as $entry) {
            if ($entry->isSymbolTable()) {
                $entries[Elf64DynamicStructure::DT_SYMTAB] = $entry;
            } elseif ($entry->isSymbolTableEntrySize()) {
                $entries[Elf64DynamicStructure::DT_SYMENT] = $entry;
            }
        }
        return $entries;
    }

    public function findGnuHashTableEntry(): ?Elf64DynamicStructure
    {
        foreach ($this->entries as $entry) {
            if ($entry->isGnuHashTable()) {
                return $entry;
            }
        }
        return null;
    }

    public function findDebugEntry(): ?Elf64DynamicStructure
    {
        foreach ($this->entries as $entry) {
            if ($entry->isDebug()) {
                return $entry;
            }
        }
        return null;
    }
}
