<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpProfiler\Lib\Process;

use FFI\CInteger;

/**
 * Class RegisterReader
 * @package PhpProfiler\Lib\Process
 */
final class RegisterReader
{
    private const PTRACE_PEEKUSER = 3;
    private const PTRACE_ATTACH = 16;
    private const PTRACE_DETACH = 17;

    public const R15 = 0 * 8;
    public const R14 = 1 * 8;
    public const R13 = 2 * 8;
    public const R12 = 3 * 8;
    public const BP = 4 * 8;
    public const BX = 5 * 8;
    public const R11 = 6 * 8;
    public const R10 = 7 * 8;
    public const R9 = 8 * 8;
    public const R8 = 9 * 8;
    public const AX = 10 * 8;
    public const CX = 11 * 8;
    public const DX = 12 * 8;
    public const SI = 13 * 8;
    public const DI = 14 * 8;
    public const ORIG_AX = 15 * 8;
    public const IP = 16 * 8;
    public const CS = 17 * 8;
    public const FLAGS = 18 * 8;
    public const SP = 19 * 8;
    public const SS = 20 * 8;
    public const FS_BASE = 21 * 8;
    public const GS_BASE = 22 * 8;
    public const DS = 23 * 8;
    public const ES = 24 * 8;
    public const FS = 25 * 8;
    public const GS = 26 * 8;

    private \FFI $ffi;

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


    /**
     * @param int $pid
     * @param int $register
     * @return int
     * @throws RegisterReaderException
     */
    public function attachAndReadOne(int $pid, int $register)
    {
        /** @var CInteger $zero */
        $zero = $this->ffi->new('long');
        $zero->cdata = 0;
        $null = \FFI::cast('void *', $zero);
        $target_offset = $this->ffi->new('long');
        /** @var \FFI\CInteger $target_offset */
        $target_offset->cdata = $register;

        /** @var \FFI\Libc\ptrace_ffi $this->ffi */
        $attach = $this->ffi->ptrace(self::PTRACE_ATTACH, $pid, $null, $null);

        if ($attach === -1) {
            /** @var int $errno */
            $errno = $this->ffi->errno;
            if ($errno) {
                throw new RegisterReaderException("failed to attach process errno={$errno}", $errno);
            }
        }
        pcntl_waitpid($pid, $status, WUNTRACED);

        $fs = $this->ffi->ptrace(self::PTRACE_PEEKUSER, $pid, \FFI::cast('void *', $target_offset), $null);
        if ($fs === -1) {
            /** @var int $errno */
            $errno = $this->ffi->errno;
            if ($errno) {
                throw new RegisterReaderException("failed to read register errno={$errno}", $errno);
            }
        }

        $detach = $this->ffi->ptrace(self::PTRACE_DETACH, $pid, $null, $null);
        if ($detach === -1) {
            /** @var int $errno */
            $errno = $this->ffi->errno;
            if ($errno) {
                throw new RegisterReaderException("failed to detach process errno={$errno}", $errno);
            }
        }

        return $fs;
    }
}
