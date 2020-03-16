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

class Elf64ProgramHeader
{
    public int $p_type; // Elf64_Word
    public int $p_flags; // Elf64_Word
    public UInt64 $p_offset; // Elf64_Off
    public UInt64 $p_vaddr; // Elf64_Addr
    public UInt64 $p_paddr; // Elf64_Addr
    public UInt64 $p_filesz; // Elf64_Xword
    public UInt64 $p_memsz; // Elf64_Xword
    public UInt64 $p_align; // Elf64_Xword
}