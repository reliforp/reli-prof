<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Inspector\Daemon\Reader\Context;

use Amp\Parallel\Sync\Channel;
use Amp\Success;
use Hamcrest\Matchers;
use Mockery;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\DetachWorkerMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\AttachMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\SetSettingsMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\PhpReaderWorkerProtocolInterface;
use PhpProfiler\Inspector\Daemon\Reader\Worker\PhpReaderTraceLoopInterface;
use PhpProfiler\Inspector\Daemon\Reader\Worker\PhpReaderEntryPoint;
use PhpProfiler\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TargetProcessSettings\TargetProcessSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use PHPUnit\Framework\TestCase;

class PhpReaderEntryPointTest extends TestCase
{
    public function testRun(): void
    {
        $php_reader_task = Mockery::mock(PhpReaderTraceLoopInterface::class);
        $protocol = Mockery::mock(PhpReaderWorkerProtocolInterface::class);
        $protocol->expects()->receiveSettings()->andReturns(new Success(1))->once();
        $protocol->expects()->receiveAttach()->andReturns(new Success(2))->once();
        $php_reader_task->shouldReceive('run')
            ->withArgs(
                function (
                    $target_process_settings,
                    $trace_loop_serrings,
                    $target_php_settings,
                    $get_trace_settings
                ) {
                    $this->assertEquals(
                        [
                            new TargetProcessSettings(123),
                            new TraceLoopSettings(1, 'q', 10),
                            new TargetPhpSettings(),
                            new GetTraceSettings(PHP_INT_MAX),
                        ],
                        [
                            $target_process_settings,
                            $trace_loop_serrings,
                            $target_php_settings,
                            $get_trace_settings,
                        ]
                    );
                    return true;
                }
            )
            ->andReturns(
                (function () {
                    yield new TraceMessage(['abc']);
                    yield new TraceMessage(['def']);
                    yield new TraceMessage(['ghi']);
                })()
            )
        ;
        $protocol->expects()
            ->sendTrace()
            ->with(Matchers::equalTo(new TraceMessage(['abc'])))
            ->andReturns(
                new Success(3),
            )
        ;
        $protocol->expects()
            ->sendTrace()
            ->with(Matchers::equalTo(new TraceMessage(['def'])))
            ->andReturns(
                new Success(4),
            )
        ;
        $protocol->expects()
            ->sendTrace()
            ->with(Matchers::equalTo(new TraceMessage(['ghi'])))
            ->andReturns(
                new Success(5),
            )
        ;
        $protocol->expects()
            ->sendDetachWorker()
            ->with(Matchers::equalTo(new DetachWorkerMessage(123)))
            ->andReturns(new Success(6))
            ->once();

        $php_reader_entry_point = new PhpReaderEntryPoint($php_reader_task, $protocol);

        $generator = $php_reader_entry_point->run();

        $promise = $generator->current();
        $this->assertInstanceOf(Success::class, $promise);
        $promise->onResolve(function ($error, $value) use (&$result) {
            $result = $value;
        });
        $this->assertSame(1, $result);

        $promise = $generator->send(
            new SetSettingsMessage(
                new TargetPhpSettings(),
                new TraceLoopSettings(1, 'q', 10),
                new GetTraceSettings(PHP_INT_MAX)
            )
        );
        $this->assertInstanceOf(Success::class, $promise);
        $promise->onResolve(function ($error, $value) use (&$result) {
            $result = $value;
        });
        $this->assertSame(2, $result);

        $promise = $generator->send(new AttachMessage(123));
        $this->assertInstanceOf(Success::class, $promise);
        $promise->onResolve(function ($error, $value) use (&$result) {
            $result = $value;
        });
        $this->assertSame(3, $result);

        $promise = $generator->send(null);
        $this->assertInstanceOf(Success::class, $promise);
        $promise->onResolve(function ($error, $value) use (&$result) {
            $result = $value;
        });
        $this->assertSame(4, $result);

        $promise = $generator->send(null);
        $this->assertInstanceOf(Success::class, $promise);
        $promise->onResolve(function ($error, $value) use (&$result) {
            $result = $value;
        });
        $this->assertSame(5, $result);

        $promise = $generator->send(null);
        $this->assertInstanceOf(Success::class, $promise);
        $promise->onResolve(function ($error, $value) use (&$result) {
            $result = $value;
        });
        $this->assertSame(6, $result);
    }
}
