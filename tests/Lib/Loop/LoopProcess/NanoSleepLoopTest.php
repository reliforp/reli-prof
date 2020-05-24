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

use PHPUnit\Framework\TestCase;

class NanoSleepLoopTest extends TestCase
{
    public function testReturnFalseWithoutSleepIfChainFailed(): void
    {
        $time = time();
        $nano_sleep_loop = new NanoSleepLoop(
            1000 * 1000 * 1000,
            new CallableLoop(fn () => false)
        );
        $this->assertSame(false, $nano_sleep_loop->invoke());
    }

    public function testSleepIfChainSucceed(): void
    {
        $nano_sleep_loop = new NanoSleepLoop(
            1000 * 1000 * 1000,
            new CallableLoop(fn () => true)
        );
        $this->expectWarning();
        $this->expectWarningMessageMatches('/nanoseconds was not in the range 0 to 999 999 999/');
        $nano_sleep_loop->invoke();
    }

    public function testReturnTrueIfChainSucceed(): void
    {
        $nano_sleep_loop = new NanoSleepLoop(
            0,
            new CallableLoop(fn () => true)
        );
        $this->assertSame(true, $nano_sleep_loop->invoke());
    }
}
