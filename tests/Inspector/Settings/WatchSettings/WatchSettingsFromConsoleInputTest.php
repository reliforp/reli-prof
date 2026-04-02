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
use Reli\Lib\Directory\AppDirectory;
use Symfony\Component\Console\Input\InputInterface;

class WatchSettingsFromConsoleInputTest extends BaseTestCase
{
    private function makeInput(array $overrides = []): InputInterface
    {
        $defaults = [
            'memory-usage' => null,
            'memory-growth-rate' => null,
            'memory-peak-watch' => false,
            'rss-usage' => null,
            'watch-function' => null,
            'trace-depth-limit' => null,
            'watch-var' => [],
            'action' => ['memory-dump'],
            'action-exec-command' => null,
            'action-output-dir' => null,
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
            'include-binary' => false,
            'memory-limit' => null,
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
        $this->assertNull($settings->memory_usage_bytes);
        $this->assertNull($settings->memory_growth_rate);
        $this->assertFalse($settings->memory_peak_watch);
        $this->assertFalse($settings->include_binary);
        $this->assertSame(AppDirectory::getWatchDumpDir(), $settings->action_output_dir);
    }

    public function testActionOutputDirAbsolutePath(): void
    {
        $settings = (new WatchSettingsFromConsoleInput())
            ->createSettings($this->makeInput([
                'action-output-dir' => '/tmp/my-dumps',
            ]));

        $this->assertSame('/tmp/my-dumps', $settings->action_output_dir);
    }

    public function testActionOutputDirRelativePathResolvesToAbsolute(): void
    {
        $settings = (new WatchSettingsFromConsoleInput())
            ->createSettings($this->makeInput([
                'action-output-dir' => 'my-dumps',
            ]));

        $this->assertSame(getcwd() . '/my-dumps', $settings->action_output_dir);
    }

    public function testLogFileRelativePathResolvesToAbsolute(): void
    {
        $settings = (new WatchSettingsFromConsoleInput())
            ->createSettings($this->makeInput([
                'log-file' => 'watch.log',
            ]));

        $this->assertSame(getcwd() . '/watch.log', $settings->log_file);
    }

    public function testMemoryLimit(): void
    {
        $settings = (new WatchSettingsFromConsoleInput())
            ->createSettings($this->makeInput([
                'memory-usage' => '256M',
            ]));

        $this->assertSame(256 * 1024 * 1024, $settings->memory_usage_bytes);
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

    public function testIncludeBinary(): void
    {
        $settings = (new WatchSettingsFromConsoleInput())
            ->createSettings($this->makeInput([
                'include-binary' => true,
            ]));

        $this->assertTrue($settings->include_binary);
    }

    public function testAllTriggerFlags(): void
    {
        $settings = (new WatchSettingsFromConsoleInput())
            ->createSettings($this->makeInput([
                'memory-peak-watch' => true,
                'watch-function' => 'sleep',
                'trace-depth-limit' => '200',
            ]));

        $this->assertTrue($settings->memory_peak_watch);
        $this->assertSame('sleep', $settings->watch_function);
        $this->assertSame(200, $settings->trace_depth_limit);
    }
}
