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

final class ExceptionDetectionTrigger implements TriggerInterface
{
    #[\Override]
    public function name(): string
    {
        return 'on-exception';
    }

    #[\Override]
    public function requiresCallTrace(): bool
    {
        return false;
    }

    #[\Override]
    public function requiresDeepInspection(): bool
    {
        return true;
    }

    #[\Override]
    public function evaluate(WatchContext $context): ?TriggerEvent
    {
        if ($context->has_exception === true) {
            return new TriggerEvent(
                trigger_name: $this->name(),
                description: 'exception in flight',
                timestamp: $context->timestamp,
            );
        }
        return null;
    }
}
