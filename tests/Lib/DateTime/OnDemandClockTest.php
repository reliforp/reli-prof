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

namespace Reli\Lib\DateTime;

use Reli\BaseTestCase;

class OnDemandClockTest extends BaseTestCase
{
    public function testNow()
    {
        $clock = new OnDemandClock();
        $now = $clock->now();
        time_nanosleep(0, 1);
        $diff = $clock->now()->diff($now);
        $this->assertGreaterThan(0, (int)$diff->format('%f'));
    }
}
