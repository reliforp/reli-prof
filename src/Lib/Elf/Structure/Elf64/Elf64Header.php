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

namespace PhpProfiler\Lib\Elf\Structure\Elf64;

use PhpProfiler\Lib\Integer\UInt64;

/**
 * Class Elf64Header
 * @package PhpProfiler\Lib\Elf
 */
final class Elf64Header
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

    public const EV_NONE = 0;
    public const EV_CURRENT = 1;

    public const ELFOSABI_NONE = 0;

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
     * Elf64Header constructor.
     * @param array $e_ident
     * @param int $e_type
     * @param int $e_machine
     * @param int $e_version
     * @param UInt64 $e_entry
     * @param UInt64 $e_phoff
     * @param UInt64 $e_shoff
     * @param int $e_flags
     * @param int $e_ehsize
     * @param int $e_phentsize
     * @param int $e_phnum
     * @param int $e_shentsize
     * @param int $e_shnum
     * @param int $e_shstrndx
     */
    public function __construct(
        array $e_ident,
        int $e_type,
        int $e_machine,
        int $e_version,
        UInt64 $e_entry,
        UInt64 $e_phoff,
        UInt64 $e_shoff,
        int $e_flags,
        int $e_ehsize,
        int $e_phentsize,
        int $e_phnum,
        int $e_shentsize,
        int $e_shnum,
        int $e_shstrndx
    ) {
        $this->e_ident = $e_ident;
        $this->e_type = $e_type;
        $this->e_machine = $e_machine;
        $this->e_version = $e_version;
        $this->e_entry = $e_entry;
        $this->e_phoff = $e_phoff;
        $this->e_shoff = $e_shoff;
        $this->e_flags = $e_flags;
        $this->e_ehsize = $e_ehsize;
        $this->e_phentsize = $e_phentsize;
        $this->e_phnum = $e_phnum;
        $this->e_shentsize = $e_shentsize;
        $this->e_shnum = $e_shnum;
        $this->e_shstrndx = $e_shstrndx;
    }

    /**
     * @return bool
     */
    public function hasSectionHeader(): bool
    {
        return $this->e_shnum > 0;
    }
}
