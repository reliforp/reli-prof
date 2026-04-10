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

namespace Reli\Inspector\Watch\Daemon\Protocol\Message;

/**
 * Notification from worker to controller about trace session lifecycle.
 *
 * Sent when the worker autonomously starts or stops a continuous trace
 * session based on trigger state transitions.
 */
final class WatchTraceNotifyMessage implements WatchWorkerMessage
{
    public function __construct(
        public readonly int $pid,
        public readonly bool $started,
        public readonly ?string $trace_path,
    ) {
    }
}
