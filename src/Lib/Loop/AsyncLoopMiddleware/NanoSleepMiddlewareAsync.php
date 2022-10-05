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

namespace Reli\Lib\Loop\AsyncLoopMiddleware;

use Reli\Lib\Loop\AsyncLoopMiddlewareInterface;

use function hrtime;
use function time_nanosleep;

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
        $start = hrtime(true);
        yield from $this->chain->invoke();

        $wait = $this->sleep_nano_seconds - (hrtime(true) - $start);
        if ($wait > 0) {
            /**
             * @psalm-suppress UnusedFunctionCall
             * @psalm-suppress InvalidArgument
             */
            time_nanosleep(0, $wait);
        }
    }
}
