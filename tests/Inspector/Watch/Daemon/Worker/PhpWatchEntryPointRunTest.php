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

namespace Reli\Inspector\Watch\Daemon\Worker;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use Reli\Inspector\Settings\WatchSettings\WatchSettings;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchAttachMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchSettingsMessage;
use Reli\Inspector\Watch\Daemon\Searcher\WatchTargetDescriptor;
use Reli\Inspector\Watch\HeapStatsReader;
use Reli\Inspector\Watch\VariableReader;
use Reli\Lib\PhpInternals\Opcodes\OpcodeFactory;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader;
use Reli\Lib\PhpProcessReader\PhpZendMemoryManagerChunkFinder;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreatorInterface;

#[RunTestsInSeparateProcesses]
class PhpWatchEntryPointRunTest extends TestCase
{
    private function createSettingsMessage(): WatchSettingsMessage
    {
        return new WatchSettingsMessage(
            watch_settings: new WatchSettings(
                poll_interval_ms: 1,
                cooldown_seconds: 60,
                max_triggers: 0,
                max_triggers_per_hour: 10,
                max_dump_size_bytes: 1024,
                backoff_multiplier: 2.0,
                backoff_max_seconds: 3600,
                action_output_dir: '/tmp',
                status_interval_seconds: 60,
                quiet: true,
                memory_usage_bytes: 1024 * 1024,
                memory_growth_rate: null,
                memory_peak_watch: false,
                rss_usage_bytes: null,
                watch_function: null,
                trace_depth_limit: null,
                watch_var: [],
                actions: [],
                action_exec_command: null,
                log_file: null,
                memory_output_format: null,
            ),
            trace_loop_settings: new TraceLoopSettings(
                sleep_nano_seconds: 1000,
                cancel_key: 'q',
                max_retries: 10,
                stop_process: false,
            ),
            get_trace_settings: new GetTraceSettings(depth: 64),
        );
    }

    private function createAttachMessage(int $pid = 12345): WatchAttachMessage
    {
        return new WatchAttachMessage(
            new WatchTargetDescriptor(
                pid: $pid,
                eg_address: 0x1000,
                sg_address: 0x2000,
                cg_address: 0x3000,
                php_version: 'v84',
            ),
        );
    }

    private function createProtocol(int $pid = 12345): TestWatchWorkerProtocol
    {
        return new TestWatchWorkerProtocol(
            $this->createSettingsMessage(),
            $this->createAttachMessage($pid),
        );
    }

    private function createFailingHeapStatsReader(): HeapStatsReader
    {
        $memory_reader = new FailingMemoryReader();
        $zend_type_reader_creator = new ZendTypeReaderCreator();
        $memory_map_creator = $this->createStub(ProcessMemoryMapCreatorInterface::class);
        $chunk_finder = new PhpZendMemoryManagerChunkFinder(
            $memory_map_creator,
            $zend_type_reader_creator,
        );
        return new HeapStatsReader($chunk_finder, $memory_reader, $zend_type_reader_creator);
    }

    private function createCallTraceReader(): CallTraceReader
    {
        return new CallTraceReader(
            new FailingMemoryReader(),
            new ZendTypeReaderCreator(),
            new OpcodeFactory(),
        );
    }

    private function createVariableReader(): VariableReader
    {
        return new VariableReader(
            new FailingMemoryReader(),
            new ZendTypeReaderCreator(),
        );
    }

    public function testDetachesAfterConsecutiveReadFailures(): void
    {
        $protocol = $this->createProtocol(12345);

        $iterations = 0;
        $loop_condition = new CountdownLoopCondition($iterations, 15);

        $entry_point = new PhpWatchEntryPoint(
            heap_stats_reader: $this->createFailingHeapStatsReader(),
            rss_reader: new \Reli\Inspector\Watch\RssReader(),
            cpu_usage_reader: new \Reli\Inspector\Watch\CpuUsageReader(),
            call_trace_reader: $this->createCallTraceReader(),
            variable_reader: $this->createVariableReader(),
            protocol: $protocol,
            loop_condition: $loop_condition,
        );

        $entry_point->run();

        $this->assertTrue($protocol->settings_sent);
        $this->assertGreaterThanOrEqual(1, $protocol->attach_count);
        $this->assertNotNull($protocol->detach_message, 'Worker should send detach after consecutive failures');
        $this->assertSame(12345, $protocol->detach_message->pid);
        $this->assertEmpty($protocol->triggers);
    }

    public function testResetsFailureCountOnSuccessfulRead(): void
    {
        $protocol = $this->createProtocol(99999);

        $iterations = 0;
        $loop_condition = new CountdownLoopCondition($iterations, 40);

        $entry_point = new PhpWatchEntryPoint(
            heap_stats_reader: $this->createFailingHeapStatsReader(),
            rss_reader: new \Reli\Inspector\Watch\RssReader(),
            cpu_usage_reader: new \Reli\Inspector\Watch\CpuUsageReader(),
            call_trace_reader: $this->createCallTraceReader(),
            variable_reader: $this->createVariableReader(),
            protocol: $protocol,
            loop_condition: $loop_condition,
        );

        $entry_point->run();

        // With always-failing reads, the worker should detach after 10 failures
        // and then re-attach (outer loop), so we should see multiple attach cycles
        // within 40 iterations.
        $this->assertNotNull($protocol->detach_message);
        $this->assertSame(99999, $protocol->detach_message->pid);
        $this->assertGreaterThan(1, $protocol->attach_count, 'Worker should re-attach after detach in outer loop');
    }
}
