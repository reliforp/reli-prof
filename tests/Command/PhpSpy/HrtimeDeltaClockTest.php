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

namespace Reli\Command\PhpSpy;

use Reli\BaseTestCase;

class HrtimeDeltaClockTest extends BaseTestCase
{
    public function testFirstCallReturnsZero(): void
    {
        $clock = new HrtimeDeltaClock();
        $this->assertSame(0, $clock->advance());
    }

    public function testSubsequentCallsReturnPositiveDeltas(): void
    {
        $clock = new HrtimeDeltaClock();
        $clock->advance();
        usleep(2000); // 2ms
        $delta = $clock->advance();
        $this->assertGreaterThan(0, $delta);
        // Sanity bound: should not jump multiple seconds for a 2ms sleep.
        $this->assertLessThan(1_000_000, $delta);
    }

    public function testIndependentClocksDoNotShareState(): void
    {
        $a = new HrtimeDeltaClock();
        $b = new HrtimeDeltaClock();
        $a->advance();
        usleep(1000);
        // b's first call should still return 0, regardless of a's state.
        $this->assertSame(0, $b->advance());
    }
}
