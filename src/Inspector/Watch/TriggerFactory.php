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

namespace Reli\Inspector\Watch;

use Reli\Inspector\Settings\WatchSettings\WatchSettings;
use Reli\Inspector\Watch\Trigger\FunctionDetectionTrigger;
use Reli\Inspector\Watch\Trigger\MemoryGrowthRateTrigger;
use Reli\Inspector\Watch\Trigger\MemoryUsageTrigger;
use Reli\Inspector\Watch\Trigger\MemoryPeakTrigger;
use Reli\Inspector\Watch\Trigger\TraceDepthTrigger;
use Reli\Inspector\Watch\Trigger\TriggerInterface;
use Reli\Inspector\Watch\Trigger\VariableValueTrigger;

final class TriggerFactory
{
    /**
     * @return list<TriggerInterface>
     */
    public function build(WatchSettings $settings): array
    {
        $triggers = [];

        // Tier 1 triggers
        if ($settings->memory_usage_bytes !== null) {
            $triggers[] = new MemoryUsageTrigger(
                $settings->memory_usage_bytes,
            );
        }
        if ($settings->memory_growth_rate !== null) {
            [$bytes, $seconds] = MemoryGrowthRateTrigger::parseRate(
                $settings->memory_growth_rate,
            );
            $triggers[] = new MemoryGrowthRateTrigger($bytes, $seconds);
        }
        if ($settings->memory_peak_watch) {
            $triggers[] = new MemoryPeakTrigger();
        }

        // Tier 2 triggers
        if ($settings->watch_function !== null) {
            $triggers[] = new FunctionDetectionTrigger(
                $settings->watch_function,
            );
        }
        if ($settings->trace_depth_limit !== null) {
            $triggers[] = new TraceDepthTrigger(
                $settings->trace_depth_limit,
            );
        }

        // Tier 3 triggers
        foreach ($settings->watch_var as $expr) {
            $triggers[] = new VariableValueTrigger($expr);
        }

        return $triggers;
    }
}
