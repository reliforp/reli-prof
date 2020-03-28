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
     * Elf64SectionHeaderTable constructor.
     * @param Elf64SectionHeaderEntry ...$entries
     */
    public function __construct(Elf64SectionHeaderEntry ...$entries)
    {
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
}