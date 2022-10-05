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

use Reli\Lib\Loop\LoopBuilder;
use Reli\Lib\Loop\LoopMiddleware\CallableMiddleware;
use Reli\Lib\Loop\LoopMiddleware\NanoSleepMiddleware;
use Reli\Lib\Loop\LoopMiddleware\RetryOnExceptionMiddleware;

class RetryingLoopProvider
{
    public function __construct(
        private LoopBuilder $loop_builder
    ) {
    }

    /**
     * @template T
     * @param callable():T $try
     * @param class-string<\Throwable>[] $retry_on
     * @return T
     */
    public function do(
        callable $try,
        array $retry_on,
        int $max_retry,
        int $interval_on_retry_ns,
    ) {
        $result = null;

        $loop_canceller =
            /** @param-out T $result */
            function () use (&$result, $try): bool {
                $result = $try();
                // one successful execution is enough
                return false;
            }
        ;

        $this->loop_builder
            ->addProcess(RetryOnExceptionMiddleware::class, [$max_retry, $retry_on])
            ->addProcess(NanoSleepMiddleware::class, [$interval_on_retry_ns])
            ->addProcess(CallableMiddleware::class, [$loop_canceller])
            ->build()
            ->invoke();

        /** @var T */
        return $result;
    }
}
