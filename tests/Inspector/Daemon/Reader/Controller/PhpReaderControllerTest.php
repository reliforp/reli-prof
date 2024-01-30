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
use Reli\BaseTestCase;
use Reli\Inspector\Daemon\AutoContextRecoveringInterface;
use Reli\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use Reli\Inspector\Daemon\Reader\Protocol\Message\AttachMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\SetSettingsMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use Reli\Inspector\Daemon\Reader\Protocol\PhpReaderControllerProtocolInterface;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use Reli\Lib\Amphp\ContextInterface;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallFrame;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;

final class PhpReaderControllerTest extends BaseTestCase
{
    public function testStart(): void
    {
        $auto_context_recovering = Mockery::mock(AutoContextRecoveringInterface::class);
        $auto_context_recovering->expects()
            ->getContext()
            ->andReturn($context = Mockery::mock(ContextInterface::class))
            ->zeroOrMoreTimes()
        ;
        $auto_context_recovering->expects()->onRecover(Mockery::type('\Closure'));
        $context->expects()->start();
        $php_reader_context = new PhpReaderController($auto_context_recovering);
        $php_reader_context->start();
    }

    public function testIsRunning(): void
    {
        $auto_context_recovering = Mockery::mock(AutoContextRecoveringInterface::class);
        $auto_context_recovering->expects()
            ->getContext()
            ->andReturn($context = Mockery::mock(ContextInterface::class))
            ->zeroOrMoreTimes()
        ;
        $auto_context_recovering->expects()->onRecover(Mockery::type('\Closure'));
        $context->expects()->isRunning()->andReturn(true);
        $context->expects()->isRunning()->andReturn(false);
        $php_reader_context = new PhpReaderController($auto_context_recovering);
        $this->assertTrue($php_reader_context->isRunning());
        $this->assertFalse($php_reader_context->isRunning());
    }

    public function testSendSettings(): void
    {
        $trace_loop_settings = new TraceLoopSettings(1, 'q', 1, false);
        $get_trace_settings = new GetTraceSettings(1, false);

        $expected = new SetSettingsMessage(
            $trace_loop_settings,
            $get_trace_settings
        );

        $protocol = Mockery::mock(PhpReaderControllerProtocolInterface::class);
        $protocol->expects()
            ->sendSettings()
            ->with(
                Mockery::on(function (SetSettingsMessage $actual) use ($expected) {
                    $this->assertEquals($expected, $actual);
                    return true;
                })
            )
        ;
        $auto_context_recovering = Mockery::mock(AutoContextRecoveringInterface::class);
        $auto_context_recovering->expects()->onRecover(Mockery::type('\Closure'));
        $auto_context_recovering->expects()
            ->withAutoRecover()
            ->with(
                Mockery::on(function (\Closure $actual) use ($protocol, &$return_value) {
                    $return_value = $actual($protocol);
                    return true;
                }),
                'failed on sending settings to worker'
            )
        ;
        $php_reader_context = new PhpReaderController($auto_context_recovering);
        $php_reader_context->sendSettings($trace_loop_settings, $get_trace_settings);
    }

    public function testSendAttach(): void
    {
        $expected = new AttachMessage(
            new TargetProcessDescriptor(1, 0, 0, ZendTypeReader::V80)
        );
        $protocol = Mockery::mock(PhpReaderControllerProtocolInterface::class);
        $protocol->expects()
            ->sendAttach()
            ->with(
                Mockery::on(function (AttachMessage $actual) use ($expected) {
                    $this->assertEquals($expected, $actual);
                    return true;
                })
            )
        ;
        $auto_context_recovering = Mockery::mock(AutoContextRecoveringInterface::class);
        $auto_context_recovering->expects()->onRecover(Mockery::type('\Closure'));
        $auto_context_recovering->expects()
            ->withAutoRecover()
            ->with(
                Mockery::on(function (\Closure $actual) use ($protocol, &$return_value) {
                    $return_value = $actual($protocol);
                    return true;
                }),
                'failed on attaching worker'
            )
        ;
        $php_reader_context = new PhpReaderController($auto_context_recovering);
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
        $auto_context_recovering = Mockery::mock(AutoContextRecoveringInterface::class);
        $auto_context_recovering->expects()->onRecover(Mockery::type('\Closure'));
        $auto_context_recovering->expects()
            ->withAutoRecover()
            ->with(
                Mockery::on(function (\Closure $actual) use ($protocol, &$return_value) {
                    $return_value = $actual($protocol);
                    return true;
                }),
                'failed to receive trace or detach worker'
            )
            ->andReturns($trace_message)
        ;
        $php_reader_context = new PhpReaderController($auto_context_recovering);
        $php_reader_context->receiveTraceOrDetachWorker();
        $this->assertSame($trace_message, $return_value);
    }

