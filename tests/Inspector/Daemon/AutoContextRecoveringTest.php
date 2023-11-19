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

namespace Reli\Inspector\Daemon;

use Amp\Parallel\Context\ContextException;
use Reli\BaseTestCase;
use Reli\Lib\Amphp\ContextInterface;
use Reli\Lib\Amphp\MessageProtocolInterface;

class AutoContextRecoveringTest extends BaseTestCase
{
    public function testGetContextInvokesFactoryOnFirstTimeUse()
    {
        $context = \Mockery::mock(ContextInterface::class);
        $context_factory = fn () => $context;
        $auto_context_recovering = new AutoContextRecovering($context_factory);
        $result = $auto_context_recovering->getContext();
        $this->assertSame($context, $result);
    }

    public function testGetContextDoesNotInvokeFactoryOnSecondTimeUse()
    {
        $count = 0;
        $context = \Mockery::mock(ContextInterface::class);
        $context_factory = function () use (&$count, $context) {
            $count++;
            return $context;
        };
        $auto_context_recovering = new AutoContextRecovering($context_factory);
        $auto_context_recovering->getContext();
        $result = $auto_context_recovering->getContext();
        $this->assertSame($context, $result);
        $this->assertSame(1, $count);
    }

    public function testRegisteredCallbackIsCalledOnRecover()
    {
        $context = \Mockery::mock(ContextInterface::class);
        $context->expects()->isRunning()->andReturns(false);
        $context_factory = fn () => $context;
        $auto_context_recovering = new AutoContextRecovering($context_factory);
        $auto_context_recovering->getContext();
        $called = false;
        $callback = function () use (&$called) {
            $called = true;
        };
        $auto_context_recovering->onRecover($callback);
        $this->assertFalse($called);
        $auto_context_recovering->recreateContext();
        $auto_context_recovering->getContext();
        $this->assertTrue($called);
    }

    public function testAutoRecoverOnContextException()
    {
        $context = \Mockery::mock(ContextInterface::class);
        $context->expects()->isRunning()->andReturns(false);
        $context->expects()
            ->getProtocol()
            ->andReturns($protocol = \Mockery::mock(MessageProtocolInterface::class))
            ->twice()
        ;
        $context_created_times = 0;
        $context_factory = function () use (&$context_created_times, $context) {
            $context_created_times++;
            return $context;
        };
        $auto_context_recovering = new AutoContextRecovering($context_factory);
        $auto_context_recovering->getContext();
        $called = false;
        $callback = function () use (&$called) {
            $called = true;
        };
        $auto_context_recovering->onRecover($callback);
        $this->assertFalse($called);
        $first_time = true;
        $auto_context_recovering->withAutoRecover(
            function ($protocol_passed) use ($auto_context_recovering, $protocol, &$first_time) {
                $this->assertSame($protocol, $protocol_passed);
                if ($first_time) {
                    $first_time = false;
                    throw new ContextException();
                }
            },
            'test'
        );
        $this->assertTrue($called);
        $this->assertSame(2, $context_created_times);
    }

    public function testThrowOnMaxRetry()
    {
        $context = \Mockery::mock(ContextInterface::class);
        $context->expects()
            ->isRunning()
            ->andReturns(false)
            ->atLeast()
            ->once()
        ;
        $context->expects()
            ->getProtocol()
            ->andReturns($protocol = \Mockery::mock(MessageProtocolInterface::class))
            ->atLeast()
            ->once()
        ;
        $context_factory = fn () => $context;
        $auto_context_recovering = new AutoContextRecovering($context_factory);
        $auto_context_recovering->getContext();
        $this->expectException(ContextException::class);
        $auto_context_recovering->withAutoRecover(
            function ($protocol_passed) use ($protocol) {
                $this->assertSame($protocol, $protocol_passed);
                throw new ContextException();
            },
            'test'
        );
    }

    public function testStopContextOnRecreateIfRunning()
    {
        $context = \Mockery::mock(ContextInterface::class);
        $context->expects()->isRunning()->andReturns(true);
        $context->expects()->stop()->once();
        $context_factory = fn () => $context;
        $auto_context_recovering = new AutoContextRecovering($context_factory);
        $auto_context_recovering->getContext();
        $auto_context_recovering->recreateContext();
    }
}
