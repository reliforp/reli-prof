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

final class Elf64ProgramHeaderEntry
{
    public const PT_NULL = 0;
    public const PT_LOAD = 1;
    public const PT_DYNAMIC = 2;
    public const PT_INTERP = 3;
    public const PT_NOTE = 4;
    public const PT_SHLIB = 5;
    public const PT_PHDR = 6;
    public const PT_LOPROC = 0x70000000;
    public const PT_HIPROC = 0x7fffffff;

    public const PF_X = 1;
    public const PF_W = 2;
    public const PF_R = 4;
    public const PF_MASKPROC = 0xf000000;

    public function __construct(
        public int $p_type, // Elf64_Word
        public int $p_flags, // Elf64_Word
        public UInt64 $p_offset, // Elf64_Off
        public UInt64 $p_vaddr, // Elf64_Addr
        public UInt64 $p_paddr, // Elf64_Addr
        public UInt64 $p_filesz, // Elf64_Xword
        public UInt64 $p_memsz, // Elf64_Xword
        public UInt64 $p_align // Elf64_Xword
    ) {
    }

    public function isReadable(): bool
    {
        return ($this->p_flags & self::PF_R) !== 0;
    }

    public function isWritable(): bool
    {
        return ($this->p_flags & self::PF_W) !== 0;
    }

    public function isExecutable(): bool
    {
        return ($this->p_flags & self::PF_X) !== 0;
    }

    public function isLoad(): bool
    {
        return $this->p_type === self::PT_LOAD;
    }

    public function isDynamic(): bool
    {
        return $this->p_type === self::PT_DYNAMIC;
    }

    public function isNote(): bool
    {
        return $this->p_type === self::PT_NOTE;
    }
}
