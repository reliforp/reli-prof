<?php

/**
 * This file is part of the sj-i/ package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\DateTime;

use PHPUnit\Framework\TestCase;

class FixedClockTest extends TestCase
{
    public function testNow()
    {
        $clock = new FixedClock(new \DateTimeImmutable());
        $start = $clock->now();
        $diff = $clock->now()->diff($start);
        $this->assertSame(0, (int)$diff->format('%f'));
        $clock->update(new \DateTimeImmutable());
        $diff = $clock->now()->diff($start);
        $this->assertgreaterThan(0, (int)$diff->format('%f'));
    }
}
