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

use Reli\Lib\Elf\Structure\Elf64\Elf64SymbolTableEntry;

final class Elf64SymbolCache
{
    /** @var array<string, Elf64SymbolTableEntry> */
    private array $cache = [];

    public function has(string $symbol_name): bool
    {
        return isset($this->cache[$symbol_name]);
    }

    public function set(string $symbol_name, Elf64SymbolTableEntry $entry): void
    {
        $this->cache[$symbol_name] = $entry;
    }

    public function get(string $symbol_name): Elf64SymbolTableEntry
    {
        return $this->cache[$symbol_name];
    }
}
