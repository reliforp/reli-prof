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

class FunctionDetectionTriggerTest extends TestCase
{
    public function testFiresWhenFunctionFound(): void
    {
        $trigger = new FunctionDetectionTrigger('sleep');
        $trace = new CallTrace(
            new CallFrame('', 'main', 'test.php', null),
            new CallFrame('', 'sleep', 'test.php', null),
        );
        $context = $this->makeContext($trace);

        $event = $trigger->evaluate($context);
        $this->assertNotNull($event);
        $this->assertSame('watch-function', $event->trigger_name);
    }

    public function testFiresOnClassMethod(): void
    {
        $trigger = new FunctionDetectionTrigger('App\\Service::process');
        $trace = new CallTrace(
            new CallFrame('App\\Service', 'process', 'Service.php', null),
        );
        $context = $this->makeContext($trace);

        $event = $trigger->evaluate($context);
        $this->assertNotNull($event);
    }

    public function testDoesNotFireWhenFunctionNotFound(): void
    {
        $trigger = new FunctionDetectionTrigger('nonexistent');
        $trace = new CallTrace(
            new CallFrame('', 'main', 'test.php', null),
        );
        $context = $this->makeContext($trace);

        $this->assertNull($trigger->evaluate($context));
    }

    public function testDoesNotFireWithoutTrace(): void
    {
        $trigger = new FunctionDetectionTrigger('sleep');
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
        $trigger = new FunctionDetectionTrigger('test');
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
