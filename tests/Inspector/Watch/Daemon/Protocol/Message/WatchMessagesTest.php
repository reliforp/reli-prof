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

namespace Reli\Inspector\Watch\Daemon\Protocol\Message;

use PHPUnit\Framework\TestCase;
use Reli\Inspector\Watch\Daemon\Searcher\WatchTargetDescriptor;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use Reli\Inspector\Settings\WatchSettings\WatchSettings;
use Reli\Inspector\Watch\HeapStats;
use Reli\Inspector\Watch\TriggerEvent;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallFrame;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;

class WatchMessagesTest extends TestCase
{
    public function testWatchAttachMessage(): void
    {
        $desc = new WatchTargetDescriptor(
            123,
            0x1000,
            0x2000,
            0x3000,
            ZendTypeReader::V84,
        );
        $msg = new WatchAttachMessage($desc);
        $this->assertSame($desc, $msg->process_descriptor);
    }

    public function testWatchDetachMessage(): void
    {
        $msg = new WatchDetachMessage(456);
        $this->assertSame(456, $msg->pid);
    }

    public function testWatchSettingsMessage(): void
    {
        $watch = $this->makeWatchSettings();
        $loop = new TraceLoopSettings(1000, 'q', 10, false);
        $trace = new GetTraceSettings(PHP_INT_MAX);

        $msg = new WatchSettingsMessage($watch, $loop, $trace);
        $this->assertSame($watch, $msg->watch_settings);
        $this->assertSame($loop, $msg->trace_loop_settings);
        $this->assertSame($trace, $msg->get_trace_settings);
    }

    public function testWatchTriggerMessage(): void
    {
        $event = new TriggerEvent('memory-limit', 'test', 100.0);
        $heap = new HeapStats(1024, 2048, 1024, 0);
        $trace = new CallTrace(
            new CallFrame('', 'main', 'f.php', null),
        );

        $msg = new WatchTriggerMessage(789, $event, $heap, $trace);
        $this->assertSame(789, $msg->pid);
        $this->assertSame($event, $msg->event);
        $this->assertSame($heap, $msg->heap_stats);
        $this->assertSame($trace, $msg->call_trace);
    }

    public function testWatchTriggerMessageNullTrace(): void
    {
        $event = new TriggerEvent('test', 'desc', 0.0);
        $heap = new HeapStats(0, 0, 0, 0);

        $msg = new WatchTriggerMessage(1, $event, $heap, null);
        $this->assertNull($msg->call_trace);
    }

    private function makeWatchSettings(): WatchSettings
    {
        return new WatchSettings(
            poll_interval_ms: 1000,
            cooldown_seconds: 60,
            max_triggers: 0,
            max_triggers_per_hour: 10,
            max_dump_size_bytes: 1024 * 1024 * 1024,
            backoff_multiplier: 2.0,
            backoff_max_seconds: 3600,
            action_output_dir: '.',
            status_interval_seconds: 60,
            quiet: false,
            memory_limit_bytes: null,
            memory_growth_rate: null,
            memory_peak_watch: false,
            watch_function: null,
            trace_depth_limit: null,
            watch_var: [],
            actions: ['log'],
            action_exec_command: null,
            log_file: null,
            memory_output_format: null,
        );
    }
}
