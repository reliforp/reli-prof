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

use Reli\BaseTestCase;
use Reli\Lib\Libc\Errno\Errno;
use Reli\Lib\Libc\Sys\Ptrace\PtraceX64;
use Reli\Lib\Libc\Unistd\Dup2;
use Reli\Lib\Libc\Unistd\Execvp;
use Reli\Lib\Process\Exec\Internal\Pcntl;
use Reli\Lib\System\OnShutdown;

class TraceeExecutorChildOutputTest extends BaseTestCase
{
    /** @var list<string> */
    private array $temp_files = [];

    protected function tearDown(): void
    {
        foreach ($this->temp_files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function tempFile(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        assert($path !== false);
        $this->temp_files[] = $path;
        return $path;
    }

    public function testChildStdoutRedirect(): void
    {
        $stdout_path = $this->tempFile('reli_child_stdout_');

        $on_shutdown = new OnShutdown();
        $executor = new TraceeExecutor(
            new Pcntl(),
            new PtraceX64(),
            new Execvp(),
            new Errno(),
            $on_shutdown,
            new Dup2(),
        );

        $pid = $executor->execute(
            'sh',
            ['-c', 'echo hello_from_child'],
            $stdout_path,
            null,
        );

        // Wait for the child process to finish
        pcntl_waitpid($pid, $status, 0);

        $this->assertFileExists($stdout_path);
        $content = file_get_contents($stdout_path);
        $this->assertSame("hello_from_child\n", $content);
    }

    public function testChildStderrRedirect(): void
    {
        $stderr_path = $this->tempFile('reli_child_stderr_');

        $on_shutdown = new OnShutdown();
        $executor = new TraceeExecutor(
            new Pcntl(),
            new PtraceX64(),
            new Execvp(),
            new Errno(),
            $on_shutdown,
            new Dup2(),
        );

        $pid = $executor->execute(
            'sh',
            ['-c', 'echo error_from_child >&2'],
            null,
            $stderr_path,
        );

        pcntl_waitpid($pid, $status, 0);

        $this->assertFileExists($stderr_path);
        $content = file_get_contents($stderr_path);
        $this->assertSame("error_from_child\n", $content);
    }

    public function testChildStdoutAndStderrRedirect(): void
    {
        $stdout_path = $this->tempFile('reli_child_stdout_');
        $stderr_path = $this->tempFile('reli_child_stderr_');

        $on_shutdown = new OnShutdown();
        $executor = new TraceeExecutor(
            new Pcntl(),
            new PtraceX64(),
            new Execvp(),
            new Errno(),
            $on_shutdown,
            new Dup2(),
        );

        $pid = $executor->execute(
            'sh',
            ['-c', 'echo out_msg; echo err_msg >&2'],
            $stdout_path,
            $stderr_path,
        );

        pcntl_waitpid($pid, $status, 0);

        $this->assertFileExists($stdout_path);
        $this->assertFileExists($stderr_path);
        $this->assertSame("out_msg\n", file_get_contents($stdout_path));
        $this->assertSame("err_msg\n", file_get_contents($stderr_path));
    }

    public function testChildOutputWithoutRedirect(): void
    {
        $on_shutdown = new OnShutdown();
        $executor = new TraceeExecutor(
            new Pcntl(),
            new PtraceX64(),
            new Execvp(),
            new Errno(),
            $on_shutdown,
            new Dup2(),
        );

        // Without redirect, the child should still run successfully
        $pid = $executor->execute(
            'sh',
            ['-c', 'true'],
            null,
            null,
        );

        pcntl_waitpid($pid, $status, 0);
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
    }
}
