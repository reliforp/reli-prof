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


use PhpProfiler\Lib\UInt64;

/**
 * Class Elf64SymbolTableEntry
 * @package PhpProfiler\Lib\Elf
 */
class Elf64SymbolTableEntry
{
    public int $st_name; // Elf64_Word
    public int $st_info; // unsigned char
    public int $st_other; // unsigned char
    public int $st_shndx; // Elf64_Half
    public UInt64 $st_value; // Elf64_Addr
    public UInt64 $st_size; // Elf64_Xword
}