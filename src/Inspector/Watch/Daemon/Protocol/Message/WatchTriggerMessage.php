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

use Reli\Inspector\Watch\HeapStats;
use Reli\Inspector\Watch\TriggerEvent;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;

final class WatchTriggerMessage implements WatchWorkerMessage
{
    public function __construct(
        public readonly int $pid,
        public readonly TriggerEvent $event,
        public readonly HeapStats $heap_stats,
        public readonly ?CallTrace $call_trace,
        public readonly int $eg_address = 0,
        public readonly int $cg_address = 0,
        public readonly string $php_version = '',
    ) {
    }
}
