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

use FFI\CData;
use FFI\CInteger;
use Reli\Lib\FFI\CannotAllocateBufferException;
use Reli\Lib\FFI\CannotCastCDataException;
use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\Libc\Addressable;

final class PtraceAarch64 implements Ptrace
{
    /** @var \FFI */
    private \FFI $ffi;

    public function __construct()
    {
        $this->ffi = \FFI::cdef('
           struct user_pt_regs {
               unsigned long long regs[31];
               unsigned long long sp;
               unsigned long long pc;
               unsigned long long pstate;
           };
           struct iovec {
               void  *iov_base;
               unsigned long iov_len;
           };
           typedef int pid_t;
           enum __ptrace_request
           {
               PTRACE_TRACEME = 0,
               PTRACE_PEEKTEXT = 1,
               PTRACE_PEEKDATA = 2,
               PTRACE_POKETEXT = 4,
               PTRACE_POKEDATA = 5,
               PTRACE_CONT = 7,
               PTRACE_KILL = 8,
               PTRACE_SINGLESTEP = 9,
               PTRACE_ATTACH = 16,
               PTRACE_DETACH = 17,
               PTRACE_SYSCALL = 24,
               PTRACE_SETOPTIONS = 0x4200,
               PTRACE_GETEVENTMSG = 0x4201,
               PTRACE_GETSIGINFO = 0x4202,
               PTRACE_SETSIGINFO = 0x4203,
               PTRACE_GETREGSET = 0x4204,
               PTRACE_SETREGSET = 0x4205
           };
           long ptrace(enum __ptrace_request request, pid_t pid, void *addr, void *data);
           int errno;
       ', 'libc.so.6');
    }

    #[\Override]
    public function ptrace(
        PtraceRequest $request,
        int $pid,
        Addressable|CData|null|int $addr,
        Addressable|CData|null|int $data,
    ): int {
        if (is_null($addr) or is_int($addr)) {
            /** @var CInteger */
            $addr_holder = FFIHelper::new('long')
                ?? throw new CannotAllocateBufferException('cannot allocate buffer');
            $addr_holder->cdata = (int)$addr;
            $addr = FFIHelper::cast('void *', $addr_holder)
                ?? throw new CannotCastCDataException('cannot cast buffer');
        }
        if (is_null($data) or is_int($data)) {
            /** @var CInteger */
            $data_holder = FFIHelper::new('long')
                ?? throw new CannotAllocateBufferException('cannot allocate buffer');
            $data_holder->cdata = (int)$data;
            $data = FFIHelper::cast('void *', $data_holder)
                ?? throw new CannotCastCDataException('cannot cast buffer');
        }

        $addr_pointer = $addr instanceof Addressable ? $addr->toVoidPointer() : $addr;
        $data_pointer = $data instanceof Addressable ? $data->toVoidPointer() : $data;

        /** @var int */
        return $this->ffi->ptrace(
            $request->value,
            $pid,
            $addr_pointer,
            $data_pointer,
        );
    }

    /**
     * Read all general-purpose registers via PTRACE_GETREGSET + NT_PRSTATUS.
     *
     * @return CData user_pt_regs struct
     * @psalm-suppress UndefinedPropertyAssignment
     */
    public function getRegisters(int $pid): CData
    {
        $regs = $this->ffi->new('struct user_pt_regs')
            ?? throw new CannotAllocateBufferException('cannot allocate user_pt_regs');
        $iov = $this->ffi->new('struct iovec')
            ?? throw new CannotAllocateBufferException('cannot allocate iovec');

        $iov->iov_base = \FFI::addr($regs);
        $iov->iov_len = \FFI::sizeof($regs);

        // NT_PRSTATUS = 1
        $result = $this->ptrace(
            PtraceRequest::PTRACE_GETREGSET,
            $pid,
            1,
            \FFI::addr($iov),
        );

        if ($result === -1) {
            throw new \RuntimeException('PTRACE_GETREGSET failed');
        }

        return $regs;
    }

    /**
     * Read TPIDR_EL0 (thread pointer) via PTRACE_GETREGSET + NT_ARM_TLS.
     * All buffers are allocated from the same FFI context to avoid cross-context issues.
     *
     * @psalm-suppress UndefinedPropertyAssignment
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress InvalidCast
     */
    public function readTlsRegister(int $pid): int
    {
        $buf = $this->ffi->new('unsigned long long')
            ?? throw new CannotAllocateBufferException('cannot allocate tls buffer');
        $iov = $this->ffi->new('struct iovec')
            ?? throw new CannotAllocateBufferException('cannot allocate iovec');

        $iov->iov_base = \FFI::addr($buf);
        $iov->iov_len = \FFI::sizeof($buf);

        // NT_ARM_TLS = 0x401
        $result = $this->ptrace(
            PtraceRequest::PTRACE_GETREGSET,
            $pid,
            0x401,
            \FFI::addr($iov),
        );

        if ($result === -1) {
            throw new \RuntimeException(
                'PTRACE_GETREGSET NT_ARM_TLS failed, errno=' . ((int)$this->ffi->errno)
            );
        }

        return (int)$buf->cdata;
    }
}
