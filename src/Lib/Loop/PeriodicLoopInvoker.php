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

namespace PhpProfiler\Lib\Loop;

final class PeriodicLoopInvoker
{
    public function runPeriodically(int $sleep_nano_seconds, LoopProcessInterface $invokee): void
    {
        while (1) {
            if (!$invokee->invoke()) {
                break;
            }
            time_nanosleep(0, $sleep_nano_seconds);
        }
    }
}
