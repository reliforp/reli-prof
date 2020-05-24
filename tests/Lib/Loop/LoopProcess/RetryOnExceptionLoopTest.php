<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\Loop\LoopProcess;

use Exception;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RetryOnExceptionLoopTest extends TestCase
{
    public function testReturnIfChainReturn(): void
    {
        $loop = new RetryOnExceptionLoop(0, [Exception::class], new CallableLoop(fn () => true));
        $this->assertSame(true, $loop->invoke());
        $loop = new RetryOnExceptionLoop(0, [Exception::class], new CallableLoop(fn () => false));
        $this->assertSame(false, $loop->invoke());
    }

    public function testRetryIfChainThrows(): void
    {
        $counter = 0;
        $loop = new RetryOnExceptionLoop(
            1,
            [Exception::class],
            new CallableLoop(
                function () use (&$counter) {
                    if ($counter++ === 0) {
                        throw new Exception();
                    };
                    return true;
                }
            )
        );
        $this->assertSame(true, $loop->invoke());
        $this->assertSame(2, $counter);
    }

    public function testReturnFalseIfRetryCountExceedsMax(): void
    {
        $counter = 0;
        $loop = new RetryOnExceptionLoop(
            0,
            [Exception::class],
            new CallableLoop(
                function () use (&$counter) {
                    if ($counter++ === 0) {
                        throw new Exception();
                    };
                    return true;
                }
            )
        );
        $this->assertSame(false, $loop->invoke());
        $this->assertSame(1, $counter);
    }

    public function testRethrowUnspecifiedException(): void
    {
        $loop = new RetryOnExceptionLoop(
            0,
            [RuntimeException::class],
            new CallableLoop(
                function () {
                    throw new LogicException();
                }
            )
        );
        $this->expectException(LogicException::class);
        $loop->invoke();
    }
}
