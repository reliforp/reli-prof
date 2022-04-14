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

namespace PhpProfiler\Lib\Loop\AsyncLoopMiddleware;

use PhpProfiler\Lib\Loop\AsyncLoopMiddlewareInterface;

final class NanoSleepMiddlewareAsync implements AsyncLoopMiddlewareInterface
{
    /** @param positive-int $sleep_nano_seconds */
    public function __construct(
        private int $sleep_nano_seconds,
        private AsyncLoopMiddlewareInterface $chain
    ) {
    }

    public function invoke(): \Generator
    {
        $start = \hrtime(true);
        yield from $this->chain->invoke();
        /**
         * @psalm-suppress UnusedFunctionCall
         * @psalm-suppress InvalidArgument
         */
        time_nanosleep(0, $this->sleep_nano_seconds - (\hrtime(true) - $start));
    }
}
