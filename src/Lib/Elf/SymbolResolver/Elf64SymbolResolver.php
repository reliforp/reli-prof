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

interface Elf64SymbolResolver
{
    public function resolve(string $symbol_name): Elf64SymbolTableEntry;
}
