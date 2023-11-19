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

use Error;
use Exception;
use Reli\BaseTestCase;

class ExitLoopOnSpecificExceptionMiddlewareTest extends BaseTestCase
{
    public function testReturnIfChainReturn(): void
    {
        $middleware = new ExitLoopOnSpecificExceptionMiddleware(
            [Exception::class],
            new CallableMiddleware(fn () => true)
        );
        $this->assertTrue($middleware->invoke());
        $middleware = new ExitLoopOnSpecificExceptionMiddleware(
            [Exception::class],
            new CallableMiddleware(fn () => false)
        );
        $this->assertFalse($middleware->invoke());
    }

    public function testBailIfChainThrows(): void
    {
        $middleware = new ExitLoopOnSpecificExceptionMiddleware(
            [Exception::class],
            new CallableMiddleware(
                function () {
                    throw new Exception();
                }
            )
        );
        $this->assertFalse($middleware->invoke());
    }

    public function testThrowIfChainThrowsOtherException(): void
    {
        $this->expectException(Error::class);
        $middleware = new ExitLoopOnSpecificExceptionMiddleware(
            [Exception::class],
            new CallableMiddleware(
                function () {
                    throw new Error();
                }
            )
        );
        $middleware->invoke();
    }
}
