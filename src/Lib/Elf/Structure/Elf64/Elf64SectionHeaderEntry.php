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

namespace Reli\Lib\Elf\Structure\Elf64;

use Reli\Lib\Integer\UInt64;

final class Elf64SectionHeaderEntry
{
    public const SHT_NULL = 0;
    public const SHT_PROGBITS = 1;
    public const SHT_SYMTAB = 2;
    public const SHT_STRTAB = 3;
    public const SHT_RELA = 4;
    public const SHT_HASH = 5;
    public const SHT_DYNAMIC = 6;
    public const SHT_NOTE = 7;
    public const SHT_NOBITS = 8;
    public const SHT_REL = 9;
    public const SHT_SHLIB = 10;
    public const SHT_DYNSYM = 11;
    public const SHT_INIT_ARRAY = 14;
    public const SHT_FINI_ARRAY = 15;
    public const SHT_PREINIT_ARRAY = 16;
    public const SHT_GROUP = 17;
    public const SHT_SYMTAB_SHNDX = 18;
    public const SHT_LOOS = 0x60000000;
    public const SHT_HIOS = 0x6fffffff;
    public const SHT_LOPROC = 0x70000000;
    public const SHT_HIPROC = 0x7fffffff;
    public const SHT_LOUSER = 0x80000000;
    public const SHT_HIUSER = 0xffffffff;

    public function __construct(
        public int $sh_name, // Elf64_Word
        public int $sh_type, // Elf64_Word
        public UInt64 $sh_flags, // Elf64_Xword
        public UInt64 $sh_addr, // Elf64_Addr
        public UInt64 $sh_offset, // Elf64_Off
        public UInt64 $sh_size, // Elf64_Xword
        public int $sh_link, // Elf64_Word
        public int $sh_info, // Elf64_Word
        public UInt64 $sh_addralign, // Elf64_Xword
        public UInt64 $sh_entsize, // Elf64_Xword
    ) {
    }

    public function isSymbolTable(): bool
    {
        return $this->sh_type === self::SHT_SYMTAB;
    }

    public function isStringTable(): bool
    {
        return $this->sh_type === self::SHT_STRTAB;
    }
}
