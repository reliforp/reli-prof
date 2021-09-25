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
use PhpProfiler\Lib\Libc\Errno\Errno;
use PhpProfiler\Lib\Libc\Sys\Ptrace\PtraceRequest;
use PhpProfiler\Lib\Libc\Sys\Ptrace\PtraceX64;

final class ProcessStopper
{
    public function __construct(
        private PtraceX64 $ptrace,
        private Errno $errno,
    ) {
    }

    public function stop(int $pid): bool
    {
        /** @var \FFI\Libc\ptrace_ffi $this->ffi */
        $attach = $this->ptrace->ptrace(
            PtraceRequest::PTRACE_ATTACH(),
            $pid,
            null,
            null
        );

        if ($attach === -1) {
            $errno = $this->errno->get();
            if ($errno) {
                return false;
            }
        }
        pcntl_waitpid($pid, $status, WUNTRACED);
        return true;
    }

    public function resume(int $pid): void
    {
        /** @var \FFI\Libc\ptrace_ffi $this->ffi */
        $detach = $this->ptrace->ptrace(
            PtraceRequest::PTRACE_DETACH(),
            $pid,
            null,
            null
        );

        if ($detach === -1) {
            $errno = $this->errno->get();
            if ($errno) {
                return;
            }
        }
    }
}
