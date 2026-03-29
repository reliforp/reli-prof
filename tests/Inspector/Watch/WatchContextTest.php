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

use PHPUnit\Framework\TestCase;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallFrame;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;

class WatchContextTest extends TestCase
{
    public function testConstruction(): void
    {
        $heap = new HeapStats(1024, 2048, 1024, 0);
        $trace = new CallTrace(
            new CallFrame('', 'main', 'test.php', null),
        );
        $prev = new WatchContext(
            pid: 1,
            heap_stats: $heap,
            call_trace: null,
            has_exception: null,
            timestamp: 99.0,
            previous: null,
        );
        $ctx = new WatchContext(
            pid: 42,
            heap_stats: $heap,
            call_trace: $trace,
            has_exception: true,
            timestamp: 100.0,
            previous: $prev,
            variable_values: [
                'global::x' => new VariableValue('long', 5, null),
            ],
        );

        $this->assertSame(42, $ctx->pid);
        $this->assertSame($heap, $ctx->heap_stats);
        $this->assertSame($trace, $ctx->call_trace);
        $this->assertTrue($ctx->has_exception);
        $this->assertSame(100.0, $ctx->timestamp);
        $this->assertSame($prev, $ctx->previous);
        $this->assertCount(1, $ctx->variable_values);
    }

    public function testDefaultVariableValues(): void
    {
        $ctx = new WatchContext(
            pid: 1,
            heap_stats: new HeapStats(0, 0, 0, 0),
            call_trace: null,
            has_exception: null,
            timestamp: 0.0,
            previous: null,
        );
        $this->assertSame([], $ctx->variable_values);
    }
}
