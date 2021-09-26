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

namespace PhpProfiler\Lib\Process\RegisterReader;

use FFI\CInteger;
use PhpProfiler\Lib\Libc\Errno\Errno;
use PhpProfiler\Lib\Libc\Sys\Ptrace\PtraceRequest;
use PhpProfiler\Lib\Libc\Sys\Ptrace\PtraceX64;

final class X64RegisterReader
{
    /** @var int */
    public const R15 = 0 * 8;

    /** @var int */
    public const R14 = 1 * 8;

    /** @var int */
    public const R13 = 2 * 8;

    /** @var int */
    public const R12 = 3 * 8;

    /** @var int */
    public const BP = 4 * 8;

    /** @var int */
    public const BX = 5 * 8;

    /** @var int */
    public const R11 = 6 * 8;

    /** @var int */
    public const R10 = 7 * 8;

    /** @var int */
    public const R9 = 8 * 8;

    /** @var int */
    public const R8 = 9 * 8;

    /** @var int */
    public const AX = 10 * 8;

    /** @var int */
    public const CX = 11 * 8;

    /** @var int */
    public const DX = 12 * 8;

    /** @var int */
    public const SI = 13 * 8;

    /** @var int */
    public const DI = 14 * 8;

    /** @var int */
    public const ORIG_AX = 15 * 8;

    /** @var int */
    public const IP = 16 * 8;

    /** @var int */
    public const CS = 17 * 8;

    /** @var int */
    public const FLAGS = 18 * 8;

    /** @var int */
    public const SP = 19 * 8;

    /** @var int */
    public const SS = 20 * 8;

    /** @var int */
    public const FS_BASE = 21 * 8;

    /** @var int */
    public const GS_BASE = 22 * 8;

    /** @var int */
    public const DS = 23 * 8;

    /** @var int */
    public const ES = 24 * 8;

    /** @var int */
    public const FS = 25 * 8;

    /** @var int */
    public const GS = 26 * 8;

    /** @var int[] */
    public const ALL_REGISTERS = [
        self::R15,
        self::R14,
        self::R13,
        self::R12,
        self::BP,
        self::BX,
        self::R11,
        self::R10,
        self::R9,
        self::R8,
        self::AX,
        self::CX,
        self::DX,
        self::SI,
        self::DI,
        self::ORIG_AX,
        self::IP,
        self::CS,
        self::FLAGS,
        self::SP,
        self::SS,
        self::FS_BASE,
        self::GS_BASE,
        self::DS,
        self::ES,
        self::FS,
        self::GS,
    ];

    public function __construct(
        private PtraceX64 $ptrace,
        private Errno $errno,
    ) {
    }

    /**
     * @param value-of<X64RegisterReader::ALL_REGISTERS> $register
     * @throws RegisterReaderException
     */
    public function attachAndReadOne(int $pid, int $register): int
    {
        $target_offset = \FFI::new('long');
        /** @var \FFI\CInteger $target_offset */
        $target_offset->cdata = $register;

        $attach = $this->ptrace->ptrace(
            PtraceRequest::PTRACE_ATTACH(),
            $pid,
            null,
            null
        );
        if ($attach === -1) {
            $errno = $this->errno->get();
            if ($errno) {
                throw new RegisterReaderException("failed to attach process errno={$errno}", $errno);
            }
        }
        pcntl_waitpid($pid, $status, \WUNTRACED);

        $fs = $this->ptrace->ptrace(
            PtraceRequest::PTRACE_PEEKUSER(),
            $pid,
            \FFI::cast('void *', $target_offset),
            null
        );
        if ($fs === -1) {
            $errno = $this->errno->get();
            if ($errno) {
                throw new RegisterReaderException("failed to read register errno={$errno}", $errno);
            }
        }

        $detach = $this->ptrace->ptrace(
            PtraceRequest::PTRACE_DETACH(),
            $pid,
            null,
            null
        );
        if ($detach === -1) {
            $errno = $this->errno->get();
            if ($errno) {
                throw new RegisterReaderException("failed to detach process errno={$errno}", $errno);
            }
        }

        return $fs;
    }
}
