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
