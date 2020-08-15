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

namespace PhpProfiler\Lib\Loop\LoopMiddleware;

use LogicException;
use PHPUnit\Framework\TestCase;

class NanoSleepLoopTest extends TestCase
{
    public function testReturnFalseIfChainFailed(): void
    {
        time();
        $nano_sleep_loop = new NanoSleepMiddleware(
            0,
            new CallableMiddleware(fn () => false)
        );
        $this->assertFalse($nano_sleep_loop->invoke());
    }

    public function testSleepBeforeChainInvoked(): void
    {
        $nano_sleep_loop = new NanoSleepMiddleware(
            1000 * 1000 * 1000,
            new CallableMiddleware(function () {
                throw new LogicException('should not be thrown');
            })
        );
        $this->expectWarning();
        $this->expectWarningMessageMatches('/nanoseconds was not in the range 0 to 999 999 999/');
        $nano_sleep_loop->invoke();
    }

    public function testReturnTrueIfChainSucceed(): void
    {
        $nano_sleep_loop = new NanoSleepMiddleware(
            0,
            new CallableMiddleware(fn () => true)
        );
        $this->assertTrue($nano_sleep_loop->invoke());
    }
}
