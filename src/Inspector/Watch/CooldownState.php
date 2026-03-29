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

/** @internal */
final class CooldownState
{
    public function __construct(
        public float $last_fire_time,
        public int $consecutive_fires,
        public float $current_cooldown,
        /** @var \SplQueue<float> */
        public \SplQueue $hourly_timestamps,
    ) {
    }
}
