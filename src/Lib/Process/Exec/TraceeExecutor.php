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

namespace Reli\Lib\Process\Exec;

use Reli\Lib\Libc\Errno\Errno;
use Reli\Lib\Libc\Sys\Ptrace\Ptrace;
use Reli\Lib\Libc\Sys\Ptrace\PtraceRequest;
use Reli\Lib\Libc\Unistd\Dup2;
use Reli\Lib\Libc\Unistd\Execvp;
use Reli\Lib\Process\Exec\Internal\Pcntl;
use Reli\Lib\System\OnShutdown;

/** @psalm-suppress ClassMustBeFinal */
class TraceeExecutor
{
    public function __construct(
        private Pcntl $pcntl,
        private Ptrace $ptrace,
        private Execvp $execvp,
        private Errno $errno,
        private OnShutdown $on_shutdown,
        private Dup2 $dup2 = new Dup2(),
    ) {
    }

    /** @param list<string> $argv */
    public function execute(
        string $command,
        array $argv,
        ?string $child_stdout = null,
        ?string $child_stderr = null,
    ): int {
        $pid = $this->pcntl->fork();
        if ($pid === 0) {
            if ($child_stdout !== null) {
                $this->dup2->redirectToFile($child_stdout, Dup2::STDOUT_FILENO);
            }
            if ($child_stderr !== null) {
                $this->dup2->redirectToFile($child_stderr, Dup2::STDERR_FILENO);
            }
            $this->ptrace->ptrace(
                PtraceRequest::PTRACE_PTRACEME,
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
            PtraceRequest::PTRACE_DETACH,
            $pid,
            0,
            0
        );

        $this->on_shutdown->register(
            function () use ($pid) {
                // The child runs the user-supplied command (commonly an
                // infinite-loop PHP smoke-test target); a plain blocking
                // waitpid would hang reli forever after `q`-cancel. Signal
                // first, escalate to SIGKILL if SIGTERM is ignored, then reap.
                if ($this->pcntl->waitpid($pid, $status, \WNOHANG) === 0) {
                    $this->pcntl->kill($pid, \SIGTERM);
                    for ($i = 0; $i < 20; $i++) {
                        if ($this->pcntl->waitpid($pid, $status, \WNOHANG) !== 0) {
                            return;
                        }
                        \usleep(50_000);
                    }
                    $this->pcntl->kill($pid, \SIGKILL);
                    $this->pcntl->waitpid($pid, $status, 0);
                }
            }
        );
        return $pid;
    }
}
