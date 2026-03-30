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

use Mockery;
use Reli\BaseTestCase;
use Reli\Inspector\Output\TraceOutput\TraceOutput;
use Reli\Inspector\Settings\WatchSettings\WatchSettings;
use Reli\Inspector\Watch\Action\DaemonTraceAction;
use Reli\Inspector\Watch\Action\ExecAction;
use Reli\Inspector\Watch\Action\LogAction;
use Reli\Inspector\Watch\DiskUsageTracker;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ActionFactoryTest extends BaseTestCase
{
    private function makeSettings(array $overrides = []): WatchSettings
    {
        $defaults = [
            'poll_interval_ms' => 1000,
            'cooldown_seconds' => 60,
            'max_triggers' => 0,
            'max_triggers_per_hour' => 10,
            'max_dump_size_bytes' => 1024 * 1024 * 1024,
            'backoff_multiplier' => 2.0,
            'backoff_max_seconds' => 3600,
            'action_output_dir' => '.',
            'status_interval_seconds' => 60,
            'quiet' => false,
            'memory_usage_bytes' => null,
            'memory_growth_rate' => null,
            'memory_peak_watch' => false,
            'watch_function' => null,
            'trace_depth_limit' => null,
            'watch_var' => [],
            'actions' => ['log'],
            'action_exec_command' => null,
            'log_file' => null,
            'memory_output_format' => null,
        ];
        $merged = array_merge($defaults, $overrides);
        return new WatchSettings(...$merged);
    }

    public function testBuildDaemonActionsLog(): void
    {
        $factory = new ActionFactory(
            Mockery::mock('overload:' . \Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader::class),
            Mockery::mock('overload:' . \Reli\Inspector\MemoryDump\MemoryDumper::class),
            Mockery::mock('overload:' . \Reli\Inspector\MemoryDump\MemoryDumpReaderFactory::class),
        );
        $output = Mockery::mock(TraceOutput::class);

        $actions = $factory->buildDaemonActions(
            $this->makeSettings(['actions' => ['log']]),
            $output,
            new DiskUsageTracker(1024 * 1024 * 1024),
        );
        $this->assertCount(1, $actions);
        $this->assertInstanceOf(LogAction::class, $actions[0]);
    }

    public function testBuildDaemonActionsTrace(): void
    {
        $factory = new ActionFactory(
            Mockery::mock('overload:' . \Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader::class),
            Mockery::mock('overload:' . \Reli\Inspector\MemoryDump\MemoryDumper::class),
            Mockery::mock('overload:' . \Reli\Inspector\MemoryDump\MemoryDumpReaderFactory::class),
        );
        $output = Mockery::mock(TraceOutput::class);

        $actions = $factory->buildDaemonActions(
            $this->makeSettings(['actions' => ['trace']]),
            $output,
            new DiskUsageTracker(1024 * 1024 * 1024),
        );
        $this->assertCount(1, $actions);
        $this->assertInstanceOf(DaemonTraceAction::class, $actions[0]);
    }

    public function testBuildDaemonActionsExec(): void
    {
        $factory = new ActionFactory(
            Mockery::mock('overload:' . \Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader::class),
            Mockery::mock('overload:' . \Reli\Inspector\MemoryDump\MemoryDumper::class),
            Mockery::mock('overload:' . \Reli\Inspector\MemoryDump\MemoryDumpReaderFactory::class),
        );
        $output = Mockery::mock(TraceOutput::class);

        $actions = $factory->buildDaemonActions(
            $this->makeSettings([
                'actions' => ['exec'],
                'action_exec_command' => 'echo test',
            ]),
            $output,
            new DiskUsageTracker(1024 * 1024 * 1024),
        );
        $this->assertCount(1, $actions);
        $this->assertInstanceOf(ExecAction::class, $actions[0]);
    }

    public function testBuildDaemonActionsDefaultsToLog(): void
    {
        $factory = new ActionFactory(
            Mockery::mock('overload:' . \Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader::class),
            Mockery::mock('overload:' . \Reli\Inspector\MemoryDump\MemoryDumper::class),
            Mockery::mock('overload:' . \Reli\Inspector\MemoryDump\MemoryDumpReaderFactory::class),
        );
        $output = Mockery::mock(TraceOutput::class);

        $actions = $factory->buildDaemonActions(
            $this->makeSettings(['actions' => []]),
            $output,
            new DiskUsageTracker(1024 * 1024 * 1024),
        );
        $this->assertCount(1, $actions);
        $this->assertInstanceOf(LogAction::class, $actions[0]);
    }

    public function testBuildDaemonActionsMultiple(): void
    {
        $factory = new ActionFactory(
            Mockery::mock('overload:' . \Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader::class),
            Mockery::mock('overload:' . \Reli\Inspector\MemoryDump\MemoryDumper::class),
            Mockery::mock('overload:' . \Reli\Inspector\MemoryDump\MemoryDumpReaderFactory::class),
        );
        $output = Mockery::mock(TraceOutput::class);

        $actions = $factory->buildDaemonActions(
            $this->makeSettings([
                'actions' => ['trace', 'log', 'exec'],
                'action_exec_command' => 'curl http://localhost',
            ]),
            $output,
            new DiskUsageTracker(1024 * 1024 * 1024),
        );
        $this->assertCount(3, $actions);
        $this->assertInstanceOf(DaemonTraceAction::class, $actions[0]);
        $this->assertInstanceOf(LogAction::class, $actions[1]);
        $this->assertInstanceOf(ExecAction::class, $actions[2]);
    }
}
