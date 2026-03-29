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

final class FunctionDetectionTrigger implements TriggerInterface
{
    public function __construct(
        private string $function_name,
    ) {
    }

    #[\Override]
    public function name(): string
    {
        return 'watch-function';
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

        foreach ($context->call_trace->call_frames as $frame) {
            $fqn = $frame->getFullyQualifiedFunctionName();
            if ($fqn === $this->function_name) {
                return new TriggerEvent(
                    trigger_name: $this->name(),
                    description: sprintf(
                        'function="%s" depth=%d',
                        $fqn,
                        count($context->call_trace->call_frames),
                    ),
                    timestamp: $context->timestamp,
                );
            }
        }
        return null;
    }
}
