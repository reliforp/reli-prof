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

namespace Reli\Inspector\Daemon\Reader\Controller;

use Mockery;
use Reli\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use Reli\Inspector\Daemon\Reader\Protocol\Message\AttachMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\SetSettingsMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use Reli\Inspector\Daemon\Reader\Protocol\PhpReaderControllerProtocolInterface;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use Reli\Lib\Amphp\ContextInterface;
use Reli\Lib\PhpInternals\ZendTypeReader;
use PHPUnit\Framework\TestCase;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallFrame;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;

final class PhpReaderControllerTest extends TestCase
{
    public function testStart(): void
    {
        $context = Mockery::mock(ContextInterface::class);
        $context->expects()->start();
        $php_reader_context = new PhpReaderController($context);
        $php_reader_context->start();
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
            );
        $context = Mockery::mock(ContextInterface::class);
        $context->expects()
            ->getProtocol()
            ->andReturns($protocol)
        ;
        $php_reader_context = new PhpReaderController($context);
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
                            new TargetProcessDescriptor(1, 0, 0, ZendTypeReader::V80)
                        ),
                        $actual
                    );
                    return true;
                })
            )
        ;
        $context = Mockery::mock(ContextInterface::class);
        $context->expects()
            ->getProtocol()
            ->andReturns($protocol)
        ;
        $php_reader_context = new PhpReaderController($context);
        $php_reader_context->sendAttach(
            new TargetProcessDescriptor(1, 0, 0, ZendTypeReader::V80)
        );
    }

    public function testReceiveTrace(): void
    {
        $trace_message = new TraceMessage(
            new CallTrace(
                new CallFrame(
                    'class_name',
                    'function_name',
                    'file_name',
                    null,
                )
            )
        );
        $protocol = Mockery::mock(PhpReaderControllerProtocolInterface::class);
        $protocol->expects()->receiveTraceOrDetachWorker()->andReturn($trace_message);
        $context = Mockery::mock(ContextInterface::class);
        $context->expects()
            ->getProtocol()
            ->andReturns($protocol)
        ;
        $php_reader_context = new PhpReaderController($context);
        $this->assertSame($trace_message, $php_reader_context->receiveTraceOrDetachWorker());
    }
}
