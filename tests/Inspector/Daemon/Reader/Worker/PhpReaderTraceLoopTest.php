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

namespace Reli\Inspector\Daemon\Reader\Worker;

use Mockery;
use Reli\BaseTestCase;
use Reli\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use Reli\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use Reli\Inspector\Watch\VariableReaderInterface;
use Reli\Lib\Libc\Errno\Errno;
use Reli\Lib\Libc\Sys\Ptrace\Ptrace;
use Reli\Lib\Loop\AsyncLoopBuilder;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallFrame;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader;
use Reli\Lib\Process\ProcessStopper\ProcessStopper;

class PhpReaderTraceLoopTest extends BaseTestCase
{
    public function testNonNullCallTraceIsYielded(): void
    {
        $trace = new CallTrace(new CallFrame('', 'foo', '/app/foo.php', null));
        $loop = $this->makeTraceLoop([$trace, $trace]);

        $generator = $this->runOnce($loop);

        $this->assertTrue($generator->valid());
        /** @var TraceMessage $msg */
        $msg = $generator->current();
        $this->assertSame($trace, $msg->trace);
    }

    public function testIsolatedNullYieldsEmptyTraceAndKeepsLoopAlive(): void
    {
        $real = new CallTrace(new CallFrame('', 'foo', '/app/foo.php', null));
        // null sandwiched between two real traces — the daemon used to
        // detach on the first null; with the idle-tolerance fix the loop
        // continues and we see all three samples.
        $loop = $this->makeTraceLoop([$real, null, $real]);

        $messages = $this->collect($loop, 3);

        $this->assertCount(3, $messages);
        $this->assertSame($real, $messages[0]->trace);
        $this->assertSame([], $messages[1]->trace->call_frames);
        $this->assertSame($real, $messages[2]->trace);
    }

    public function testReleasesWorkerAfterIdleBudgetIsExceeded(): void
    {
        $budget = 5;
        // BUDGET + 3 nulls in a row. The loop yields BUDGET empty samples,
        // then on the (BUDGET+1)-th null returns without yielding so the
        // outer worker can be picked up by the dispatcher again.
        $sequence = array_fill(0, $budget + 3, null);
        $loop = $this->makeTraceLoop($sequence, $budget);

        $messages = $this->collect($loop, $budget + 3);

        $this->assertCount($budget, $messages);
        foreach ($messages as $msg) {
            $this->assertSame([], $msg->trace->call_frames);
        }
    }

    public function testRealTraceResetsTheIdleStreak(): void
    {
        $budget = 5;
        $real = new CallTrace(new CallFrame('', 'bar', '/app/bar.php', null));
        // (BUDGET-1) nulls — would hit the budget on the next null —
        // but a real trace lands first, resetting the streak. Then
        // another (BUDGET-1) nulls don't cause detach either.
        $sequence = array_merge(
            array_fill(0, $budget - 1, null),
            [$real],
            array_fill(0, $budget - 1, null),
        );
        $loop = $this->makeTraceLoop($sequence, $budget);

        $messages = $this->collect($loop, count($sequence));

        $this->assertCount(count($sequence), $messages);
        $this->assertSame($real, $messages[$budget - 1]->trace);
    }

    /**
     * @param list<CallTrace|null> $sequence
     */
    private function makeTraceLoop(array $sequence, ?int $idle_tick_budget = null): PhpReaderTraceLoop
    {
        // CallTraceReader is final — use Mockery overload (matches the
        // pattern in TraceActionTest). We feed the sequence via
        // andReturnUsing because long andReturn(...) varargs lists can
        // crash mockery's expectation matcher. Throw at end-of-sequence
        // so a test that under-counts iterations fails loudly instead
        // of getting padded with phantom nulls.
        $reader = Mockery::mock('overload:' . CallTraceReader::class);
        /** @var list<CallTrace|null> $queue */
        $queue = $sequence;
        $reader->shouldReceive('readCallTrace')->andReturnUsing(
            static function () use (&$queue) {
                if ($queue === []) {
                    throw new \LogicException('readCallTrace called past end of test sequence');
                }
                return array_shift($queue);
            }
        );
        // ProcessStopper is final — build a real one wired to a mocked
        // Ptrace and a real Errno (its ctor only opens libc.so.6 via FFI).
        // The trace loop only enters the stopper code path when
        // stop_process is true, which these tests never set.
        $stopper = new ProcessStopper(
            Mockery::mock(Ptrace::class),
            new Errno(),
        );
        $variable_reader = Mockery::mock(VariableReaderInterface::class);

        return new PhpReaderTraceLoop(
            $reader,
            new ReaderLoopProvider(new AsyncLoopBuilder()),
            $stopper,
            $variable_reader,
            idle_tick_budget: $idle_tick_budget ?? PhpReaderTraceLoop::DEFAULT_IDLE_TICK_BUDGET,
        );
    }

    private function runOnce(PhpReaderTraceLoop $loop): \Generator
    {
        return $loop->run(
            new TraceLoopSettings(1, 'q', 10, false),
            new TargetProcessDescriptor(123, 0, 0, ZendTypeReader::V83),
            new GetTraceSettings(PHP_INT_MAX),
        );
    }

    /**
     * @return list<TraceMessage>
     *
     * Uses foreach so we never advance the generator past `$max` —
     * advancing into a tick that the test sequence isn't prepared
     * for would throw a LogicException out of the mock and mask the
     * real assertion failure.
     */
    private function collect(PhpReaderTraceLoop $loop, int $max): array
    {
        $messages = [];
        foreach ($this->runOnce($loop) as $value) {
            /** @var TraceMessage $value */
            $messages[] = $value;
            if (count($messages) >= $max) {
                break;
            }
        }
        return $messages;
    }
}
