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

final class Elf64SymbolTable
{
    public const STN_UNDEF = 0;

    /** @var Elf64SymbolTableEntry[] */
    private array $entries;

    public function __construct(Elf64SymbolTableEntry ...$entries)
    {
        $this->entries = $entries;
    }

    public function lookup(int $index): Elf64SymbolTableEntry
    {
        return $this->entries[$index];
    }

    /**
     * @return Elf64SymbolTableEntry[]
     */
    public function findAll(): array
    {
        return $this->entries;
    }
}
