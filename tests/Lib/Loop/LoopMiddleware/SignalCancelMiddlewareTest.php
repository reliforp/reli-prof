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

namespace Reli\Lib\Loop\LoopMiddleware;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionClass;
use Reli\BaseTestCase;

class SignalCancelMiddlewareTest extends BaseTestCase
{
    public function testPassesThroughUntilCancelled(): void
    {
        $reflection = new ReflectionClass(SignalCancelMiddleware::class);
        $middleware = $reflection->newInstanceWithoutConstructor();
        (function () {
            /** @var SignalCancelMiddleware $this */
            $this->chain = new CallableMiddleware(fn () => true);
            $this->cancelled = false;
        })->bindTo($middleware, $middleware)();

        $this->assertTrue($middleware->invoke());

        (function () {
            /** @var SignalCancelMiddleware $this */
            $this->cancelled = true;
        })->bindTo($middleware, $middleware)();

        $this->assertFalse($middleware->invoke());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReturnsFalseAfterSigint(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix not available');
        }
        $middleware = new SignalCancelMiddleware(
            new CallableMiddleware(fn () => true),
        );
        $this->assertTrue($middleware->invoke());

        posix_kill(posix_getpid(), SIGINT);
        // pcntl_async_signals(true) was enabled in the constructor, so the
        // handler runs at the next safe point - which by the time we get
        // here has already happened.
        $this->assertFalse($middleware->invoke());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReturnsFalseAfterSigterm(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix not available');
        }
        $middleware = new SignalCancelMiddleware(
            new CallableMiddleware(fn () => true),
        );
        $this->assertTrue($middleware->invoke());

        posix_kill(posix_getpid(), SIGTERM);
        $this->assertFalse($middleware->invoke());
    }
}
