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

use FFI\CData;
use FFI\CInteger;
use PhpProfiler\Lib\Libc\Addressable;

class PtraceX64 implements Ptrace
{
    /** @var \FFI\Libc\ptrace_ffi */
    private \FFI $ffi;

    public function __construct()
    {
        /** @var \FFI\Libc\ptrace_ffi ffi */
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

    public function ptrace(
        PtraceRequest $request,
        int $pid,
        Addressable|CData|null|int $addr,
        Addressable|CData|null|int $data,
    ): int {
        if (is_null($addr) or is_int($addr)) {
            /** @var CInteger */
            $addr_holder = \FFI::new('long');
            $addr_holder->cdata = (int)$addr;
            $addr = \FFI::cast('void *', $addr_holder);
        }
        if (is_null($data) or is_int($data)) {
            /** @var CInteger */
            $data_holder = \FFI::new('long');
            $data_holder->cdata = (int)$data;
            $data = \FFI::cast('void *', $data_holder);
        }

        $addr_pointer = $addr instanceof Addressable ? $addr->toVoidPointer() : $addr;
        $data_pointer = $data instanceof Addressable ? $data->toVoidPointer() : $data;

        /** @var int */
        return $this->ffi->ptrace(
            $request->getValue(),
            $pid,
            $addr_pointer,
            $data_pointer,
        );
    }
}
