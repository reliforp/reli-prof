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

final class RssUsageTrigger implements TriggerInterface
{
    public function __construct(
        private int $limit_bytes,
    ) {
    }

    #[\Override]
    public function name(): string
    {
        return 'rss-usage';
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
        if ($context->rss_bytes === null) {
            return null;
        }
        if ($context->rss_bytes > $this->limit_bytes) {
            return new TriggerEvent(
                trigger_name: $this->name(),
                description: sprintf(
                    'rss=%s>%s',
                    HeapStats::humanReadableBytes($context->rss_bytes),
                    HeapStats::humanReadableBytes($this->limit_bytes),
                ),
                timestamp: $context->timestamp,
                value: (float)$context->rss_bytes,
            );
        }
        return null;
    }
}
