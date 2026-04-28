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

use Hamcrest\Matchers;
use Mockery;
use Reli\BaseTestCase;
use Reli\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use Reli\Inspector\Daemon\Reader\Protocol\Message\DetachWorkerMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\AttachMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\SetSettingsMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use Reli\Inspector\Daemon\Reader\Protocol\PhpReaderWorkerProtocolInterface;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use Reli\Inspector\Settings\OutputSettings\OutputSettings;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use Reli\Lib\Loop\LoopCondition\LoopConditionInterface;
use Reli\Lib\Loop\LoopCondition\OnlyOnceCondition;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallFrame;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;

class PhpReaderEntryPointTest extends BaseTestCase
{
    public function testRun(): void
    {
        $settings = new SetSettingsMessage(
            new TraceLoopSettings(1, 'q', 10, false),
            new GetTraceSettings(PHP_INT_MAX)
        );
        $attach = new AttachMessage(
            new TargetProcessDescriptor(
                123,
                0,
                0,
                ZendTypeReader::V80
            )
        );
        $php_reader_task = Mockery::mock(PhpReaderTraceLoopInterface::class);
        $protocol = Mockery::mock(PhpReaderWorkerProtocolInterface::class);
        $protocol->expects()->receiveSettings()->andReturns($settings)->once();
        $protocol->expects()->receiveAttach()->andReturns($attach)->once();
        $php_reader_task->shouldReceive('run')
            ->withArgs(
                function (
                    $trace_loop_serrings,
                    $target_process_descriptor,
                    $get_trace_settings
                ) {
                    $this->assertEquals(
                        [
                            new TraceLoopSettings(1, 'q', 10, false),
                            new TargetProcessDescriptor(123, 0, 0, ZendTypeReader::V80),
                            new GetTraceSettings(PHP_INT_MAX),
                        ],
                        [
                            $trace_loop_serrings,
                            $target_process_descriptor,
                            $get_trace_settings,
                        ]
                    );
                    return true;
                }
            )
            ->andReturns(
                (function () {
                    yield $this->getTestTrace('abc');
                    yield $this->getTestTrace('def');
                    yield $this->getTestTrace('ghi');
                })()
            )
        ;
        $protocol->expects()
            ->sendTrace()
            ->with(Matchers::equalTo($this->getTestTrace('abc', 123)))
        ;
        $protocol->expects()
            ->sendTrace()
            ->with(Matchers::equalTo($this->getTestTrace('def', 123)))
        ;
        $protocol->expects()
            ->sendTrace()
            ->with(Matchers::equalTo($this->getTestTrace('ghi', 123)))
        ;
        $protocol->expects()
            ->sendDetachWorker()
            ->with(Matchers::equalTo(new DetachWorkerMessage(123)))
            ->once();

        $php_reader_entry_point = new PhpReaderEntryPoint(
            $php_reader_task,
            $protocol,
            new OnlyOnceCondition(),
        );

        $php_reader_entry_point->run();
    }

    public function testEmptyCallTracesAreDroppedBeforeIpc(): void
    {
        $settings = new SetSettingsMessage(
            new TraceLoopSettings(1, 'q', 10, false),
            new GetTraceSettings(PHP_INT_MAX)
        );
        $attach = new AttachMessage(
            new TargetProcessDescriptor(
                123,
                0,
                0,
                ZendTypeReader::V80
            )
        );
        $php_reader_task = Mockery::mock(PhpReaderTraceLoopInterface::class);
        $protocol = Mockery::mock(PhpReaderWorkerProtocolInterface::class);
        $protocol->expects()->receiveSettings()->andReturns($settings)->once();
        $protocol->expects()->receiveAttach()->andReturns($attach)->once();
        // The trace loop yields a real frame, then an empty CallTrace
        // (the idle ride-through marker), then another real frame.
        // The empty one must NOT reach sendTrace — dropping it here
        // keeps templates free of blank lines and rbt streams free of
        // misleading zero-frame "idle" samples.
        $php_reader_task->shouldReceive('run')
            ->andReturns(
                (function () {
                    yield $this->getTestTrace('abc');
                    yield new TraceMessage(new CallTrace(), null, null);
                    yield $this->getTestTrace('def');
                })()
            );
        $protocol->expects()
            ->sendTrace()
            ->with(Matchers::equalTo($this->getTestTrace('abc', 123)));
        $protocol->expects()
            ->sendTrace()
            ->with(Matchers::equalTo($this->getTestTrace('def', 123)));
        $protocol->expects()
            ->sendDetachWorker()
            ->with(Matchers::equalTo(new DetachWorkerMessage(123)))
            ->once();

        $entry_point = new PhpReaderEntryPoint(
            $php_reader_task,
            $protocol,
            new OnlyOnceCondition(),
        );

        $entry_point->run();
    }

