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

use Composer\InstalledVersions;

final class ReliProfiler
{
    public const TOOL_NAME = 'reli';

    public static function getVersion(): string
    {
        return InstalledVersions::getPrettyVersion('reliforp/reli-prof') ?? 'unknown';
    }

    public static function toolSignature(): string
    {
        return self::TOOL_NAME . ' ' . self::getVersion();
    }
}
