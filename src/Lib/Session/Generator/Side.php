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

namespace Reli\Lib\Session\Generator;

enum Side
{
    case Worker;
    case Controller;

    public function label(): string
    {
        return match ($this) {
            self::Worker => 'Worker',
            self::Controller => 'Controller',
        };
    }
}
