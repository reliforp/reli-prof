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

/**
 * Fires when process CPU usage exceeds a threshold.
 *
 * Supports hysteresis: enter threshold can differ from exit threshold
 * to prevent rapid on/off toggling around a single boundary.
 *
 * Supports sustain duration: the enter condition must be met
 * continuously for a specified duration before the trigger activates.
 *
 * Once active, the trigger stays active (returns TriggerEvent) until
 * CPU drops below the exit threshold.
 */
final class CpuUsageTrigger implements TriggerInterface
{
    private bool $is_active = false;
    private ?float $sustained_since = null;

    /**
     * @param float $enter_percent  CPU% threshold to enter (e.g. 80.0)
     * @param float $exit_percent   CPU% threshold to exit  (e.g. 60.0)
     * @param float $sustain_seconds How long the enter condition must hold (0 = immediate)
     */
    public function __construct(
        private float $enter_percent,
        private float $exit_percent,
        private float $sustain_seconds = 0.0,
    ) {
    }

    #[\Override]
    public function name(): string
    {
        return 'cpu-usage';
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
        $cpu = $context->cpu_usage_percent;
        if ($cpu === null) {
            return null;
        }

        if (!$this->is_active) {
            // Check enter condition
            if ($cpu >= $this->enter_percent) {
                if ($this->sustain_seconds > 0.0) {
                    if ($this->sustained_since === null) {
                        $this->sustained_since = $context->timestamp;
                    }
                    if ($context->timestamp - $this->sustained_since < $this->sustain_seconds) {
                        return null; // not sustained long enough yet
                    }
                }
                $this->is_active = true;
                $this->sustained_since = null;
                return new TriggerEvent(
                    trigger_name: $this->name(),
                    description: sprintf(
                        'cpu=%.1f%%>=%.0f%%',
                        $cpu,
                        $this->enter_percent,
                    ),
                    timestamp: $context->timestamp,
                    value: $cpu,
                );
            }
            // Below enter threshold — reset sustain timer
            $this->sustained_since = null;
            return null;
        }

        // Active: check exit condition
        if ($cpu < $this->exit_percent) {
            $this->is_active = false;
            $this->sustained_since = null;
            return null;
        }

        return new TriggerEvent(
            trigger_name: $this->name(),
            description: sprintf(
                'cpu=%.1f%% (active, exit<%.0f%%)',
                $cpu,
                $this->exit_percent,
            ),
            timestamp: $context->timestamp,
            value: $cpu,
        );
    }
}
