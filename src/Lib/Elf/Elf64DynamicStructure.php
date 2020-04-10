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
 * Class Elf64DynamicStructure
 * @package PhpProfiler\Lib\Elf
 */
class Elf64DynamicStructure
{
    public const DT_NULL = 0;
    public const DT_NEEDED = 1;
    public const DT_PLTRELSZ = 2;
    public const DT_PLTGOT = 3;
    public const DT_HASH = 4;
    public const DT_STRTAB = 5;
    public const DT_SYMTAB = 6;
    public const DT_RELA = 7;
    public const DT_RELASZ = 8;
    public const DT_RELAENT = 9;
    public const DT_STRSZ = 10;
    public const DT_SYMENT = 11;
    public const DT_INIT = 12;
    public const DT_FINI = 13;
    public const DT_SONAME = 14;
    public const DT_RPATH = 15;
    public const DT_SYMBOLIC = 16;
    public const DT_REL = 17;
    public const DT_RELSZ = 18;
    public const DT_RELENT = 19;
    public const DT_PLTREL = 20;
    public const DT_DEBUG = 21;
    public const DT_TEXTREL = 22;
    public const DT_JMPREL = 23;
    public const DT_BIND_NOW = 24;
    public const DT_INIT_ARRAY = 25;
    public const DT_FINI_ARRAY = 26;
    public const DT_INIT_ARRAYSZ = 27;
    public const DT_FINI_ARRAYSZ = 28;
    public const DT_RUNPATH = 29;
    public const DT_FLAGS = 30;
    public const DT_ENCODINGS = 31;
    public const DT_PREINIT_ARRAY = 32;
    public const DT_PREINIT_ARRAYSZ = 33;
    public const DT_LOOS = 0x6000000d;
    public const DT_HIOS = 0x6ffff000;
    public const DT_GNU_HASH = 0x6ffffef5;
    public const DT_LOPROC = 0x70000000;
    public const DT_HIPROC = 0x7fffffff;

    public const DF_ORIGIN = 1;
    public const DF_SYMBOLIC = 2;
    public const DF_TEXTREL = 4;
    public const DF_BIND_NOW = 8;

    public UInt64 $d_tag;
    public UInt64 $d_un;

    /**
     * @return bool
     */
    public function isEnd(): bool
    {
        return $this->d_tag->hi === 0 and $this->d_tag->lo === self::DT_NULL;
    }

    /**
     * @return bool
     */
    public function isHashTable(): bool
    {
        return $this->d_tag->hi === 0 and $this->d_tag->lo === self::DT_HASH;
    }

    /**
     * @return bool
     */
    public function isGnuHashTable(): bool
    {
        return $this->d_tag->hi === 0 and $this->d_tag->lo === self::DT_GNU_HASH;
    }

    /**
     * @return bool
     */
    public function isStringTable(): bool
    {
        return $this->d_tag->hi === 0 and $this->d_tag->lo === self::DT_STRTAB;
    }

    /**
     * @return bool
     */
    public function isStringTableSize(): bool
    {
        return $this->d_tag->hi === 0 and $this->d_tag->lo === self::DT_STRSZ;
    }

    /**
     * @return bool
     */
    public function isSymbolTable(): bool
    {
        return $this->d_tag->hi === 0 and $this->d_tag->lo === self::DT_SYMTAB;
    }

    /**
     * @return bool
     */
    public function isSymbolTableEntrySize(): bool
    {
        return $this->d_tag->hi === 0 and $this->d_tag->lo === self::DT_SYMENT;
    }

    /**
     * @return bool
     */
    public function isDebug(): bool
    {
        return $this->d_tag->hi === 0 and $this->d_tag->lo === self::DT_DEBUG;
    }
}