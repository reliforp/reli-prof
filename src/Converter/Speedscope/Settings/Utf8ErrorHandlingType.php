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

namespace Reli\Converter\Speedscope\Settings;

enum Utf8ErrorHandlingType
{
    case Ignore;
    case Substitute;
    case Fail;

    public function toFlag(): int
    {
        return match ($this) {
            self::Ignore => \JSON_INVALID_UTF8_IGNORE,
            self::Substitute => \JSON_INVALID_UTF8_SUBSTITUTE,
            self::Fail => 0,
        };
    }
}
