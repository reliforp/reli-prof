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

namespace Reli;

final class ReliProfiler
{
    public const TOOL_NAME = 'reli';
    public const VERSION = '0.10.0';

    public static function toolSignature(): string
    {
        return self::TOOL_NAME . ' ' . self::VERSION;
    }
}
