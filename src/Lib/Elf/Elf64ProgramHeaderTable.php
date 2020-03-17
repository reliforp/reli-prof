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
 * Class Elf64ProgramHeaderTable
 * @package PhpProfiler\Lib\Elf
 */
class Elf64ProgramHeaderTable
{
    /** @var Elf64ProgramHeaderEntry[] */
    private $entries = [];

    /**
     * Elf64ProgramHeaderTable constructor.
     * @param Elf64ProgramHeaderEntry ...$entries
     */
    public function __construct(Elf64ProgramHeaderEntry ...$entries)
    {
        $this->entries = $entries;
    }

    /**
     * @return Elf64ProgramHeaderEntry[]
     */
    public function findLoad(): array
    {
        $result = [];
        foreach ($this->entries as $entry) {
            if ($entry->isLoad()) {
                $result[] = $entry;
            }
        }
        return $result;
    }

    /**
     * @return Elf64ProgramHeaderEntry[]
     */
    public function findDynamic(): array
    {
        $result = [];
        foreach ($this->entries as $entry) {
            if ($entry->isDynamic()) {
                $result[] = $entry;
            }
        }
        return $result;
    }

    public function findAll(): array
    {
        return $this->entries;
    }
}