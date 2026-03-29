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

final class MemoryLimitTrigger implements TriggerInterface
{
    public function __construct(
        private int $limit_bytes,
    ) {
    }

    #[\Override]
    public function name(): string
    {
        return 'memory-limit';
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
        if ($context->heap_stats->size > $this->limit_bytes) {
            return new TriggerEvent(
                trigger_name: $this->name(),
                description: sprintf(
                    'mem=%s>%s',
                    HeapStats::humanReadableBytes($context->heap_stats->size),
                    HeapStats::humanReadableBytes($this->limit_bytes),
                ),
                timestamp: $context->timestamp,
                value: (float)$context->heap_stats->size,
            );
        }
        return null;
    }
}
