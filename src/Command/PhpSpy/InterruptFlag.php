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

/**
 * Tiny mutable holder so signal-handler closures can flip the flag
 * the main loop polls — closures over a bare bool would copy by value.
 */
final class InterruptFlag
{
    public bool $value = false;
}
