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

namespace Reli\Lib\DateTime;

use PHPUnit\Framework\TestCase;

class OnDemandClockTest extends TestCase
{
    public function testNow()
    {
        $clock = new OnDemandClock();
        $now = $clock->now();
        $diff = $clock->now()->diff($now);
        $this->assertGreaterThan(0, (int)$diff->format('%f'));
    }
}
