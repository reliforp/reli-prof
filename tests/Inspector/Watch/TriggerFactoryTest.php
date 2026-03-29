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
use Reli\Inspector\Settings\WatchSettings\WatchSettings;
use Reli\Inspector\Watch\Trigger\ExceptionDetectionTrigger;
use Reli\Inspector\Watch\Trigger\FunctionDetectionTrigger;
use Reli\Inspector\Watch\Trigger\MemoryGrowthRateTrigger;
use Reli\Inspector\Watch\Trigger\MemoryLimitTrigger;
use Reli\Inspector\Watch\Trigger\MemoryPeakTrigger;
use Reli\Inspector\Watch\Trigger\TraceDepthTrigger;
use Reli\Inspector\Watch\Trigger\VariableValueTrigger;

class TriggerFactoryTest extends TestCase
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
            'memory_limit_bytes' => null,
            'memory_growth_rate' => null,
            'memory_peak_watch' => false,
            'watch_function' => null,
            'trace_depth_limit' => null,
            'on_exception' => false,
            'watch_var' => [],
            'actions' => ['log'],
            'action_exec_command' => null,
            'log_file' => null,
            'memory_output_format' => null,
        ];
        $merged = array_merge($defaults, $overrides);
        return new WatchSettings(...$merged);
    }

    public function testEmptySettings(): void
    {
        $factory = new TriggerFactory();
        $triggers = $factory->build($this->makeSettings());
        $this->assertCount(0, $triggers);
    }

    public function testMemoryLimit(): void
    {
        $factory = new TriggerFactory();
        $triggers = $factory->build($this->makeSettings([
            'memory_limit_bytes' => 256 * 1024 * 1024,
        ]));
        $this->assertCount(1, $triggers);
        $this->assertInstanceOf(MemoryLimitTrigger::class, $triggers[0]);
    }

    public function testMemoryGrowthRate(): void
    {
        $factory = new TriggerFactory();
        $triggers = $factory->build($this->makeSettings([
            'memory_growth_rate' => '10M/min',
        ]));
        $this->assertCount(1, $triggers);
        $this->assertInstanceOf(
            MemoryGrowthRateTrigger::class,
            $triggers[0],
        );
    }

    public function testMemoryPeakWatch(): void
    {
        $factory = new TriggerFactory();
        $triggers = $factory->build($this->makeSettings([
            'memory_peak_watch' => true,
        ]));
        $this->assertCount(1, $triggers);
        $this->assertInstanceOf(MemoryPeakTrigger::class, $triggers[0]);
    }

    public function testWatchFunction(): void
    {
        $factory = new TriggerFactory();
        $triggers = $factory->build($this->makeSettings([
            'watch_function' => 'sleep',
        ]));
        $this->assertCount(1, $triggers);
        $this->assertInstanceOf(
            FunctionDetectionTrigger::class,
            $triggers[0],
        );
    }

    public function testTraceDepthLimit(): void
    {
        $factory = new TriggerFactory();
        $triggers = $factory->build($this->makeSettings([
            'trace_depth_limit' => 200,
        ]));
        $this->assertCount(1, $triggers);
        $this->assertInstanceOf(TraceDepthTrigger::class, $triggers[0]);
    }

    public function testOnException(): void
    {
        $factory = new TriggerFactory();
        $triggers = $factory->build($this->makeSettings([
            'on_exception' => true,
        ]));
        $this->assertCount(1, $triggers);
        $this->assertInstanceOf(
            ExceptionDetectionTrigger::class,
            $triggers[0],
        );
    }

    public function testWatchVar(): void
    {
        $factory = new TriggerFactory();
        $triggers = $factory->build($this->makeSettings([
            'watch_var' => ['global::$x:gt:0', 'local::y:eq:test'],
        ]));
        $this->assertCount(2, $triggers);
        $this->assertInstanceOf(
            VariableValueTrigger::class,
            $triggers[0],
        );
        $this->assertInstanceOf(
            VariableValueTrigger::class,
            $triggers[1],
        );
    }

    public function testAllTriggersAtOnce(): void
    {
        $factory = new TriggerFactory();
        $triggers = $factory->build($this->makeSettings([
            'memory_limit_bytes' => 100,
            'memory_growth_rate' => '1K/s',
            'memory_peak_watch' => true,
            'watch_function' => 'sleep',
            'trace_depth_limit' => 50,
            'on_exception' => true,
            'watch_var' => ['global::$a:gt:0'],
        ]));
        $this->assertCount(7, $triggers);
    }
}
