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

namespace Reli\Inspector\Watch\Action;

use Mockery;
use Reli\BaseTestCase;
use Reli\Inspector\Output\TraceOutput\TraceOutput;
use Reli\Inspector\Watch\HeapStats;
use Reli\Inspector\Watch\TriggerEvent;
use Reli\Inspector\Watch\WatchContext;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallFrame;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;
use Reli\Lib\Process\ProcessSpecifier;

class DaemonTraceActionTest extends BaseTestCase
{
    public function testName(): void
    {
        $trace_output = Mockery::mock(TraceOutput::class);
        $action = new DaemonTraceAction($trace_output);
        $this->assertSame('trace-once', $action->name());
    }

    public function testOutputsTraceFromContext(): void
    {
        $trace = new CallTrace(
            new CallFrame('', 'main', 'test.php', null),
        );
        $trace_output = Mockery::mock(TraceOutput::class);
        $trace_output->expects()->output($trace)->once();

        $action = new DaemonTraceAction($trace_output);
        $action->execute(
            $this->makeEvent(),
            new ProcessSpecifier(1),
            $this->makeContext($trace),
        );
    }

    public function testDoesNothingWithoutTrace(): void
    {
        $trace_output = Mockery::mock(TraceOutput::class);
        $trace_output->shouldNotReceive('output');

        $action = new DaemonTraceAction($trace_output);
        $action->execute(
            $this->makeEvent(),
            new ProcessSpecifier(1),
            $this->makeContext(null),
        );
    }

    private function makeEvent(): TriggerEvent
    {
        return new TriggerEvent('test', 'test event', 100.0);
    }

    private function makeContext(?CallTrace $trace): WatchContext
    {
        return new WatchContext(
            pid: 1,
            heap_stats: new HeapStats(0, 0, 0, 0),
            call_trace: $trace,
            timestamp: 100.0,
            previous: null,
        );
    }
}
