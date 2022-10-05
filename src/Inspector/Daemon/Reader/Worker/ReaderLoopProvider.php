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

namespace PhpProfiler\Inspector\Daemon\Reader\Worker;

use PhpProfiler\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use PhpProfiler\Lib\Loop\AsyncLoop;
use PhpProfiler\Lib\Loop\AsyncLoopBuilder;
use PhpProfiler\Lib\Loop\AsyncLoopMiddleware\CallableMiddlewareAsync;
use PhpProfiler\Lib\Loop\AsyncLoopMiddleware\NanoSleepMiddlewareAsync;
use PhpProfiler\Lib\Loop\AsyncLoopMiddleware\RetryOnExceptionMiddlewareAsync;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;

final class ReaderLoopProvider
{
    public function __construct(
        private AsyncLoopBuilder $loop_builder
    ) {
    }

    public function getMainLoop(callable $main, TraceLoopSettings $settings): AsyncLoop
    {
        return $this->loop_builder
            ->addProcess(
                RetryOnExceptionMiddlewareAsync::class,
                [
                    $settings->max_retries,
                    [MemoryReaderException::class]
                ]
            )
            ->addProcess(NanoSleepMiddlewareAsync::class, [$settings->sleep_nano_seconds])
            ->addProcess(CallableMiddlewareAsync::class, [$main])
            ->build();
    }
}
