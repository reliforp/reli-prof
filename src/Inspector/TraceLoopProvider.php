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

namespace PhpProfiler\Inspector;

use PhpProfiler\Inspector\Settings\TraceLoopSettings;
use PhpProfiler\Lib\Loop\Loop;
use PhpProfiler\Lib\Loop\LoopBuilder;
use PhpProfiler\Lib\Loop\LoopMiddleware\CallableMiddleware;
use PhpProfiler\Lib\Loop\LoopMiddleware\KeyboardCancelMiddleware;
use PhpProfiler\Lib\Loop\LoopMiddleware\NanoSleepMiddleware;
use PhpProfiler\Lib\Loop\LoopMiddleware\RetryOnExceptionMiddleware;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;

final class TraceLoopProvider
{
    private LoopBuilder $loop_builder;

    public function __construct(LoopBuilder $loop_builder)
    {
        $this->loop_builder = $loop_builder;
    }

    public function getMainLoop(callable $main, TraceLoopSettings $settings): Loop
    {
        return $this->loop_builder
            ->addProcess(RetryOnExceptionMiddleware::class, [$settings->max_retries, [MemoryReaderException::class]])
            ->addProcess(KeyboardCancelMiddleware::class, [$settings->cancel_key])
            ->addProcess(NanoSleepMiddleware::class, [$settings->sleep_nano_seconds])
            ->addProcess(CallableMiddleware::class, [$main])
            ->build();
    }
}
