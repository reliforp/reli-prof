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

namespace Reli\Lib\System;

enum Architecture: string
{
    case X86_64 = 'x86_64';
    case AARCH64 = 'aarch64';

    public static function detect(): self
    {
        $machine = php_uname('m');
        return self::from($machine);
    }
}
