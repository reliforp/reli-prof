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

namespace Reli\Inspector\Daemon\Reader\Worker;

use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use Reli\Lib\Loop\AsyncLoop;
use Reli\Lib\Loop\AsyncLoopBuilder;
use Reli\Lib\Loop\AsyncLoopMiddleware\CallableMiddlewareAsync;
use Reli\Lib\Loop\AsyncLoopMiddleware\NanoSleepMiddlewareAsync;
use Reli\Lib\Loop\AsyncLoopMiddleware\RetryOnExceptionMiddlewareAsync;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;

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
