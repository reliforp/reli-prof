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
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallFrame;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader;
use Reli\Lib\Process\ProcessSpecifier;

class TraceActionTest extends BaseTestCase
{
    public function testName(): void
    {
        // CallTraceReader is final — use Mockery overload
        $reader = Mockery::mock(
            'overload:' . CallTraceReader::class
        );
        $output = Mockery::mock(TraceOutput::class);
        $action = new TraceAction(
            $reader,
            $output,
            ZendTypeReader::V84,
            0x1000,
            0x2000,
            100,
        );
        $this->assertSame('trace', $action->name());
    }

    public function testReusesTraceFromContext(): void
    {
        $trace = new CallTrace(
            new CallFrame('', 'main', 'test.php', null),
        );

        $output = Mockery::mock(TraceOutput::class);
        $output->expects()->output($trace)->once();

        // When call_trace is in context, reader should not be called.
        // We can't easily mock a final class, so just verify via output.
        $reader = Mockery::mock(
            'overload:' . CallTraceReader::class
        );
        $reader->shouldNotReceive('readCallTrace');

        $action = new TraceAction(
            $reader,
            $output,
            ZendTypeReader::V84,
            0x1000,
            0x2000,
            100,
        );

        $context = new WatchContext(
            pid: 1,
            heap_stats: new HeapStats(0, 0, 0, 0),
            call_trace: $trace,
            has_exception: null,
            timestamp: 100.0,
            previous: null,
        );

        $action->execute(
            new TriggerEvent('test', 'desc', 100.0),
            new ProcessSpecifier(1),
            $context,
        );
    }
}
