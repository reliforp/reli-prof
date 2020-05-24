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

namespace PhpProfiler\Lib\Loop\LoopProcess;

use PhpProfiler\Lib\Loop\LoopProcessInterface;

final class NanoSleepLoop implements LoopProcessInterface
{
    private int $sleep_nano_seconds;
    private LoopProcessInterface $chain;

    public function __construct(int $sleep_nano_seconds, LoopProcessInterface $chain)
    {
        $this->sleep_nano_seconds = $sleep_nano_seconds;
        $this->chain = $chain;
    }

    public function invoke(): bool
    {
        if (!$this->chain->invoke()) {
            return false;
        }
        time_nanosleep(0, $this->sleep_nano_seconds);
        return true;
    }
}
