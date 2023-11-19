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

use Exception;
use LogicException;
use Reli\BaseTestCase;
use RuntimeException;

class RetryOnExceptionMiddlewareTest extends BaseTestCase
{
    public function testReturnIfChainReturn(): void
    {
        $loop = new RetryOnExceptionMiddleware(0, [Exception::class], new CallableMiddleware(fn () => true));
        $this->assertTrue($loop->invoke());
        $loop = new RetryOnExceptionMiddleware(0, [Exception::class], new CallableMiddleware(fn () => false));
        $this->assertFalse($loop->invoke());
    }

    public function testRetryIfChainThrows(): void
    {
        $counter = 0;
        $loop = new RetryOnExceptionMiddleware(
            1,
            [Exception::class],
            new CallableMiddleware(
                function () use (&$counter) {
                    if ($counter++ === 0) {
                        throw new Exception();
                    };
                    return true;
                }
            )
        );
        $this->assertTrue($loop->invoke());
        $this->assertSame(2, $counter);
    }

    public function testThrowIfRetryCountExceedsMax(): void
    {
        $counter = 0;
        $loop = new RetryOnExceptionMiddleware(
            0,
            [Exception::class],
            new CallableMiddleware(
                function () use (&$counter) {
                    if ($counter++ === 0) {
                        throw new Exception();
                    };
                    return true;
                }
            )
        );
        try {
            $loop->invoke();
        } catch (\Exception $e) {
            $this->assertSame(Exception::class, $e::class);
        }
        $this->assertSame(1, $counter);
    }

    public function testRethrowUnspecifiedException(): void
    {
        $loop = new RetryOnExceptionMiddleware(
            0,
            [RuntimeException::class],
            new CallableMiddleware(
                function () {
                    throw new LogicException();
                }
            )
        );
        $this->expectException(LogicException::class);
        $loop->invoke();
    }
}
