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

use Reli\Lib\Libc\Errno\Errno;
use Reli\Lib\Libc\Sys\Ptrace\PtraceAarch64;
use Reli\Lib\Libc\Sys\Ptrace\PtraceRequest;

/**
 * Retrieves the thread pointer (TPIDR_EL0) on AArch64 Linux
 * using PTRACE_GETREGSET with NT_ARM_TLS.
 */
final class Aarch64LinuxThreadPointerRetriever implements ThreadPointerRetrieverInterface
{
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
            $value = $this->ptrace->readTlsRegister($pid);
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
}
