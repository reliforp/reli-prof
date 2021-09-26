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

use PhpProfiler\Lib\Loop\LoopBuilder;
use PhpProfiler\Lib\Loop\LoopMiddleware\CallableMiddleware;
use PhpProfiler\Lib\Loop\LoopMiddleware\NanoSleepMiddleware;
use PhpProfiler\Lib\Loop\LoopMiddleware\RetryOnExceptionMiddleware;

class RetryingLoopProvider
{
    public function __construct(
        private LoopBuilder $loop_builder
    ) {
    }

    /** @param class-string<\Throwable>[] $retry_on */
    public function do(
        callable $try,
        array $retry_on,
        int $max_retry,
        int $interval_on_retry_ns,
    ): void {
        // one successful execution is enough
        $loop_canceller = function () use ($try): bool {
            $try();
            return false;
        };

        $this->loop_builder
            ->addProcess(RetryOnExceptionMiddleware::class, [$max_retry, $retry_on])
            ->addProcess(NanoSleepMiddleware::class, [$interval_on_retry_ns])
            ->addProcess(CallableMiddleware::class, [$loop_canceller])
            ->build()
            ->invoke();
    }
}
