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

    public function testReturnTrueIfChainSucceed(): void
    {
        $nano_sleep_loop = new NanoSleepMiddleware(
            0,
            new CallableMiddleware(fn () => true)
        );
        $this->assertTrue($nano_sleep_loop->invoke());
    }
}
