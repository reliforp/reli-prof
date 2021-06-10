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

final class NanoSleepMiddleware implements LoopMiddlewareInterface
{
    private int $sleep_nano_seconds;
    private LoopMiddlewareInterface $chain;

    public function __construct(int $sleep_nano_seconds, LoopMiddlewareInterface $chain)
    {
        $this->sleep_nano_seconds = $sleep_nano_seconds;
        $this->chain = $chain;
    }

    public function invoke(): bool
    {
        /** @psalm-suppress UnusedFunctionCall */
        time_nanosleep(0, $this->sleep_nano_seconds);
        if (!$this->chain->invoke()) {
            return false;
        }
        return true;
    }
}