    public function testNothingIsSentOnRecoverIfSettingsHaveNotSent()
    {
        $auto_context_recovering = Mockery::mock(AutoContextRecoveringInterface::class);
        $auto_context_recovering->expects()
            ->onRecover()
            ->with(
                Mockery::on(function (\Closure $actual) use (&$handler) {
                    $handler = $actual;
                    return true;
                })
            )
        ;
        $php_reader_context = new PhpReaderController($auto_context_recovering);
        $auto_context_recovering->expects()
            ->getContext()
            ->never()
        ;
        $handler();
    }

    public function testSettingsIsResentOnRecoverIfOnceSent()
    {
        $trace_loop_settings = new TraceLoopSettings(1, 'q', 1, false);
        $get_trace_settings = new GetTraceSettings(1, false);

        $expected = new SetSettingsMessage(
            $trace_loop_settings,
            $get_trace_settings
        );

        $protocol = Mockery::mock(PhpReaderControllerProtocolInterface::class);
        $protocol->expects()
            ->sendSettings()
            ->with(
                Mockery::on(function (SetSettingsMessage $actual) use ($expected) {
                    $this->assertEquals($expected, $actual);
                    return true;
                })
            )
            ->twice()
        ;

        $auto_context_recovering = Mockery::mock(AutoContextRecoveringInterface::class);
        $auto_context_recovering->expects()
            ->onRecover()
            ->with(
                Mockery::on(function (\Closure $actual) use (&$handler) {
                    $handler = $actual;
                    return true;
                })
            )
        ;
        $auto_context_recovering->expects()
            ->withAutoRecover()
            ->with(
                Mockery::on(function (\Closure $actual) use ($protocol, &$trace_message) {
                    $trace_message = $actual($protocol);
                    return true;
                }),
                'failed on sending settings to worker'
            )
        ;
        $php_reader_context = new PhpReaderController($auto_context_recovering);
        $php_reader_context->sendSettings($trace_loop_settings, $get_trace_settings);
        $auto_context_recovering->expects()
            ->getContext()
            ->andReturn($context = Mockery::mock(ContextInterface::class))
        ;
        $context->expects()->getProtocol()->andReturn($protocol);

        $handler();
    }

    public function testAttachMessageIsResentOnRecoverIfOnceSent()
    {
        $expected = new AttachMessage(
            new TargetProcessDescriptor(1, 0, 0, ZendTypeReader::V80)
        );
        $protocol = Mockery::mock(PhpReaderControllerProtocolInterface::class);
        $protocol->expects()
            ->sendAttach()
            ->with(
                Mockery::on(function (AttachMessage $actual) use ($expected) {
                    $this->assertEquals($expected, $actual);
                    return true;
                })
            )
            ->twice()
        ;
        $auto_context_recovering = Mockery::mock(AutoContextRecoveringInterface::class);
        $auto_context_recovering->expects()
            ->onRecover()
            ->with(
                Mockery::on(function (\Closure $actual) use (&$handler) {
                    $handler = $actual;
                    return true;
                })
            )
        ;
        $auto_context_recovering->expects()
            ->withAutoRecover()
            ->with(
                Mockery::on(function (\Closure $actual) use ($protocol, &$return_value) {
                    $return_value = $actual($protocol);
                    return true;
                }),
                'failed on attaching worker'
            )
        ;
        $php_reader_context = new PhpReaderController($auto_context_recovering);
        $php_reader_context->sendAttach(
            new TargetProcessDescriptor(1, 0, 0, ZendTypeReader::V80)
        );
        $auto_context_recovering->expects()
            ->getContext()
            ->andReturn($context = Mockery::mock(ContextInterface::class))
        ;
        $context->expects()->getProtocol()->andReturn($protocol);

        $handler();
    }
}
