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

use PHPUnit\Framework\TestCase;

class WatchSettingsTest extends TestCase
{
    public function testConstruction(): void
    {
        $settings = new WatchSettings(
            poll_interval_ms: 500,
            cooldown_seconds: 30,
            max_triggers: 5,
            max_triggers_per_hour: 20,
            max_dump_size_bytes: 2 * 1024 * 1024 * 1024,
            backoff_multiplier: 3.0,
            backoff_max_seconds: 7200,
            action_output_dir: '/tmp',
            status_interval_seconds: 120,
            quiet: true,
            memory_usage_bytes: 256 * 1024 * 1024,
            memory_growth_rate: '10M/min',
            memory_peak_watch: true,
            rss_usage_bytes: null,
            watch_function: 'sleep',
            trace_depth_limit: 200,
            watch_var: ['global::x:gt:0'],
            actions: ['trace', 'log'],
            action_exec_command: 'echo test',
            log_file: '/tmp/watch.log',
            memory_output_format: 'sqlite3',
        );

        $this->assertSame(500, $settings->poll_interval_ms);
        $this->assertSame(30, $settings->cooldown_seconds);
        $this->assertSame(5, $settings->max_triggers);
        $this->assertSame(20, $settings->max_triggers_per_hour);
        $this->assertSame(3.0, $settings->backoff_multiplier);
        $this->assertSame(7200, $settings->backoff_max_seconds);
        $this->assertSame('/tmp', $settings->action_output_dir);
        $this->assertSame(120, $settings->status_interval_seconds);
        $this->assertTrue($settings->quiet);
        $this->assertSame(256 * 1024 * 1024, $settings->memory_usage_bytes);
        $this->assertSame('10M/min', $settings->memory_growth_rate);
        $this->assertTrue($settings->memory_peak_watch);
        $this->assertSame('sleep', $settings->watch_function);
        $this->assertSame(200, $settings->trace_depth_limit);
        $this->assertSame(['global::x:gt:0'], $settings->watch_var);
        $this->assertSame(['trace', 'log'], $settings->actions);
        $this->assertSame('echo test', $settings->action_exec_command);
        $this->assertSame('/tmp/watch.log', $settings->log_file);
        $this->assertSame('sqlite3', $settings->memory_output_format);
    }

    public function testDefaults(): void
    {
        $this->assertSame(
            1000,
            WatchSettings::POLL_INTERVAL_MS_DEFAULT,
        );
        $this->assertSame(60, WatchSettings::COOLDOWN_SECONDS_DEFAULT);
        $this->assertSame(0, WatchSettings::MAX_TRIGGERS_DEFAULT);
        $this->assertSame(
            10,
            WatchSettings::MAX_TRIGGERS_PER_HOUR_DEFAULT,
        );
        $this->assertSame(
            1024 * 1024 * 1024,
            WatchSettings::MAX_DUMP_SIZE_BYTES_DEFAULT,
        );
        $this->assertSame(
            2.0,
            WatchSettings::BACKOFF_MULTIPLIER_DEFAULT,
        );
        $this->assertSame(
            3600,
            WatchSettings::BACKOFF_MAX_SECONDS_DEFAULT,
        );
    }
}
