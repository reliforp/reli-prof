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

namespace Reli\Inspector\Settings\WatchSettings;

use Mockery;
use Reli\BaseTestCase;
use Symfony\Component\Console\Input\InputInterface;

class WatchSettingsFromConsoleInputTest extends BaseTestCase
{
    private function makeInput(array $overrides = []): InputInterface
    {
        $defaults = [
            'memory-limit' => null,
            'memory-growth-rate' => null,
            'memory-peak-watch' => false,
            'watch-function' => null,
            'trace-depth-limit' => null,
            'on-exception' => false,
            'watch-var' => [],
            'action' => ['memory-dump'],
            'action-exec-command' => null,
            'action-output-dir' => '.',
            'log-file' => null,
            'memory-output-format' => 'json',
            'poll-interval' => null,
            'cooldown' => null,
            'max-triggers' => null,
            'oneshot' => null,
            'max-triggers-per-hour' => null,
            'max-dump-size' => null,
            'backoff-multiplier' => null,
            'backoff-max' => null,
            'status-interval' => null,
            'quiet-watch' => false,
        ];
        $merged = array_merge($defaults, $overrides);
        $input = Mockery::mock(InputInterface::class);
        foreach ($merged as $key => $value) {
            $input->allows()->getOption($key)->andReturns($value);
        }
        return $input;
    }

    public function testDefaults(): void
    {
        $settings = (new WatchSettingsFromConsoleInput())
            ->createSettings($this->makeInput());

        $this->assertSame(1000, $settings->poll_interval_ms);
        $this->assertSame(60, $settings->cooldown_seconds);
        $this->assertSame(0, $settings->max_triggers);
        $this->assertSame(10, $settings->max_triggers_per_hour);
        $this->assertSame(
            1024 * 1024 * 1024,
            $settings->max_dump_size_bytes,
        );
        $this->assertSame(2.0, $settings->backoff_multiplier);
        $this->assertSame(3600, $settings->backoff_max_seconds);
        $this->assertFalse($settings->quiet);
        $this->assertNull($settings->memory_limit_bytes);
        $this->assertNull($settings->memory_growth_rate);
        $this->assertFalse($settings->memory_peak_watch);
    }

    public function testMemoryLimit(): void
    {
        $settings = (new WatchSettingsFromConsoleInput())
            ->createSettings($this->makeInput([
                'memory-limit' => '256M',
            ]));

        $this->assertSame(256 * 1024 * 1024, $settings->memory_limit_bytes);
    }

    public function testMemoryGrowthRate(): void
    {
        $settings = (new WatchSettingsFromConsoleInput())
            ->createSettings($this->makeInput([
                'memory-growth-rate' => '10M/min',
            ]));

        $this->assertSame('10M/min', $settings->memory_growth_rate);
    }

    public function testInvalidGrowthRateThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new WatchSettingsFromConsoleInput())
            ->createSettings($this->makeInput([
                'memory-growth-rate' => 'invalid',
            ]));
    }

    public function testPollIntervalMinimum(): void
    {
        $settings = (new WatchSettingsFromConsoleInput())
            ->createSettings($this->makeInput([
                'poll-interval' => '50',
            ]));

        $this->assertSame(100, $settings->poll_interval_ms);
    }

    public function testOneshotAlias(): void
    {
        $settings = (new WatchSettingsFromConsoleInput())
            ->createSettings($this->makeInput([
                'oneshot' => '5',
            ]));

        $this->assertSame(5, $settings->max_triggers);
    }

    public function testMaxTriggersOverridesOneshot(): void
    {
        $settings = (new WatchSettingsFromConsoleInput())
            ->createSettings($this->makeInput([
                'max-triggers' => '3',
                'oneshot' => '5',
            ]));

        // --max-triggers takes precedence (checked first)
        $this->assertSame(3, $settings->max_triggers);
    }

    public function testActions(): void
    {
        $settings = (new WatchSettingsFromConsoleInput())
            ->createSettings($this->makeInput([
                'action' => ['trace', 'log'],
            ]));

        $this->assertSame(['trace', 'log'], $settings->actions);
    }

    public function testMaxDumpSize(): void
    {
        $settings = (new WatchSettingsFromConsoleInput())
            ->createSettings($this->makeInput([
                'max-dump-size' => '2G',
            ]));

        $this->assertSame(
            2 * 1024 * 1024 * 1024,
            $settings->max_dump_size_bytes,
        );
    }

    public function testAllTriggerFlags(): void
    {
        $settings = (new WatchSettingsFromConsoleInput())
            ->createSettings($this->makeInput([
                'memory-peak-watch' => true,
                'on-exception' => true,
                'watch-function' => 'sleep',
                'trace-depth-limit' => '200',
            ]));

        $this->assertTrue($settings->memory_peak_watch);
        $this->assertTrue($settings->on_exception);
        $this->assertSame('sleep', $settings->watch_function);
        $this->assertSame(200, $settings->trace_depth_limit);
    }
}
