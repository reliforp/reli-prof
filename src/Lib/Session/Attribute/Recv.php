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

namespace Reli\Lib\Session\Attribute;

use Attribute;
use UnitEnum;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT | Attribute::IS_REPEATABLE)]
final class Recv
{
    /**
     * @param class-string $messageClass
     * @param class-string<UnitEnum>|string $next enum case name or class-string
     */
    public function __construct(
        public readonly string $messageClass,
        public readonly string $next,
    ) {
    }
}
