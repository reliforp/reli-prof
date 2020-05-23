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

namespace PhpProfiler\Lib\Timer;

use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;

final class PeriodicInvoker
{
    public function runPeriodically(int $sleep_nano_seconds, callable $func): void
    {
        exec('stty -icanon -echo');
        $keyboard_input = fopen('php://stdin', 'r');
        stream_set_blocking($keyboard_input, false);

        $key = '';
        $count_retry = 0;
        while ($key !== 'q' and $count_retry < 10) {
            try {
                $func();
                $count_retry = 0;
                time_nanosleep(0, $sleep_nano_seconds);
            } catch (MemoryReaderException $e) {
                $count_retry++;
            }
            $key = fread($keyboard_input, 1);
        }
    }
}
