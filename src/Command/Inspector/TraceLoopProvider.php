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

namespace PhpProfiler\Command\Inspector;

use PhpProfiler\Command\Inspector\Settings\LoopSettings;
use PhpProfiler\Lib\Loop\Loop;
use PhpProfiler\Lib\Loop\LoopBuilder;
use PhpProfiler\Lib\Loop\LoopProcess\CallableLoop;
use PhpProfiler\Lib\Loop\LoopProcess\KeyboardCancelLoop;
use PhpProfiler\Lib\Loop\LoopProcess\NanoSleepLoop;
use PhpProfiler\Lib\Loop\LoopProcess\RetryOnExceptionLoop;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;

final class TraceLoopProvider
{
    private LoopBuilder $loop_builder;

    public function __construct(LoopBuilder $loop_builder)
    {
        $this->loop_builder = $loop_builder;
    }

    public function getMainLoop(callable $main, LoopSettings $settings): Loop
    {
        return $this->loop_builder->addProcess(NanoSleepLoop::class, [$settings->sleep_nano_seconds])
            ->addProcess(RetryOnExceptionLoop::class, [$settings->max_retries, [MemoryReaderException::class]])
            ->addProcess(KeyboardCancelLoop::class, [$settings->cancel_key])
            ->addProcess(CallableLoop::class, [$main])
            ->build();
    }
}
