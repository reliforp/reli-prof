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

use Hamcrest\Type\IsCallable;
use PhpProfiler\Lib\Libc\Errno\Errno;
use PhpProfiler\Lib\Libc\Sys\Ptrace\PtraceX64;
use PhpProfiler\Lib\Libc\Unistd\Execvp;
use PhpProfiler\Lib\Process\Exec\Internal\Pcntl;
use PhpProfiler\Lib\Process\ProcessStopper\ProcessStopper;
use PhpProfiler\Lib\System\OnShutdown;
use PHPUnit\Framework\TestCase;

class TraceeExecutorTest extends TestCase
{
    public function testExecute()
    {
        $on_shutdown = \Mockery::mock(OnShutdown::class);
        $on_shutdown->expects()->register(new IsCallable());
        $executor = new TraceeExecutor(
            new Pcntl(),
            new PtraceX64(),
            new Execvp(),
            new Errno(),
            $on_shutdown
        );

        $stopper = new ProcessStopper(
            new PtraceX64(),
            new Errno(),
        );
        $pid = $executor->execute('ls', []);
        self::assertSame(true, $stopper->stop($pid));
        posix_kill($pid, SIGKILL);
    }
}
