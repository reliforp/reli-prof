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

namespace Reli\Inspector\Watch\Trigger;

use Reli\Inspector\Watch\HeapStats;
use Reli\Inspector\Watch\TriggerEvent;
use Reli\Inspector\Watch\WatchContext;

final class MemoryPeakTrigger implements TriggerInterface
{
    #[\Override]
    public function name(): string
    {
        return 'memory-peak-watch';
    }

    #[\Override]
    public function requiresCallTrace(): bool
    {
        return false;
    }

    #[\Override]
    public function requiresDeepInspection(): bool
    {
        return false;
    }

    #[\Override]
    public function evaluate(WatchContext $context): ?TriggerEvent
    {
        if ($context->previous === null) {
            return null;
        }

        if ($context->heap_stats->peak !== $context->previous->heap_stats->peak) {
            return new TriggerEvent(
                trigger_name: $this->name(),
                description: sprintf(
                    'peak=%s (was %s)',
                    HeapStats::humanReadableBytes($context->heap_stats->peak),
                    HeapStats::humanReadableBytes($context->previous->heap_stats->peak),
                ),
                timestamp: $context->timestamp,
                value: (float)$context->heap_stats->peak,
            );
        }
        return null;
    }
}
