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

namespace PhpProfiler\Lib\Process\Exec;

use PhpProfiler\Lib\Libc\Errno\Errno;
use PhpProfiler\Lib\Libc\Sys\Ptrace\Ptrace;
use PhpProfiler\Lib\Libc\Sys\Ptrace\PtraceRequest;
use PhpProfiler\Lib\Libc\Unistd\Execvp;
use PhpProfiler\Lib\Process\Exec\Internal\Pcntl;
use PhpProfiler\Lib\System\OnShutdown;

final class TraceeExecutor
{
    public function __construct(
        private Pcntl $pcntl,
        private Ptrace $ptrace,
        private Execvp $execvp,
        private Errno $errno,
        private OnShutdown $on_shutdown,
    ) {
    }

    /** @param list<string> $argv */
    public function execute(string $command, array $argv): int
    {
        $pid = $this->pcntl->fork();
        if ($pid === 0) {
            $this->ptrace->ptrace(
                PtraceRequest::PTRACE_PTRACEME(),
                0,
                null,
                null
            );
            $result = $this->execvp->execvp($command, $argv);
            throw new TraceeExecutorException(
                "error on executing child process ({$result})({$this->errno->get()})"
            );
        } elseif ($pid < 0) {
            throw new TraceeExecutorException(
                "error on forking child process ({$this->errno->get()})"
            );
        }
        $this->pcntl->waitpid($pid, $status, WUNTRACED);

        if (!$this->pcntl->wifstopped($status)) {
            throw new TraceeExecutorException(
                "cannot stop child process"
            );
        }
        if ($this->pcntl->wstopsig($status) !== \SIGTRAP) {
            $signal = $this->pcntl->wstopsig($status);
            throw new TraceeExecutorException(
                "unexpected signal ({$signal})"
            );
        }

        $this->ptrace->ptrace(
            PtraceRequest::PTRACE_DETACH(),
            $pid,
            0,
            0
        );

        $this->on_shutdown->register(
            function () use ($pid) {
                $this->pcntl->waitpid($pid, $status, 0);
            }
        );
        return $pid;
    }
}
