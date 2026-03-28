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

namespace Reli\Lib\Elf\Tls;

use FFI\CData;
use Reli\Lib\FFI\CannotAllocateBufferException;
use Reli\Lib\Libc\Errno\Errno;
use Reli\Lib\Libc\Sys\Ptrace\PtraceAarch64;
use Reli\Lib\Libc\Sys\Ptrace\PtraceRequest;
use Reli\Lib\Process\RegisterReader\RegisterReaderException;

/**
 * Retrieves the thread pointer (TPIDR_EL0) on AArch64 Linux
 * using PTRACE_GETREGSET with NT_ARM_TLS.
 */
final class Aarch64LinuxThreadPointerRetriever implements ThreadPointerRetrieverInterface
{
    private const NT_ARM_TLS = 0x401;

    public static function createDefault(): self
    {
        return new self(
            new PtraceAarch64(),
            new Errno(),
        );
    }

    public function __construct(
        private PtraceAarch64 $ptrace,
        private Errno $errno,
    ) {
    }

    #[\Override]
    public function getThreadPointer(int $pid): int
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
                    throw new TlsFinderException(
                        "cannot attach to read thread pointer errno={$errno}"
                    );
                }
            }
        }
        if (!$already_attached) {
            pcntl_waitpid($pid, $status, \WUNTRACED);
        }

        try {
            $value = $this->readTpidrEl0($pid);
        } catch (\Throwable $e) {
            if (!$already_attached) {
                $this->ptrace->ptrace(PtraceRequest::PTRACE_DETACH, $pid, null, null);
            }
            throw new TlsFinderException('cannot find thread pointer', 0, $e);
        }

        if (!$already_attached) {
            $this->ptrace->ptrace(PtraceRequest::PTRACE_DETACH, $pid, null, null);
        }

        return $value;
    }

    /**
     * Read TPIDR_EL0 via PTRACE_GETREGSET with NT_ARM_TLS (0x401).
     * The kernel returns a single 64-bit value.
     */
    /** @psalm-suppress UndefinedPropertyAssignment */
    private function readTpidrEl0(int $pid): int
    {
        /** @var \FFI $ffi */
        $ffi = \FFI::cdef('
            struct iovec {
                void  *iov_base;
                unsigned long iov_len;
            };
        ');

        $buf = \FFI::new('unsigned long long')
            ?? throw new CannotAllocateBufferException('cannot allocate tls buffer');
        $iov = $ffi->new('struct iovec')
            ?? throw new CannotAllocateBufferException('cannot allocate iovec');

        $iov->iov_base = \FFI::addr($buf);
        $iov->iov_len = \FFI::sizeof($buf);

        $result = $this->ptrace->ptrace(
            PtraceRequest::PTRACE_GETREGSET,
            $pid,
            self::NT_ARM_TLS,
            \FFI::addr($iov),
        );

        if ($result === -1) {
            throw new TlsFinderException('PTRACE_GETREGSET NT_ARM_TLS failed');
        }

        return (int)$buf->cdata;
    }
}
