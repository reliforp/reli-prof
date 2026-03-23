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

    /** @throws HomeNotFoundException */
    public static function getConfigHome(): string
    {
        $xdgConfigHome = self::getAbsoluteEnv('XDG_CONFIG_HOME');
        if ($xdgConfigHome !== null) {
            return $xdgConfigHome . '/' . ReliProfiler::TOOL_NAME;
        }
        return self::getHome() . '/.config/' . ReliProfiler::TOOL_NAME;
    }

    /** @throws HomeNotFoundException */
    public static function getCacheHome(): string
    {
        $xdgCacheHome = self::getAbsoluteEnv('XDG_CACHE_HOME');
        if ($xdgCacheHome !== null) {
            return $xdgCacheHome . '/' . ReliProfiler::TOOL_NAME;
        }
        return self::getHome() . '/.cache/' . ReliProfiler::TOOL_NAME;
    }

    /** @throws HomeNotFoundException */
    public static function getDataHome(): string
    {
        $xdgDataHome = self::getAbsoluteEnv('XDG_DATA_HOME');
        if ($xdgDataHome !== null) {
            return $xdgDataHome . '/' . ReliProfiler::TOOL_NAME;
        }
        return self::getHome() . '/.local/share/' . ReliProfiler::TOOL_NAME;
    }

    /** @throws HomeNotFoundException */
    public static function getStateHome(): string
    {
        $xdgStateHome = self::getAbsoluteEnv('XDG_STATE_HOME');
        if ($xdgStateHome !== null) {
            return $xdgStateHome . '/' . ReliProfiler::TOOL_NAME;
        }
        return self::getHome() . '/.local/state/' . ReliProfiler::TOOL_NAME;
    }

    public static function getRuntimeDir(): ?string
    {
        $xdgRuntimeDir = self::getAbsoluteEnv('XDG_RUNTIME_DIR');
        if ($xdgRuntimeDir !== null) {
            return $xdgRuntimeDir . '/' . ReliProfiler::TOOL_NAME;
        }
        return null;
    }

    /**
     * @return list<string>
     * @throws HomeNotFoundException
     */
    public static function getConfigDirs(): array
    {
        $dirs = [self::getConfigHome()];
        $xdgConfigDirs = getenv('XDG_CONFIG_DIRS');
        if ($xdgConfigDirs === false || $xdgConfigDirs === '') {
            $xdgConfigDirs = '/etc/xdg';
        }
        foreach (explode(':', $xdgConfigDirs) as $dir) {
            if (self::isAbsolutePath($dir)) {
                $dirs[] = $dir . '/' . ReliProfiler::TOOL_NAME;
            }
        }
        return $dirs;
    }

    /**
     * @return list<string>
     * @throws HomeNotFoundException
     */
    public static function getDataDirs(): array
    {
        $dirs = [self::getDataHome()];
        $xdgDataDirs = getenv('XDG_DATA_DIRS');
        if ($xdgDataDirs === false || $xdgDataDirs === '') {
            $xdgDataDirs = '/usr/local/share:/usr/share';
        }
        foreach (explode(':', $xdgDataDirs) as $dir) {
            if (self::isAbsolutePath($dir)) {
                $dirs[] = $dir . '/' . ReliProfiler::TOOL_NAME;
            }
        }
        return $dirs;
    }

    public static function ensureDirectoryExists(string $path): string
    {
        if (!is_dir($path)) {
            if (!mkdir($path, 0700, true) && !is_dir($path)) {
                throw new \RuntimeException(
                    sprintf('Failed to create directory: %s', $path)
                );
            }
        }
        return $path;
    }

    /**
     * Resolves the home directory following XDG conventions.
     *
     * 1. HOME environment variable (Unix) / USERPROFILE / HOMEDRIVE+HOMEPATH (Windows)
     * 2. POSIX passwd database lookup (Unix/Mac)
     * 3. HomeNotFoundException
     *
     * @throws HomeNotFoundException
     */
    private static function getHome(): string
    {
        $home = getenv('HOME');
        if ($home !== false && $home !== '') {
            return $home;
        }

        // Windows fallbacks
        if (DIRECTORY_SEPARATOR === '\\') {
            $profile = getenv('USERPROFILE');
            if ($profile !== false && $profile !== '') {
                return $profile;
            }
            $drive = getenv('HOMEDRIVE');
            $homePath = getenv('HOMEPATH');
            if ($drive !== false && $homePath !== false && $homePath !== '') {
                return $drive . $homePath;
            }
        }

        // POSIX passwd database fallback
        if (function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
            $info = posix_getpwuid(posix_getuid());
            if ($info !== false && ($info['dir'] ?? '') !== '') {
                return $info['dir'];
            }
        }

        throw new HomeNotFoundException();
    }

    /**
     * Returns the value of an environment variable only if it is set, non-empty, and an absolute path.
     * Per the XDG Base Directory Specification, relative paths must be ignored.
     */
    private static function getAbsoluteEnv(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return null;
        }
        if (!self::isAbsolutePath($value)) {
            return null;
        }
        return $value;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/');
    }
}
