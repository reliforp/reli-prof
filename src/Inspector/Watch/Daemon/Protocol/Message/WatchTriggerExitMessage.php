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

use Reli\Inspector\Watch\TriggerEvent;

/**
 * Sent from worker to controller when a trigger condition clears (EXIT phase).
 *
 * This allows the controller to execute on-exit one-shot actions (log, exec)
 * at the moment the condition is no longer met, not just on process detach.
 */
final class WatchTriggerExitMessage
{
    public function __construct(
        public readonly int $pid,
        public readonly TriggerEvent $event,
    ) {
    }
}
