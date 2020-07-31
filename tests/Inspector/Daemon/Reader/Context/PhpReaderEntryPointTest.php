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
use PhpProfiler\Inspector\Daemon\Dispatcher\Message\DetachWorkerMessage;
use PhpProfiler\Inspector\Daemon\Reader\Message\AttachMessage;
use PhpProfiler\Inspector\Daemon\Reader\Message\SetSettingsMessage;
use PhpProfiler\Inspector\Daemon\Reader\PhpReaderTaskInterface;
use PhpProfiler\Inspector\Settings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TargetProcessSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings;
use PHPUnit\Framework\TestCase;

class PhpReaderEntryPointTest extends TestCase
{
    public function testRun(): void
    {
        $php_reader_task = Mockery::mock(PhpReaderTaskInterface::class);
        $channel = Mockery::mock(Channel::class);
        $channel->expects()->receive()->andReturns(new Success(1))->once();
        $channel->expects()->receive()->andReturns(new Success(2))->once();
        $php_reader_task->shouldReceive('run')
            ->withArgs(
                function (
                    $channel_passed,
                    $target_process_settings,
                    $trace_loop_serrings,
                    $target_php_settings,
                    $get_trace_settings
                ) use ($channel) {
                    $this->assertSame($channel, $channel_passed);
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
                    yield new Success(3);
                    yield new Success(4);
                    yield new Success(5);
                })()
            )
        ;
        $channel->shouldReceive('send')
            ->with(Matchers::equalTo(new DetachWorkerMessage(123)))
            ->andReturns(new Success(6))
            ->once();

        $php_reader_entry_point = new PhpReaderEntryPoint($php_reader_task);

        $generator = $php_reader_entry_point->run($channel);

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
