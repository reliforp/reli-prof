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

use Reli\Inspector\Watch\TriggerEvent;
use Reli\Inspector\Watch\WatchContext;

final class TraceDepthTrigger implements TriggerInterface
{
    public function __construct(
        private int $max_depth,
    ) {
    }

    #[\Override]
    public function name(): string
    {
        return 'trace-depth-limit';
    }

    #[\Override]
    public function requiresCallTrace(): bool
    {
        return true;
    }

    #[\Override]
    public function requiresDeepInspection(): bool
    {
        return false;
    }

    #[\Override]
    public function evaluate(WatchContext $context): ?TriggerEvent
    {
        if ($context->call_trace === null) {
            return null;
        }

        $depth = count($context->call_trace->call_frames);
        if ($depth > $this->max_depth) {
            return new TriggerEvent(
                trigger_name: $this->name(),
                description: sprintf('depth=%d>%d', $depth, $this->max_depth),
                timestamp: $context->timestamp,
                value: (float)$depth,
            );
        }
        return null;
    }
}
