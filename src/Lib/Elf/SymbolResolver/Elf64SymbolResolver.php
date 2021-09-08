<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\Elf\SymbolResolver;

use PhpProfiler\Lib\Elf\Structure\Elf64\Elf64SymbolTableEntry;

interface Elf64SymbolResolver
{
    public function resolve(string $symbol_name): Elf64SymbolTableEntry;
}
