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

namespace PhpProfiler\Lib\Loop\LoopMiddleware;

use PhpProfiler\Lib\Loop\LoopMiddlewareInterface;

use function hrtime;
use function time_nanosleep;

final class NanoSleepMiddleware implements LoopMiddlewareInterface
{
    /** @param positive-int $sleep_nano_seconds */
    public function __construct(
        private int $sleep_nano_seconds,
        private LoopMiddlewareInterface $chain,
    ) {
    }

    public function invoke(): bool
    {
        $start = hrtime(true);
        if (!$this->chain->invoke()) {
            return false;
        }
        $wait = $this->sleep_nano_seconds - (hrtime(true) - $start);
        if ($wait > 0) {
            /**
             * @psalm-suppress UnusedFunctionCall
             * @psalm-suppress InvalidArgument
             */
            time_nanosleep(0, $wait);
        }
        return true;
    }
}
