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

class MemoryPeakTriggerTest extends TestCase
{
    public function testDoesNotFireWithoutPrevious(): void
    {
        $trigger = new MemoryPeakTrigger();
        $context = $this->makeContext(100, null);
        $this->assertNull($trigger->evaluate($context));
    }

    public function testFiresWhenPeakChanges(): void
    {
        $trigger = new MemoryPeakTrigger();
        $prev = $this->makeContext(100, null);
        $curr = $this->makeContext(200, $prev);

        $event = $trigger->evaluate($curr);
        $this->assertNotNull($event);
        $this->assertSame('memory-peak-watch', $event->trigger_name);
    }

    public function testDoesNotFireWhenPeakUnchanged(): void
    {
        $trigger = new MemoryPeakTrigger();
        $prev = $this->makeContext(100, null);
        $curr = $this->makeContext(100, $prev);

        $this->assertNull($trigger->evaluate($curr));
    }

    private function makeContext(
        int $peak,
        ?WatchContext $previous,
    ): WatchContext {
        return new WatchContext(
            pid: 1,
            heap_stats: new HeapStats(0, 0, $peak, 0),
            call_trace: null,
            has_exception: null,
            timestamp: microtime(true),
            previous: $previous,
        );
    }
}
