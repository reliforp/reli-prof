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

namespace PhpProfiler\Lib\Libc\Sys\Ptrace;

use MyCLabs\Enum\Enum;

/**
 * @extends Enum<int>
 * @psalm-immutable
 */
final class PtraceRequest extends Enum
{
    public const PTRACE_PTRACEME = 0;
    public const PTRACE_PEEKTEXT = 1;
    public const PTRACE_PEEKDATA = 2;
    public const PTRACE_PEEKUSER = 3;
    public const PTRACE_POKETEXT = 4;
    public const PTRACE_POKEDATA = 5;
    public const PTRACE_POKEUSER = 6;
    public const PTRACE_CONT = 7;
    public const PTRACE_KILL = 8;
    public const PTRACE_SINGLESTEP = 9;
    public const PTRACE_GETREGS = 12;
    public const PTRACE_SETREGS = 13;
    public const PTRACE_GETFPREGS = 14;
    public const PTRACE_SETFPREGS = 15;
    public const PTRACE_ATTACH = 16;
    public const PTRACE_DETACH = 17;
    public const PTRACE_GETFPXREGS = 18;
    public const PTRACE_SETFPXREGS = 19;
    public const PTRACE_SYSCALL = 24;
    public const PTRACE_SETOPTIONS = 0x4200;
    public const PTRACE_GETEVENTMSG = 0x4201;
    public const PTRACE_GETSIGINFO = 0x4202;
    public const PTRACE_SETSIGINFO = 0x4203;
}
