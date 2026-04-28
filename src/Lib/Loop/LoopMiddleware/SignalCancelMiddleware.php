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

namespace Reli\Lib\Loop\LoopMiddleware;

use Reli\Lib\Loop\LoopMiddlewareInterface;

/**
 * Returns false from the loop on SIGINT / SIGTERM so callers can run their
 * usual end-of-loop finalization (e.g. BinaryTraceOutput::finish() to flush
 * the run-length-encoded sample buffer and write CHECKPOINT + SEGMENT_END).
 *
 * Without this, Ctrl-C / `kill <pid>` exits PHP before destructors run and
 * the .rbt file is left as just header + definitions with zero samples on
 * disk.
 */
final class SignalCancelMiddleware implements LoopMiddlewareInterface
{
    private bool $cancelled = false;

    public function __construct(
        private LoopMiddlewareInterface $chain,
    ) {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        $handler = function (): void {
            $this->cancelled = true;
        };
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
    }

    #[\Override]
    public function invoke(): bool
    {
        if ($this->cancelled) {
            return false;
        }
        return $this->chain->invoke();
    }
}
