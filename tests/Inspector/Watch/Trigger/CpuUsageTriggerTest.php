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

use PHPUnit\Framework\TestCase;
use Reli\Inspector\Watch\HeapStats;
use Reli\Inspector\Watch\WatchContext;

class CpuUsageTriggerTest extends TestCase
{
    public function testProperties(): void
    {
        $trigger = new CpuUsageTrigger(80.0, 60.0);
        $this->assertSame('cpu-usage', $trigger->name());
        $this->assertFalse($trigger->requiresCallTrace());
        $this->assertFalse($trigger->requiresDeepInspection());
    }

    public function testDoesNotFireWhenCpuIsNull(): void
    {
        $trigger = new CpuUsageTrigger(80.0, 60.0);
        $context = $this->makeContext(null, 1.0);

        $this->assertNull($trigger->evaluate($context));
    }

    public function testDoesNotFireBelowEnterThreshold(): void
    {
        $trigger = new CpuUsageTrigger(80.0, 60.0);

        $this->assertNull($trigger->evaluate($this->makeContext(50.0, 1.0)));
        $this->assertNull($trigger->evaluate($this->makeContext(79.9, 2.0)));
    }

    public function testFiresAtEnterThreshold(): void
    {
        $trigger = new CpuUsageTrigger(80.0, 60.0);
        $event = $trigger->evaluate($this->makeContext(80.0, 1.0));

        $this->assertNotNull($event);
        $this->assertSame('cpu-usage', $event->trigger_name);
        $this->assertSame(80.0, $event->value);
    }

    public function testStaysActiveAboveExitThreshold(): void
    {
        $trigger = new CpuUsageTrigger(80.0, 60.0);

        // Enter
        $event = $trigger->evaluate($this->makeContext(85.0, 1.0));
        $this->assertNotNull($event);

        // Still active (above exit threshold of 60%)
        $event = $trigger->evaluate($this->makeContext(65.0, 2.0));
        $this->assertNotNull($event);
        $this->assertSame(65.0, $event->value);
    }

    public function testExitsBelowExitThreshold(): void
    {
        $trigger = new CpuUsageTrigger(80.0, 60.0);

        // Enter
        $this->assertNotNull($trigger->evaluate($this->makeContext(85.0, 1.0)));

        // Exit (below exit threshold of 60%)
        $this->assertNull($trigger->evaluate($this->makeContext(55.0, 2.0)));
    }

    public function testHysteresis(): void
    {
        $trigger = new CpuUsageTrigger(80.0, 60.0);

        // Enter at 85%
        $this->assertNotNull($trigger->evaluate($this->makeContext(85.0, 1.0)));

        // Drop to 65% — still active (between exit and enter)
        $this->assertNotNull($trigger->evaluate($this->makeContext(65.0, 2.0)));

        // Drop to 55% — exits
        $this->assertNull($trigger->evaluate($this->makeContext(55.0, 3.0)));

        // Rise to 70% — still inactive (below enter threshold)
        $this->assertNull($trigger->evaluate($this->makeContext(70.0, 4.0)));

        // Rise to 82% — enters again
        $this->assertNotNull($trigger->evaluate($this->makeContext(82.0, 5.0)));
    }

    public function testSustainDuration(): void
    {
        $trigger = new CpuUsageTrigger(80.0, 60.0, sustain_seconds: 3.0);

        // Above threshold but not sustained long enough
        $this->assertNull($trigger->evaluate($this->makeContext(85.0, 1.0)));
        $this->assertNull($trigger->evaluate($this->makeContext(90.0, 2.0)));
        $this->assertNull($trigger->evaluate($this->makeContext(85.0, 3.0)));

        // Now sustained for 3 seconds (3.0 - 1.0 = 2.0 < 3.0, so still waiting)
        // At t=4.0 we have 4.0 - 1.0 = 3.0 >= 3.0 → enter
        $event = $trigger->evaluate($this->makeContext(82.0, 4.0));
        $this->assertNotNull($event);
    }

    public function testSustainResetWhenCpuDrops(): void
    {
        $trigger = new CpuUsageTrigger(80.0, 60.0, sustain_seconds: 3.0);

        // Above threshold
        $this->assertNull($trigger->evaluate($this->makeContext(85.0, 1.0)));
        $this->assertNull($trigger->evaluate($this->makeContext(90.0, 2.0)));

        // Drop below enter threshold — sustain timer resets
        $this->assertNull($trigger->evaluate($this->makeContext(75.0, 3.0)));

        // Go above again — timer restarts from t=4.0
        $this->assertNull($trigger->evaluate($this->makeContext(85.0, 4.0)));
        $this->assertNull($trigger->evaluate($this->makeContext(85.0, 5.0)));
        $this->assertNull($trigger->evaluate($this->makeContext(85.0, 6.0)));

        // 7.0 - 4.0 = 3.0 >= 3.0 → enter
        $this->assertNotNull($trigger->evaluate($this->makeContext(85.0, 7.0)));
    }

    public function testSameThresholdForEnterAndExit(): void
    {
        $trigger = new CpuUsageTrigger(80.0, 80.0);

        // Enter at 80%
        $this->assertNotNull($trigger->evaluate($this->makeContext(80.0, 1.0)));

        // Still at 80% — active (not below exit)
        $this->assertNotNull($trigger->evaluate($this->makeContext(80.0, 2.0)));

        // Drop to 79.9% — exits (< 80%)
        $this->assertNull($trigger->evaluate($this->makeContext(79.9, 3.0)));
    }

    private function makeContext(?float $cpu_percent, float $timestamp): WatchContext
    {
        return new WatchContext(
            pid: 1234,
            heap_stats: new HeapStats(0, 0, 0, 0),
            call_trace: null,
            timestamp: $timestamp,
            previous: null,
            cpu_usage_percent: $cpu_percent,
        );
    }
}
