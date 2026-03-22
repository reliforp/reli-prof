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

/**
 * Application-level directory resolver for reli-prof.
 *
 * Wraps XdgDirectory and provides fallback policies:
 * - Config: no fallback (requires home directory)
 * - Cache/State/Log: falls back to a temp directory when home is unavailable
 */
final class AppDirectory
{
    private function __construct()
    {
    }

    /**
     * Config directory. No fallback — configuration requires a stable, user-owned location.
     *
     * @throws HomeNotFoundException
     */
    public static function getConfigDir(): string
    {
        return XdgDirectory::getConfigHome();
    }

    /**
     * Cache directory. Falls back to temp directory when home is unavailable.
     * Suitable for ELF binary analysis cache, etc.
     */
    public static function getCacheDir(): string
    {
        try {
            return XdgDirectory::getCacheHome();
        } catch (HomeNotFoundException) {
            return self::getTempFallbackDir('cache');
        }
    }

    /**
     * State directory (logs, history). Falls back to temp directory.
     */
    public static function getStateDir(): string
    {
        try {
            return XdgDirectory::getStateHome();
        } catch (HomeNotFoundException) {
            return self::getTempFallbackDir('state');
        }
    }

    /**
     * Data directory. Falls back to temp directory.
     */
    public static function getDataDir(): string
    {
        try {
            return XdgDirectory::getDataHome();
        } catch (HomeNotFoundException) {
            return self::getTempFallbackDir('data');
        }
    }

    /**
     * Log file path.
     */
    public static function getLogPath(): string
    {
        return self::getStateDir() . '/reli.log';
    }

    public static function ensureDirectoryExists(string $path): string
    {
        return XdgDirectory::ensureDirectoryExists($path);
    }

    /**
     * Builds a per-user temp fallback directory.
     *
     * Uses UID on POSIX systems, or a fixed name on Windows,
     * to avoid collisions between users sharing /tmp.
     */
    private static function getTempFallbackDir(string $subdirectory): string
    {
        $base = sys_get_temp_dir() . '/' . ReliProfiler::TOOL_NAME;
        if (function_exists('posix_getuid')) {
            $base .= '-' . posix_getuid();
        }
        return $base . '/' . $subdirectory;
    }
}
