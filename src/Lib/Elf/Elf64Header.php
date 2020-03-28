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
 * Class Elf64Header
 * @package PhpProfiler\Lib\Elf
 */
class Elf64Header
{
    public const EI_MAG0 = 0;
    public const EI_MAG1 = 1;
    public const EI_MAG2 = 2;
    public const EI_MAG3 = 3;
    public const EI_CLASS = 4;
    public const EI_DATA = 5;
    public const EI_VERSION = 6;
    public const EI_OSABI = 7;
    public const EI_ABIVERSION = 8;
    public const EI_PAD = 9;
    public const EI_NIDENT = 16;

    public const MAGIC = "\x7fELF";

    public const ELFCLASSNONE = 0;
    public const ELFCLASS32 = 1;
    public const ELFCLASS64 = 2;

    public const ELFDATANONE = 0;
    public const ELFDATA2LSB = 1;
    public const ELFDATA2MSB = 2;

    public const ET_NONE = 0;
    public const ET_REL = 1;
    public const ET_EXEC = 2;
    public const ET_DYN = 3;
    public const ET_CORE = 4;
    public const ET_LOPROC = 0xff00;
    public const ET_HIPROC = 0xffff;

    public const EM_NONE = 0;
    public const EM_386 = 3;
    public const EM_X86_64 = 62;

    public array $e_ident; // unsigned char[EI_NIDENT]
    public int $e_type; // Elf64_Half
    public int $e_machine; // Elf64_Half
    public int $e_version; // Elf64_Word
    public UInt64 $e_entry; // Elf64_Addr
    public UInt64 $e_phoff; // Elf64_Off
    public UInt64 $e_shoff; // Elf64_Off
    public int $e_flags; // Elf64_Word
    public int $e_ehsize; // Elf64_Half
    public int $e_phentsize; // Elf64_Half
    public int $e_phnum; // Elf64_Half
    public int $e_shentsize; // Elf64_Half
    public int $e_shnum; // Elf64_Half
    public int $e_shstrndx; // Elf64_Half

    /**
     * @return bool
     */
    public function hasSectionHeader(): bool
    {
        return $this->e_shnum > 0;
    }
}