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

namespace Reli\Command\PhpSpy;

/**
 * Tracks hrtime() between calls and returns the microsecond delta.
 * The first call returns 0 (no previous reference). Subsequent calls
 * return the elapsed microseconds since the previous advance().
 *
 * Used to attach approximate timestamps to phpspy-sourced traces in
 * `--rbt-timestamps=delta` mode. The delta is "when reli observed the
 * trace on phpspy's stdout", which lags actual sample time by at most
 * the poll interval (1ms) plus phpspy's stdio buffer flush.
 */
final class HrtimeDeltaClock
{
    private ?int $last_ns = null;

    public function advance(): int
    {
        $now_ns = hrtime(true);
        $delta_us = 0;
        if ($this->last_ns !== null) {
            $delta_us = (int) (($now_ns - $this->last_ns) / 1000);
        }
        $this->last_ns = $now_ns;
        return $delta_us;
    }
}
