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

namespace Reli\Lib\Libc\Sys\Ptrace;

enum PtraceRequest: int
{
    case PTRACE_PTRACEME = 0;
    case PTRACE_PEEKTEXT = 1;
    case PTRACE_PEEKDATA = 2;
    case PTRACE_PEEKUSER = 3;
    case PTRACE_POKETEXT = 4;
    case PTRACE_POKEDATA = 5;
    case PTRACE_POKEUSER = 6;
    case PTRACE_CONT = 7;
    case PTRACE_KILL = 8;
    case PTRACE_SINGLESTEP = 9;
    case PTRACE_GETREGS = 12;
    case PTRACE_SETREGS = 13;
    case PTRACE_GETFPREGS = 14;
    case PTRACE_SETFPREGS = 15;
    case PTRACE_ATTACH = 16;
    case PTRACE_DETACH = 17;
    case PTRACE_GETFPXREGS = 18;
    case PTRACE_SETFPXREGS = 19;
    case PTRACE_SYSCALL = 24;
    case PTRACE_SETOPTIONS = 0x4200;
    case PTRACE_GETEVENTMSG = 0x4201;
    case PTRACE_GETSIGINFO = 0x4202;
    case PTRACE_SETSIGINFO = 0x4203;
}
