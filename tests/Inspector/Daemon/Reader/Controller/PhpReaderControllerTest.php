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

namespace PhpProfiler\Inspector\Daemon\Reader\Controller;

use Amp\Promise;
use Mockery;
use PhpProfiler\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\AttachMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\SetSettingsMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\PhpReaderControllerProtocolInterface;
use PhpProfiler\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use PhpProfiler\Lib\Amphp\ContextInterface;
use PhpProfiler\Lib\PhpInternals\ZendTypeReader;
use PHPUnit\Framework\TestCase;

final class PhpReaderControllerTest extends TestCase
{
    public function testStart(): void
    {
        $context = Mockery::mock(ContextInterface::class);
        $context->expects()->start()->andReturn(Mockery::mock(Promise::class));
        $php_reader_context = new PhpReaderController($context);
        $this->assertInstanceOf(Promise::class, $php_reader_context->start());
    }

    public function testIsRunning(): void
    {
        $context = Mockery::mock(ContextInterface::class);
        $context->expects()->isRunning()->andReturn(true);
        $context->expects()->isRunning()->andReturn(false);
        $php_reader_context = new PhpReaderController($context);
        $this->assertTrue($php_reader_context->isRunning());
        $this->assertFalse($php_reader_context->isRunning());
    }

    public function testSendSettings(): void
    {
        $trace_loop_settings = new TraceLoopSettings(1, 'q', 1, false);
        $get_trace_settings = new GetTraceSettings(1);

        $expected = new SetSettingsMessage(
            $trace_loop_settings,
            $get_trace_settings
        );

        $protocol = Mockery::mock(PhpReaderControllerProtocolInterface::class);
        $protocol->shouldReceive('sendSettings')
            ->once()
            ->with(
                Mockery::on(function (SetSettingsMessage $actual) use ($expected) {
                    $this->assertEquals($actual, $expected);
                    return true;
                })
            )
            ->andReturn(Mockery::mock(Promise::class));
        $context = Mockery::mock(ContextInterface::class);
        $context->expects()
            ->getProtocol()
            ->andReturns($protocol)
        ;
        $php_reader_context = new PhpReaderController($context);
        $this->assertInstanceOf(
            Promise::class,
            $php_reader_context->sendSettings(
                $trace_loop_settings,
                $get_trace_settings
            )
        );
    }

    public function testSendAttach(): void
    {
        $protocol = Mockery::mock(PhpReaderControllerProtocolInterface::class);
        $protocol->expects()
            ->sendAttach()
            ->with(
                Mockery::on(function (AttachMessage $actual) {
                    $this->assertEquals(
                        new AttachMessage(
                            new TargetProcessDescriptor(1, 0, ZendTypeReader::V80)
                        ),
                        $actual
                    );
                    return true;
                })
            )
            ->andReturn(
                Mockery::mock(Promise::class)
            )
        ;
        $context = Mockery::mock(ContextInterface::class);
        $context->expects()
            ->getProtocol()
            ->andReturns($protocol)
        ;
        $php_reader_context = new PhpReaderController($context);
        $this->assertInstanceOf(
            Promise::class,
            $php_reader_context->sendAttach(
                new TargetProcessDescriptor(1, 0, ZendTypeReader::V80)
            )
        );
    }

    public function testReceiveTrace(): void
    {
        $protocol = Mockery::mock(PhpReaderControllerProtocolInterface::class);
        $protocol->expects()->receiveTraceOrDetachWorker()->andReturn(Mockery::mock(Promise::class));
        $context = Mockery::mock(ContextInterface::class);
        $context->expects()
            ->getProtocol()
            ->andReturns($protocol)
        ;
        $php_reader_context = new PhpReaderController($context);
        $this->assertInstanceOf(Promise::class, $php_reader_context->receiveTraceOrDetachWorker());
    }
}
