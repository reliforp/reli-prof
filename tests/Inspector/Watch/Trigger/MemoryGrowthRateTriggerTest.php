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

class MemoryGrowthRateTriggerTest extends TestCase
{
    public function testParseRate(): void
    {
        [$bytes, $seconds] = MemoryGrowthRateTrigger::parseRate('10M/min');
        $this->assertSame(10 * 1024 * 1024, $bytes);
        $this->assertSame(60, $seconds);

        [$bytes, $seconds] = MemoryGrowthRateTrigger::parseRate('1G/h');
        $this->assertSame(1024 * 1024 * 1024, $bytes);
        $this->assertSame(3600, $seconds);

        [$bytes, $seconds] = MemoryGrowthRateTrigger::parseRate('500K/s');
        $this->assertSame(500 * 1024, $bytes);
        $this->assertSame(1, $seconds);
    }

    public function testParseRateInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MemoryGrowthRateTrigger::parseRate('invalid');
    }

    public function testDoesNotFireWithoutPrevious(): void
    {
        $trigger = new MemoryGrowthRateTrigger(1024, 1);
        $context = $this->makeContext(10 * 1024 * 1024, null);

        $this->assertNull($trigger->evaluate($context));
    }

    public function testFiresWhenGrowthExceedsRate(): void
    {
        // Threshold: 1M/s
        $trigger = new MemoryGrowthRateTrigger(1024 * 1024, 1);

        $prev = $this->makeContext(10 * 1024 * 1024, null, 100.0);
        // 5M growth in 1 second = 5M/s > 1M/s
        $curr = $this->makeContext(15 * 1024 * 1024, $prev, 101.0);

        $event = $trigger->evaluate($curr);
        $this->assertNotNull($event);
        $this->assertSame('memory-growth-rate', $event->trigger_name);
    }

    public function testDoesNotFireWhenGrowthBelowRate(): void
    {
        // Threshold: 10M/s
        $trigger = new MemoryGrowthRateTrigger(10 * 1024 * 1024, 1);

        $prev = $this->makeContext(10 * 1024 * 1024, null, 100.0);
        // 1M growth in 1 second = 1M/s < 10M/s
        $curr = $this->makeContext(11 * 1024 * 1024, $prev, 101.0);

        $this->assertNull($trigger->evaluate($curr));
    }

    public function testDoesNotFireOnMemoryDecrease(): void
    {
        $trigger = new MemoryGrowthRateTrigger(1024, 1);

        $prev = $this->makeContext(10 * 1024 * 1024, null, 100.0);
        $curr = $this->makeContext(5 * 1024 * 1024, $prev, 101.0);

        $this->assertNull($trigger->evaluate($curr));
    }

    public function testProperties(): void
    {
        $trigger = new MemoryGrowthRateTrigger(1024, 1);
        $this->assertSame('memory-growth-rate', $trigger->name());
        $this->assertFalse($trigger->requiresCallTrace());
        $this->assertFalse($trigger->requiresDeepInspection());
    }

    private function makeContext(
        int $size,
        ?WatchContext $previous,
        float $timestamp = 0.0,
    ): WatchContext {
        return new WatchContext(
            pid: 1234,
            heap_stats: new HeapStats($size, $size, $size, 0),
            call_trace: null,
            timestamp: $timestamp,
            previous: $previous,
        );
    }
}
