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

namespace Reli\Lib\Session;

final class SessionTransition
{
    /**
     * @param class-string $messageClass
     */
    public function __construct(
        public readonly Direction $direction,
        public readonly string $messageClass,
        public readonly string $nextState,
    ) {
    }
}
