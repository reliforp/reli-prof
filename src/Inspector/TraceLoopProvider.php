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

namespace Reli\Inspector;

use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use Reli\Lib\Console\EchoBackCanceller;
use Reli\Lib\Loop\Loop;
use Reli\Lib\Loop\LoopBuilder;
use Reli\Lib\Loop\LoopMiddleware\ExitLoopOnSpecificExceptionMiddleware;
use Reli\Lib\Loop\LoopMiddleware\CallableMiddleware;
use Reli\Lib\Loop\LoopMiddleware\KeyboardCancelMiddleware;
use Reli\Lib\Loop\LoopMiddleware\NanoSleepMiddleware;
use Reli\Lib\Loop\LoopMiddleware\RetryOnExceptionMiddleware;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;
use Reli\Lib\Process\ProcessNotFoundException;

final class TraceLoopProvider
{
    public function __construct(
        private LoopBuilder $loop_builder
    ) {
    }

    public function getMainLoop(callable $main, TraceLoopSettings $settings): Loop
    {
        return $this->loop_builder
            ->addProcess(ExitLoopOnSpecificExceptionMiddleware::class, [[ProcessNotFoundException::class]])
            ->addProcess(RetryOnExceptionMiddleware::class, [$settings->max_retries, [MemoryReaderException::class]])
            ->addProcess(KeyboardCancelMiddleware::class, [$settings->cancel_key, new EchoBackCanceller()])
            ->addProcess(NanoSleepMiddleware::class, [$settings->sleep_nano_seconds])
            ->addProcess(CallableMiddleware::class, [$main])
            ->build();
    }
}
