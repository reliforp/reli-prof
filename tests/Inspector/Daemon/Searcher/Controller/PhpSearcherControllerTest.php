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

namespace PhpProfiler\Inspector\Daemon\Searcher\Controller;

use Amp\Promise;
use Mockery;
use PhpProfiler\Inspector\Daemon\Searcher\Protocol\Message\TargetPhpSettingsMessage;
use PhpProfiler\Inspector\Daemon\Searcher\Protocol\PhpSearcherControllerProtocolInterface;
use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use PhpProfiler\Lib\Amphp\ContextInterface;
use PHPUnit\Framework\TestCase;

class PhpSearcherControllerTest extends TestCase
{
    public function testStart(): void
    {
        $context = Mockery::mock(ContextInterface::class);
        $context->expects()->start()->andReturn(Mockery::mock(Promise::class));
        $php_searcher_context = new PhpSearcherController($context);
        $this->assertInstanceOf(Promise::class, $php_searcher_context->start());
    }

    public function testSendTargetRegex(): void
    {
        $protocol = Mockery::mock(PhpSearcherControllerProtocolInterface::class);
        $context = Mockery::mock(ContextInterface::class);
        $context->expects()
            ->getProtocol()
            ->andReturns($protocol)
        ;
        $protocol->shouldReceive('sendTargetRegex')
            ->once()
            ->withArgs(
                function (TargetPhpSettingsMessage $message) {
                    $this->assertSame('abcdefg', $message->regex);
                    return true;
                }
            )
            ->andReturn(Mockery::mock(Promise::class))
        ;
        $php_searcher_context = new PhpSearcherController($context);
        $this->assertInstanceOf(
            Promise::class,
            $php_searcher_context->sendTarget(
                'abcdefg',
                new TargetPhpSettings()
            )
        );
    }

    public function testReceivePidList(): void
    {
        $protocol = Mockery::mock(PhpSearcherControllerProtocolInterface::class);
        $context = Mockery::mock(ContextInterface::class);
        $context->expects()
            ->getProtocol()
            ->andReturns($protocol)
        ;
        $protocol->expects()->receiveUpdateTargetProcess()->andReturn(Mockery::mock(Promise::class));
        $php_searcher_context = new PhpSearcherController($context);
        $this->assertInstanceOf(Promise::class, $php_searcher_context->receivePidList());
    }
}
