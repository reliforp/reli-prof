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

namespace PhpProfiler\Lib\Process\ProcessStopper;

use FFI\CInteger;

final class ProcessStopper
{
    private \FFI $ffi;

    private const PTRACE_ATTACH = 16;
    private const PTRACE_DETACH = 17;

    public function __construct()
    {
        $this->ffi = \FFI::cdef('
           struct user_regs_struct {
               unsigned long r15;
               unsigned long r14;
               unsigned long r13;
               unsigned long r12;
               unsigned long bp;
               unsigned long bx;
               unsigned long r11;
               unsigned long r10;
               unsigned long r9;
               unsigned long r8;
               unsigned long ax;
               unsigned long cx;
               unsigned long dx;
               unsigned long si;
               unsigned long di;
               unsigned long orig_ax;
               unsigned long ip;
               unsigned long cs;
               unsigned long flags;
               unsigned long sp;
               unsigned long ss;
               unsigned long fs_base;
               unsigned long gs_base;
               unsigned long ds;
               unsigned long es;
               unsigned long fs;
               unsigned long gs;
           };
           typedef int pid_t;
           enum __ptrace_request
           {
               PTRACE_TRACEME = 0,
               PTRACE_PEEKTEXT = 1,
               PTRACE_PEEKDATA = 2,
               PTRACE_PEEKUSER = 3,
               PTRACE_POKETEXT = 4,
               PTRACE_POKEDATA = 5,
               PTRACE_POKEUSER = 6,
               PTRACE_CONT = 7,
               PTRACE_KILL = 8,
               PTRACE_SINGLESTEP = 9,
               PTRACE_GETREGS = 12,
               PTRACE_SETREGS = 13,
               PTRACE_GETFPREGS = 14,
               PTRACE_SETFPREGS = 15,
               PTRACE_ATTACH = 16,
               PTRACE_DETACH = 17,
               PTRACE_GETFPXREGS = 18,
               PTRACE_SETFPXREGS = 19,
               PTRACE_SYSCALL = 24,
               PTRACE_SETOPTIONS = 0x4200,
               PTRACE_GETEVENTMSG = 0x4201,
               PTRACE_GETSIGINFO = 0x4202,
               PTRACE_SETSIGINFO = 0x4203
           };
           long ptrace(enum __ptrace_request request, pid_t pid, void *addr, void *data);
           int errno;
       ', 'libc.so.6');
    }

    public function stop(int $pid): bool
    {
        /** @var CInteger $zero */
        $zero = $this->ffi->new('long');
        $zero->cdata = 0;
        $null = \FFI::cast('void *', $zero);

        /** @var \FFI\Libc\ptrace_ffi $this->ffi */
        $attach = $this->ffi->ptrace(self::PTRACE_ATTACH, $pid, $null, $null);

        if ($attach === -1) {
            /** @var int $errno */
            $errno = $this->ffi->errno;
            if ($errno) {
                return false;
            }
        }
        pcntl_waitpid($pid, $status, WUNTRACED);
        return true;
    }

    public function resume(int $pid): void
    {
        /** @var CInteger $zero */
        $zero = $this->ffi->new('long');
        $zero->cdata = 0;
        $null = \FFI::cast('void *', $zero);

        /** @var \FFI\Libc\ptrace_ffi $this->ffi */
        $detach = $this->ffi->ptrace(self::PTRACE_DETACH, $pid, $null, $null);
        if ($detach === -1) {
            /** @var int $errno */
            $errno = $this->ffi->errno;
            if ($errno) {
                return;
            }
        }
    }
}
