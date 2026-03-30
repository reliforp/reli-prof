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

final class TriggerEvent
{
    public function __construct(
        public readonly string $trigger_name,
        public readonly string $description,
        public readonly float $timestamp,
        public readonly ?float $value = null,
    ) {
    }
}
