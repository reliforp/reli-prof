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

class Elf64Note
{
    public const NT_PRSTATUS = 1;
    public const NT_FPREGSET = 2;
    public const NT_PRPSINFO = 3;
    public const NT_TASKSTRUCT = 4;
    public const NT_AUXV = 6;
    public const NT_SIGINFO = 0x53494749;
    public const NT_FILE = 0x46494c45;
    public const NT_PRXFPREG = 0x46e62b7f;
    public const NT_X86_XSTATE = 0x202;

    public function __construct(
        public int $name_size,
        public int $desc_size,
        public int $type,
        public string $name,
        public string $desc,
    ) {
    }

    public function isCore(): bool
    {
        return $this->name === 'CORE';
    }

    public function isFile(): bool
    {
        return $this->type === self::NT_FILE;
    }

    public function isPrStatus()
    {
        return $this->type === self::NT_PRSTATUS;
    }

    public function isPrPsInfo()
    {
        return $this->type === self::NT_PRPSINFO;
    }
}
