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
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Reli\BaseTestCase;
use Reli\Inspector\MemoryDump\MemoryDumper;
use Reli\Inspector\Output\TraceOutput\TraceOutput;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Inspector\Settings\WatchSettings\WatchSettings;
use Reli\Inspector\Watch\Action\DaemonTraceAction;
use Reli\Inspector\Watch\Action\ExecAction;
use Reli\Inspector\Watch\Action\LogAction;
use Reli\Inspector\Watch\Action\MemoryDumpAction;
use Reli\Inspector\Watch\Action\TraceAction;
use Reli\Lib\Process\ProcessStopper\ProcessStopper;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class ActionFactoryBuildActionsTest extends BaseTestCase
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
            'rss_usage_bytes' => null,
            'watch_function' => null,
            'trace_depth_limit' => null,
            'watch_var' => [],
            'actions' => ['log'],
            'action_exec_command' => null,
            'log_file' => null,
            'memory_output_format' => null,
        ];
        return new WatchSettings(...array_merge($defaults, $overrides));
    }

    private function makeFactory(): ActionFactory
    {
        return new ActionFactory(
            Mockery::mock(
                'overload:' . \Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader::class,
            ),
            Mockery::mock(
                'overload:' . MemoryDumper::class,
            ),
            Mockery::mock('overload:' . ProcessStopper::class),
        );
    }

    public function testBuildActionsTrace(): void
    {
        $factory = $this->makeFactory();
        $output = Mockery::mock(TraceOutput::class);
        $tps = new TargetPhpSettings(php_version: 'v84');

        $actions = $factory->buildActions(
            $this->makeSettings(['actions' => ['trace-once']]),
            $output,
            'v84',
            0x1000,
            0x2000,
            0x3000,
            100,
            $tps,
            new DiskUsageTracker(1024 * 1024),
        );
        $this->assertCount(1, $actions);
        $this->assertInstanceOf(TraceAction::class, $actions[0]);
    }

    public function testBuildActionsLog(): void
    {
        $factory = $this->makeFactory();
        $output = Mockery::mock(TraceOutput::class);
        $tps = new TargetPhpSettings(php_version: 'v84');

        $actions = $factory->buildActions(
            $this->makeSettings(['actions' => ['log']]),
            $output,
            'v84',
            0x1000,
            0x2000,
            0x3000,
            100,
            $tps,
            new DiskUsageTracker(1024 * 1024),
        );
        $this->assertCount(1, $actions);
        $this->assertInstanceOf(LogAction::class, $actions[0]);
    }

    public function testBuildActionsMemoryDump(): void
    {
        $factory = $this->makeFactory();
        $output = Mockery::mock(TraceOutput::class);
        $tps = new TargetPhpSettings(php_version: 'v84');

        $actions = $factory->buildActions(
            $this->makeSettings(['actions' => ['memory-dump']]),
            $output,
            'v84',
            0x1000,
            0x2000,
            0x3000,
            100,
            $tps,
            new DiskUsageTracker(1024 * 1024),
        );
        $this->assertCount(1, $actions);
        $this->assertInstanceOf(MemoryDumpAction::class, $actions[0]);
    }

    public function testBuildActionsExec(): void
    {
        $factory = $this->makeFactory();
        $output = Mockery::mock(TraceOutput::class);
        $tps = new TargetPhpSettings(php_version: 'v84');

        $actions = $factory->buildActions(
            $this->makeSettings([
                'actions' => ['exec'],
                'action_exec_command' => 'echo test',
            ]),
            $output,
            'v84',
            0x1000,
            0x2000,
            0x3000,
            100,
            $tps,
            new DiskUsageTracker(1024 * 1024),
        );
        $this->assertCount(1, $actions);
        $this->assertInstanceOf(ExecAction::class, $actions[0]);
    }

    public function testBuildActionsDefaultsToMemoryDump(): void
    {
        $factory = $this->makeFactory();
        $output = Mockery::mock(TraceOutput::class);
        $tps = new TargetPhpSettings(php_version: 'v84');

        $actions = $factory->buildActions(
            $this->makeSettings(['actions' => []]),
            $output,
            'v84',
            0x1000,
            0x2000,
            0x3000,
            100,
            $tps,
            new DiskUsageTracker(1024 * 1024),
        );
        $this->assertCount(1, $actions);
        $this->assertInstanceOf(MemoryDumpAction::class, $actions[0]);
    }

    public function testBuildActionsMultiple(): void
    {
        $factory = $this->makeFactory();
        $output = Mockery::mock(TraceOutput::class);
        $tps = new TargetPhpSettings(php_version: 'v84');

        $actions = $factory->buildActions(
            $this->makeSettings([
                'actions' => ['trace-once', 'log', 'memory-dump', 'exec'],
                'action_exec_command' => 'curl http://example.com',
            ]),
            $output,
            'v84',
            0x1000,
            0x2000,
            0x3000,
            100,
            $tps,
            new DiskUsageTracker(1024 * 1024),
        );
        $this->assertCount(4, $actions);
        $this->assertInstanceOf(TraceAction::class, $actions[0]);
        $this->assertInstanceOf(LogAction::class, $actions[1]);
        $this->assertInstanceOf(
            MemoryDumpAction::class,
            $actions[2],
        );
        $this->assertInstanceOf(ExecAction::class, $actions[3]);
    }

    public function testBuildDaemonActionsMemoryDump(): void
    {
        $factory = $this->makeFactory();
        $output = Mockery::mock(TraceOutput::class);

        $actions = $factory->buildDaemonActions(
            $this->makeSettings(['actions' => ['memory-dump']]),
            $output,
            new DiskUsageTracker(1024 * 1024),
        );
        $this->assertCount(1, $actions);
        $this->assertSame('memory-dump', $actions[0]->name());
    }
}
