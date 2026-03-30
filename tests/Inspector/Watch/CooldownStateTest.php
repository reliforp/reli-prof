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

namespace Reli\Inspector\Watch;

use PHPUnit\Framework\TestCase;

class CooldownStateTest extends TestCase
{
    public function testConstruction(): void
    {
        /** @var \SplQueue<float> $q */
        $q = new \SplQueue();
        $state = new CooldownState(
            last_fire_time: 100.0,
            consecutive_fires: 3,
            current_cooldown: 60.0,
            hourly_timestamps: $q,
        );

        $this->assertSame(100.0, $state->last_fire_time);
        $this->assertSame(3, $state->consecutive_fires);
        $this->assertSame(60.0, $state->current_cooldown);
        $this->assertSame($q, $state->hourly_timestamps);
    }

    public function testMutable(): void
    {
        /** @var \SplQueue<float> $q */
        $q = new \SplQueue();
        $state = new CooldownState(
            last_fire_time: 0.0,
            consecutive_fires: 0,
            current_cooldown: 10.0,
            hourly_timestamps: $q,
        );

        $state->last_fire_time = 50.0;
        $state->consecutive_fires = 5;
        $state->current_cooldown = 120.0;

        $this->assertSame(50.0, $state->last_fire_time);
        $this->assertSame(5, $state->consecutive_fires);
        $this->assertSame(120.0, $state->current_cooldown);
    }
}
