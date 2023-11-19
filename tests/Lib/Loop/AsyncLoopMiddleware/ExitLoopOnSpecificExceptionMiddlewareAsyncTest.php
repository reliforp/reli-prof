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

namespace Reli\Lib\Loop\AsyncLoopMiddleware;

use Error;
use Exception;
use Reli\BaseTestCase;

class ExitLoopOnSpecificExceptionMiddlewareAsyncTest extends BaseTestCase
{
    public function testReturnIfChainReturn(): void
    {
        $middleware = new ExitLoopOnSpecificExceptionMiddlewareAsync(
            [Exception::class],
            new CallableMiddlewareAsync(fn () => [true])
        );
        $this->assertTrue([...$middleware->invoke()][0]);
        $middleware = new ExitLoopOnSpecificExceptionMiddlewareAsync(
            [Exception::class],
            new CallableMiddlewareAsync(fn () => [false])
        );
        $this->assertFalse([...$middleware->invoke()][0]);
    }

    public function testBailIfChainThrows(): void
    {
        $middleware = new ExitLoopOnSpecificExceptionMiddlewareAsync(
            [Exception::class],
            new CallableMiddlewareAsync(
                function () {
                    throw new Exception();
                }
            )
        );
        $this->assertCount(0, [...$middleware->invoke()]);
    }

    public function testThrowIfChainThrowsOtherException(): void
    {
        $this->expectException(Error::class);
        $middleware = new ExitLoopOnSpecificExceptionMiddlewareAsync(
            [Exception::class],
            new CallableMiddlewareAsync(
                function () {
                    throw new Error();
                }
            )
        );
        [...$middleware->invoke()];
    }
}
