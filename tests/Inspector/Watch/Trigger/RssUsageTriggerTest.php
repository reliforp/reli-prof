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

class RssUsageTriggerTest extends TestCase
{
    public function testFiresWhenAboveLimit(): void
    {
        $trigger = new RssUsageTrigger(100 * 1024 * 1024); // 100M
        $context = $this->makeContext(150 * 1024 * 1024);

        $event = $trigger->evaluate($context);
        $this->assertNotNull($event);
        $this->assertSame('rss-usage', $event->trigger_name);
        $this->assertSame((float)(150 * 1024 * 1024), $event->value);
    }

    public function testDoesNotFireWhenBelowLimit(): void
    {
        $trigger = new RssUsageTrigger(100 * 1024 * 1024);
        $context = $this->makeContext(50 * 1024 * 1024);

        $this->assertNull($trigger->evaluate($context));
    }

    public function testDoesNotFireWhenExactlyAtLimit(): void
    {
        $trigger = new RssUsageTrigger(100 * 1024 * 1024);
        $context = $this->makeContext(100 * 1024 * 1024);

        $this->assertNull($trigger->evaluate($context));
    }

    public function testDoesNotFireWhenRssIsNull(): void
    {
        $trigger = new RssUsageTrigger(100 * 1024 * 1024);
        $context = new WatchContext(
            pid: 1234,
            heap_stats: new HeapStats(0, 0, 0, 0),
            call_trace: null,
            timestamp: microtime(true),
            previous: null,
            rss_bytes: null,
        );

        $this->assertNull($trigger->evaluate($context));
    }

    public function testProperties(): void
    {
        $trigger = new RssUsageTrigger(1024);
        $this->assertSame('rss-usage', $trigger->name());
        $this->assertFalse($trigger->requiresCallTrace());
        $this->assertFalse($trigger->requiresDeepInspection());
    }

    private function makeContext(int $rss_bytes): WatchContext
    {
        return new WatchContext(
            pid: 1234,
            heap_stats: new HeapStats(0, 0, 0, 0),
            call_trace: null,
            timestamp: microtime(true),
            previous: null,
            rss_bytes: $rss_bytes,
        );
    }
}
