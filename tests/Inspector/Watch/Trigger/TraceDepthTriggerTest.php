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
use Reli\Lib\PhpProcessReader\CallTraceReader\CallFrame;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;

class TraceDepthTriggerTest extends TestCase
{
    public function testFiresWhenDepthExceeded(): void
    {
        $trigger = new TraceDepthTrigger(2);
        $trace = new CallTrace(
            new CallFrame('', 'a', 'f.php', null),
            new CallFrame('', 'b', 'f.php', null),
            new CallFrame('', 'c', 'f.php', null),
        );
        $context = $this->makeContext($trace);

        $event = $trigger->evaluate($context);
        $this->assertNotNull($event);
        $this->assertSame('trace-depth-limit', $event->trigger_name);
    }

    public function testDoesNotFireWhenDepthWithinLimit(): void
    {
        $trigger = new TraceDepthTrigger(5);
        $trace = new CallTrace(
            new CallFrame('', 'a', 'f.php', null),
            new CallFrame('', 'b', 'f.php', null),
        );
        $context = $this->makeContext($trace);

        $this->assertNull($trigger->evaluate($context));
    }

    public function testDoesNotFireWithoutTrace(): void
    {
        $trigger = new TraceDepthTrigger(1);
        $context = new WatchContext(
            pid: 1,
            heap_stats: new HeapStats(0, 0, 0, 0),
            call_trace: null,
            timestamp: 0.0,
            previous: null,
        );

        $this->assertNull($trigger->evaluate($context));
    }

    public function testProperties(): void
    {
        $trigger = new TraceDepthTrigger(10);
        $this->assertTrue($trigger->requiresCallTrace());
        $this->assertFalse($trigger->requiresDeepInspection());
    }

    private function makeContext(CallTrace $trace): WatchContext
    {
        return new WatchContext(
            pid: 1,
            heap_stats: new HeapStats(0, 0, 0, 0),
            call_trace: $trace,
            timestamp: microtime(true),
            previous: null,
        );
    }
}
