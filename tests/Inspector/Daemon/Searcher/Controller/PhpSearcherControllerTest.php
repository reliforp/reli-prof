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
        $context = Mockery::mock(ContextInterface::class);
        $context->expects()->start();
        $php_searcher_context = new PhpSearcherController($context);
        $php_searcher_context->start();
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
        ;
        $php_searcher_context = new PhpSearcherController($context);
        $php_searcher_context->sendTarget(
            'abcdefg',
            new TargetPhpSettings(),
            getmypid(),
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
        $message = new UpdateTargetProcessMessage(
            new TargetProcessList(
                new TargetProcessDescriptor(1, 2, 3, 'v81'),
                new TargetProcessDescriptor(4, 5, 6, 'v81'),
            )
        );
        $protocol->expects()->receiveUpdateTargetProcess()->andReturn($message);
        $php_searcher_context = new PhpSearcherController($context);
        $this->assertSame($message, $php_searcher_context->receivePidList());
    }
}
