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

namespace Reli\Lib\Directory;

use Reli\ReliProfiler;

final class XdgDirectory
{
    private function __construct()
    {
    }

    public static function getConfigHome(): string
    {
        $xdgConfigHome = getenv('XDG_CONFIG_HOME');
        if ($xdgConfigHome !== false && $xdgConfigHome !== '') {
            return $xdgConfigHome . '/' . ReliProfiler::TOOL_NAME;
        }
        return self::getHome() . '/.config/' . ReliProfiler::TOOL_NAME;
    }

    public static function getCacheHome(): string
    {
        $xdgCacheHome = getenv('XDG_CACHE_HOME');
        if ($xdgCacheHome !== false && $xdgCacheHome !== '') {
            return $xdgCacheHome . '/' . ReliProfiler::TOOL_NAME;
        }
        return self::getHome() . '/.cache/' . ReliProfiler::TOOL_NAME;
    }

    public static function getDataHome(): string
    {
        $xdgDataHome = getenv('XDG_DATA_HOME');
        if ($xdgDataHome !== false && $xdgDataHome !== '') {
            return $xdgDataHome . '/' . ReliProfiler::TOOL_NAME;
        }
        return self::getHome() . '/.local/share/' . ReliProfiler::TOOL_NAME;
    }

    public static function getStateHome(): string
    {
        $xdgStateHome = getenv('XDG_STATE_HOME');
        if ($xdgStateHome !== false && $xdgStateHome !== '') {
            return $xdgStateHome . '/' . ReliProfiler::TOOL_NAME;
        }
        return self::getHome() . '/.local/state/' . ReliProfiler::TOOL_NAME;
    }

    public static function ensureDirectoryExists(string $path): string
    {
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }
        return $path;
    }

    private static function getHome(): string
    {
        $home = getenv('HOME');
        if ($home !== false && $home !== '') {
            return $home;
        }
        throw new \RuntimeException('HOME environment variable is not set');
    }
}
