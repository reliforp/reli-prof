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

namespace Reli\Inspector\Daemon\Searcher\Controller;

use Mockery;
use Reli\BaseTestCase;
use Reli\Inspector\Daemon\AutoContextRecoveringInterface;
use Reli\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use Reli\Inspector\Daemon\Dispatcher\TargetProcessList;
use Reli\Inspector\Daemon\Searcher\Protocol\Message\TargetPhpSettingsMessage;
use Reli\Inspector\Daemon\Searcher\Protocol\Message\UpdateTargetProcessMessage;
use Reli\Inspector\Daemon\Searcher\Protocol\PhpSearcherControllerProtocolInterface;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\Amphp\ContextInterface;
use PHPUnit\Framework\TestCase;

class PhpSearcherControllerTest extends BaseTestCase
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
        $php_searcher_context = new PhpSearcherController($auto_context_recovering);
        $php_searcher_context->start();
    }

    public function testSendTargetRegex(): void
    {
        $protocol = Mockery::mock(PhpSearcherControllerProtocolInterface::class);
        $auto_context_recovering = Mockery::mock(AutoContextRecoveringInterface::class);
        $auto_context_recovering->expects()
            ->getContext()
            ->andReturn($context = Mockery::mock(ContextInterface::class))
            ->zeroOrMoreTimes()
        ;
        $auto_context_recovering->expects()->onRecover(Mockery::type('\Closure'));
        $auto_context_recovering->expects()
            ->withAutoRecover()
            ->with(
                Mockery::on(function (\Closure $actual) use ($protocol, &$return_value) {
                    $return_value = $actual($protocol);
                    return true;
                }),
                'failed to send target'
            )
        ;
        $protocol->shouldReceive('sendTargetRegex')
            ->once()
            ->withArgs(
                function (TargetPhpSettingsMessage $message) {
                    $this->assertSame('abcdefg', $message->regex);
                    return true;
                }
            )
        ;
        $php_searcher_context = new PhpSearcherController($auto_context_recovering);
        $php_searcher_context->sendTarget(
            'abcdefg',
            new TargetPhpSettings(),
            getmypid(),
        );
    }

    public function testReceivePidList(): void
    {
        $message = new UpdateTargetProcessMessage(
            new TargetProcessList(
                new TargetProcessDescriptor(1, 2, 3, 'v81'),
                new TargetProcessDescriptor(4, 5, 6, 'v81'),
            )
        );
        $protocol = Mockery::mock(PhpSearcherControllerProtocolInterface::class);
        $auto_context_recovering = Mockery::mock(AutoContextRecoveringInterface::class);
        $auto_context_recovering->expects()
            ->getContext()
            ->andReturn($context = Mockery::mock(ContextInterface::class))
            ->zeroOrMoreTimes()
        ;
        $auto_context_recovering->expects()->onRecover(Mockery::type('\Closure'));
        $auto_context_recovering->expects()
            ->withAutoRecover()
            ->with(
                Mockery::on(function (\Closure $actual) use ($protocol, &$return_value) {
                    $return_value = $actual($protocol);
                    return true;
                }),
                'failed to receive pid list',
            )
            ->andReturns($message)
        ;
        $protocol->expects()->receiveUpdateTargetProcess()->andReturn($message);
        $php_searcher_context = new PhpSearcherController($auto_context_recovering);
        $php_searcher_context->receivePidList();
        $this->assertSame($message, $return_value);
    }

    public function testNothingIsSentOnRecoverIfTargetHasNotSent()
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
        $auto_context_recovering->expects()
            ->getContext()
            ->never()
        ;
        $php_searcher_context = new PhpSearcherController($auto_context_recovering);
        $handler();
    }

    public function testTargetIsResentIfOnceSent()
    {
        $protocol = Mockery::mock(PhpSearcherControllerProtocolInterface::class);
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
            ->getContext()
            ->andReturn($context = Mockery::mock(ContextInterface::class))
            ->zeroOrMoreTimes()
        ;
        $auto_context_recovering->expects()
            ->withAutoRecover()
            ->with(
                Mockery::on(function (\Closure $actual) use ($protocol, &$return_value) {
                    $return_value = $actual($protocol);
                    return true;
                }),
                'failed to send target'
            )
        ;
        $context->expects()
            ->getProtocol()
            ->andReturns($protocol)
        ;
        $protocol->shouldReceive('sendTargetRegex')
            ->twice()
            ->withArgs(
                function (TargetPhpSettingsMessage $message) {
                    $this->assertSame('abcdefg', $message->regex);
                    return true;
                }
            )
        ;
        $php_searcher_context = new PhpSearcherController($auto_context_recovering);
        $php_searcher_context->sendTarget(
            'abcdefg',
            new TargetPhpSettings(),
            getmypid(),
        );
        $handler();
    }
}
