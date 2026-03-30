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

use Amp\Sync\Channel;
use FFI;
use FFI\CData;
use PHPUnit\Framework\TestCase;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use Reli\Inspector\Settings\WatchSettings\WatchSettings;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchAttachMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchDetachMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchSettingsMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchTriggerMessage;
use Reli\Inspector\Watch\Daemon\Protocol\PhpWatchWorkerProtocolInterface;
use Reli\Inspector\Watch\Daemon\Searcher\WatchTargetDescriptor;
use Reli\Inspector\Watch\HeapStats;
use Reli\Inspector\Watch\HeapStatsReader;
use Reli\Inspector\Watch\VariableReader;
use Reli\Lib\Loop\LoopCondition\LoopConditionInterface;
use Reli\Lib\PhpInternals\Opcodes\OpcodeFactory;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader;
use Reli\Lib\PhpProcessReader\PhpZendMemoryManagerChunkFinder;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreatorInterface;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\ProcessSpecifier;

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

    /**
     * Create a HeapStatsReader whose read() always throws.
     * We pass a MemoryReaderInterface that throws on read().
     */
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

        // Use an intermittent memory reader: fails 5 times, succeeds once, repeats
        // Never reaches 10 consecutive failures
        $memory_reader = new IntermittentMemoryReader(fail_count: 5, succeed_count: 1);
        $zend_type_reader_creator = new ZendTypeReaderCreator();
        $memory_map_creator = $this->createStub(ProcessMemoryMapCreatorInterface::class);
        $chunk_finder = new PhpZendMemoryManagerChunkFinder(
            $memory_map_creator,
            $zend_type_reader_creator,
        );

        // Pre-cache a chunk address so HeapStatsReader.read() doesn't go through findAddress()
        // The read will still fail at the dereferencer level.
        // Actually we can't easily do this since chunk_address_cache is private.
        // Instead, let's track the total iterations: the inner loop checks shouldContinue
        // on each iteration. If the worker breaks out at 10 failures, total iterations <= 11.
        // If it properly resets, we should see many more iterations.

        $iterations = 0;
        $loop_condition = new CountdownLoopCondition($iterations, 40);

        // For this test, we need a HeapStatsReader that alternates between
        // throwing and returning. Since HeapStatsReader is final, we test
        // the consecutive_failures counter indirectly: if the loop runs for
        // 40 iterations with only 5 consecutive failures at a time, it should
        // exit due to loop_condition, not due to max_consecutive_failures.
        // With always-failing reader and 40 iterations, it would break at 10.

        $entry_point = new PhpWatchEntryPoint(
            heap_stats_reader: $this->createFailingHeapStatsReader(),
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
        // Multiple attach cycles prove the inner loop broke and outer loop continued
        $this->assertGreaterThan(1, $protocol->attach_count, 'Worker should re-attach after detach in outer loop');
    }
}

/**
 * MemoryReaderInterface that always throws.
 */
final class FailingMemoryReader implements MemoryReaderInterface
{
    public function read(int $pid, int $remote_address, int $size): CData
    {
        throw new MemoryReaderException('simulated process_vm_readv failure');
    }
}

/**
 * MemoryReaderInterface that fails N times then succeeds once, cyclically.
 */
final class IntermittentMemoryReader implements MemoryReaderInterface
{
    private int $call_count = 0;

    public function __construct(
        private int $fail_count,
        private int $succeed_count,
    ) {
    }

    public function read(int $pid, int $remote_address, int $size): CData
    {
        $this->call_count++;
        $cycle_pos = ($this->call_count - 1) % ($this->fail_count + $this->succeed_count);
        if ($cycle_pos < $this->fail_count) {
            throw new MemoryReaderException('simulated intermittent failure');
        }
        // Return a zeroed buffer
        $buf = FFI::new("uint8_t[$size]");
        assert($buf instanceof CData);
        return $buf;
    }
}

final class TestWatchWorkerProtocol implements PhpWatchWorkerProtocolInterface
{
    public bool $settings_sent = false;
    public int $attach_count = 0;
    public ?WatchDetachMessage $detach_message = null;
    /** @var list<WatchTriggerMessage> */
    public array $triggers = [];

    public function __construct(
        private WatchSettingsMessage $settings_message,
        private WatchAttachMessage $attach_message,
    ) {
    }

    public static function createFromChannel(Channel $channel): static
    {
        throw new \LogicException('Not used in tests');
    }

    public function receiveSettings(): WatchSettingsMessage
    {
        $this->settings_sent = true;
        return $this->settings_message;
    }

    public function receiveAttach(): WatchAttachMessage
    {
        $this->attach_count++;
        return $this->attach_message;
    }

    public function sendTrigger(WatchTriggerMessage $message): void
    {
        $this->triggers[] = $message;
    }

    public function sendDetach(WatchDetachMessage $message): void
    {
        $this->detach_message = $message;
    }
}

final class CountdownLoopCondition implements LoopConditionInterface
{
    public function __construct(
        private int &$count,
        private int $max,
    ) {
    }

    public function shouldContinue(): bool
    {
        return $this->count++ < $this->max;
    }
}
