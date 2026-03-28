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

namespace Reli\Lib\Process\RegisterReader;

use Reli\Lib\Libc\Errno\Errno;
use Reli\Lib\Libc\Sys\Ptrace\PtraceAarch64;
use Reli\Lib\Libc\Sys\Ptrace\PtraceRequest;

final class Aarch64RegisterReader
{
    /** Register index constants for user_pt_regs */
    public const X0 = 0;
    public const X1 = 1;
    public const X2 = 2;
    public const X3 = 3;
    public const X4 = 4;
    public const X5 = 5;
    public const X6 = 6;
    public const X7 = 7;
    public const X8 = 8;
    public const X9 = 9;
    public const X10 = 10;
    public const X11 = 11;
    public const X12 = 12;
    public const X13 = 13;
    public const X14 = 14;
    public const X15 = 15;
    public const X16 = 16;
    public const X17 = 17;
    public const X18 = 18;
    public const X19 = 19;
    public const X20 = 20;
    public const X21 = 21;
    public const X22 = 22;
    public const X23 = 23;
    public const X24 = 24;
    public const X25 = 25;
    public const X26 = 26;
    public const X27 = 27;
    public const X28 = 28;
    public const X29 = 29; // FP (frame pointer)
    public const X30 = 30; // LR (link register)
    public const SP = 31;
    public const PC = 32;

    public function __construct(
        private PtraceAarch64 $ptrace,
        private Errno $errno,
    ) {
    }

    /**
     * Attach to a process, read a single register value, and detach.
     *
     * @param int $register One of the register index constants (X0-X30, SP, PC)
     * @throws RegisterReaderException
     */
    public function attachAndReadOne(int $pid, int $register): int
    {
        $already_attached = false;
        $attach = $this->ptrace->ptrace(
            PtraceRequest::PTRACE_ATTACH,
            $pid,
            null,
            null
        );
        if ($attach === -1) {
            $errno = $this->errno->get();
            if ($errno) {
                if ($errno === 1) {
                    $already_attached = true;
                } else {
                    throw new RegisterReaderException(
                        "failed to attach process errno={$errno}",
                        $errno
                    );
                }
            }
        }
        if (!$already_attached) {
            pcntl_waitpid($pid, $status, \WUNTRACED);
        }

        try {
            $regs = $this->ptrace->getRegisters($pid);
        } catch (\Throwable $e) {
            if (!$already_attached) {
                $this->ptrace->ptrace(PtraceRequest::PTRACE_DETACH, $pid, null, null);
            }
            throw new RegisterReaderException(
                "failed to read registers: {$e->getMessage()}",
                0,
                $e
            );
        }

        /**
         * @var int $value
         * @psalm-suppress UndefinedPropertyFetch
         * @psalm-suppress MixedArrayAccess
         */
        $value = match ($register) {
            self::SP => (int)$regs->sp,
            self::PC => (int)$regs->pc,
            default => (int)$regs->regs[$register],
        };

        if (!$already_attached) {
            $detach = $this->ptrace->ptrace(
                PtraceRequest::PTRACE_DETACH,
                $pid,
                null,
                null
            );
            if ($detach === -1) {
                $errno = $this->errno->get();
                if ($errno) {
                    throw new RegisterReaderException(
                        "failed to detach process errno={$errno}",
                        $errno
                    );
                }
            }
        }

        return $value;
    }
}
