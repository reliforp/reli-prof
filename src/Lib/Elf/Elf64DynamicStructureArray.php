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
 * Class Elf64DynamicStructureArray
 * @package PhpProfiler\Lib\Elf
 */
class Elf64DynamicStructureArray
{
    /** @var Elf64DynamicStructure[] */
    private $entries = [];

    /**
     * Elf64ProgramHeaderTable constructor.
     * @param Elf64DynamicStructure ...$entries
     */
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
}