    public function testIdleWorkerLeavesNoBinaryArtifact(): void
    {
        // A daemon worker that never receives an attach message — i.e.
        // -T is larger than the live target count — must not leave a
        // zero-byte worker_<pid>.rbt behind in the output directory.
        // The output stream is opened lazily on first attach, so an
        // idle worker drops out via shouldContinue() without creating
        // any file.
        $tmpdir = \sys_get_temp_dir() . '/reli-test-idle-worker-' . \getmypid();
        @\mkdir($tmpdir, 0755, true);
        try {
            $output_settings = new OutputSettings(
                output_format: 'rbt',
                output_path: $tmpdir,
                rbt_timestamps: 'delta',
                rbt_compress: false,
            );
            $settings = new SetSettingsMessage(
                new TraceLoopSettings(1, 'q', 10, false),
                new GetTraceSettings(PHP_INT_MAX),
                $output_settings,
            );
            $php_reader_task = Mockery::mock(PhpReaderTraceLoopInterface::class);
            $protocol = Mockery::mock(PhpReaderWorkerProtocolInterface::class);
            $protocol->expects()->receiveSettings()->andReturns($settings)->once();
            // Loop never enters: receiveAttach is not called.
            $protocol->shouldNotReceive('receiveAttach');

            $never = Mockery::mock(LoopConditionInterface::class);
            $never->shouldReceive('shouldContinue')->andReturn(false);

            $entry_point = new PhpReaderEntryPoint(
                $php_reader_task,
                $protocol,
                $never,
            );
            $entry_point->run();

            $files = \glob($tmpdir . '/*');
            $this->assertSame(
                [],
                $files === false ? [] : $files,
                'idle worker must not create any rbt file',
            );
        } finally {
            $files = \glob($tmpdir . '/*');
            if ($files !== false) {
                foreach ($files as $f) {
                    @\unlink($f);
                }
            }
            @\rmdir($tmpdir);
        }
    }

    public function testAttachWithOnlyEmptyTracesLeavesNoArtifact(): void
    {
        // A daemon worker that *does* receive an attach but observes
        // only the trace loop's idle ride-through markers (empty
        // CallTrace) for the entire attach must not leave behind a
        // header+metadata-only worker_<pid>.rbt segment. Header,
        // metadata, writer construction, and stream open are all
        // deferred to the first non-empty trace, so an attach that
        // never produces a real sample also produces no artifact.
        $tmpdir = \sys_get_temp_dir() . '/reli-test-empty-attach-' . \getmypid();
        @\mkdir($tmpdir, 0755, true);
        try {
            $output_settings = new OutputSettings(
                output_format: 'rbt',
                output_path: $tmpdir,
                rbt_timestamps: 'delta',
                rbt_compress: false,
            );
            $settings = new SetSettingsMessage(
                new TraceLoopSettings(1, 'q', 10, false),
                new GetTraceSettings(PHP_INT_MAX),
                $output_settings,
            );
            $attach = new AttachMessage(
                new TargetProcessDescriptor(
                    424242,
                    0,
                    0,
                    ZendTypeReader::V80,
                ),
            );
            $php_reader_task = Mockery::mock(PhpReaderTraceLoopInterface::class);
            $protocol = Mockery::mock(PhpReaderWorkerProtocolInterface::class);
            $protocol->expects()->receiveSettings()->andReturns($settings)->once();
            $protocol->expects()->receiveAttach()->andReturns($attach)->once();
            // Trace loop yields only the idle ride-through marker —
            // no real PHP frames ever observed for this attach.
            $php_reader_task->shouldReceive('run')->andReturns(
                (function () {
                    yield new TraceMessage(new CallTrace(), null, null);
                    yield new TraceMessage(new CallTrace(), null, null);
                    yield new TraceMessage(new CallTrace(), null, null);
                })()
            );
            $protocol->shouldNotReceive('sendTrace');
            $protocol->expects()
                ->sendDetachWorker()
                ->with(Matchers::equalTo(new DetachWorkerMessage(424242)))
                ->once();

            $entry_point = new PhpReaderEntryPoint(
                $php_reader_task,
                $protocol,
                new OnlyOnceCondition(),
            );
            $entry_point->run();

            $files = \glob($tmpdir . '/*');
            $this->assertSame(
                [],
                $files === false ? [] : $files,
                'attach with only empty traces must not create any rbt artifact',
            );
        } finally {
            $files = \glob($tmpdir . '/*');
            if ($files !== false) {
                foreach ($files as $f) {
                    @\unlink($f);
                }
            }
            @\rmdir($tmpdir);
        }
    }

    private function getTestTrace(string $function, ?int $pid = null): TraceMessage
    {
        return new TraceMessage(
            new CallTrace(
                new CallFrame('class', $function, 'file', null)
            ),
            $pid,
        );
    }
}
