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

use Reli\BaseTestCase;

class XdgDirectoryTest extends BaseTestCase
{
    private string|false $originalHome;
    private string|false $originalXdgConfigHome;
    private string|false $originalXdgCacheHome;
    private string|false $originalXdgDataHome;
    private string|false $originalXdgStateHome;
    private string|false $originalXdgRuntimeDir;
    private string|false $originalXdgConfigDirs;
    private string|false $originalXdgDataDirs;

    public function setUp(): void
    {
        $this->originalHome = getenv('HOME');
        $this->originalXdgConfigHome = getenv('XDG_CONFIG_HOME');
        $this->originalXdgCacheHome = getenv('XDG_CACHE_HOME');
        $this->originalXdgDataHome = getenv('XDG_DATA_HOME');
        $this->originalXdgStateHome = getenv('XDG_STATE_HOME');
        $this->originalXdgRuntimeDir = getenv('XDG_RUNTIME_DIR');
        $this->originalXdgConfigDirs = getenv('XDG_CONFIG_DIRS');
        $this->originalXdgDataDirs = getenv('XDG_DATA_DIRS');
    }

    public function tearDown(): void
    {
        $this->restoreEnv('HOME', $this->originalHome);
        $this->restoreEnv('XDG_CONFIG_HOME', $this->originalXdgConfigHome);
        $this->restoreEnv('XDG_CACHE_HOME', $this->originalXdgCacheHome);
        $this->restoreEnv('XDG_DATA_HOME', $this->originalXdgDataHome);
        $this->restoreEnv('XDG_STATE_HOME', $this->originalXdgStateHome);
        $this->restoreEnv('XDG_RUNTIME_DIR', $this->originalXdgRuntimeDir);
        $this->restoreEnv('XDG_CONFIG_DIRS', $this->originalXdgConfigDirs);
        $this->restoreEnv('XDG_DATA_DIRS', $this->originalXdgDataDirs);
    }

    private function restoreEnv(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);
        } else {
            putenv("{$name}={$value}");
        }
    }

    public function testGetConfigHomeWithXdgEnv(): void
    {
        putenv('XDG_CONFIG_HOME=/tmp/xdg-test-config');
        $this->assertSame('/tmp/xdg-test-config/reli', XdgDirectory::getConfigHome());
    }

    public function testGetConfigHomeWithoutXdgEnv(): void
    {
        putenv('XDG_CONFIG_HOME');
        putenv('HOME=/tmp/xdg-test-home');
        $this->assertSame('/tmp/xdg-test-home/.config/reli', XdgDirectory::getConfigHome());
    }

    public function testGetCacheHomeWithXdgEnv(): void
    {
        putenv('XDG_CACHE_HOME=/tmp/xdg-test-cache');
        $this->assertSame('/tmp/xdg-test-cache/reli', XdgDirectory::getCacheHome());
    }

    public function testGetCacheHomeWithoutXdgEnv(): void
    {
        putenv('XDG_CACHE_HOME');
        putenv('HOME=/tmp/xdg-test-home');
        $this->assertSame('/tmp/xdg-test-home/.cache/reli', XdgDirectory::getCacheHome());
    }

    public function testGetDataHomeWithXdgEnv(): void
    {
        putenv('XDG_DATA_HOME=/tmp/xdg-test-data');
        $this->assertSame('/tmp/xdg-test-data/reli', XdgDirectory::getDataHome());
    }

    public function testGetDataHomeWithoutXdgEnv(): void
    {
        putenv('XDG_DATA_HOME');
        putenv('HOME=/tmp/xdg-test-home');
        $this->assertSame('/tmp/xdg-test-home/.local/share/reli', XdgDirectory::getDataHome());
    }

    public function testGetStateHomeWithXdgEnv(): void
    {
        putenv('XDG_STATE_HOME=/tmp/xdg-test-state');
        $this->assertSame('/tmp/xdg-test-state/reli', XdgDirectory::getStateHome());
    }

    public function testGetStateHomeWithoutXdgEnv(): void
    {
        putenv('XDG_STATE_HOME');
        putenv('HOME=/tmp/xdg-test-home');
        $this->assertSame('/tmp/xdg-test-home/.local/state/reli', XdgDirectory::getStateHome());
    }

    public function testGetHomeThrowsWhenNotSet(): void
    {
        putenv('HOME');
        putenv('XDG_CONFIG_HOME');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HOME environment variable is not set');
        XdgDirectory::getConfigHome();
    }

    public function testEnsureDirectoryExists(): void
    {
        $testDir = sys_get_temp_dir() . '/xdg-test-' . uniqid();
        $this->assertDirectoryDoesNotExist($testDir);

        $result = XdgDirectory::ensureDirectoryExists($testDir);

        $this->assertSame($testDir, $result);
        $this->assertDirectoryExists($testDir);
        $this->assertSame(0700, fileperms($testDir) & 0777);

        rmdir($testDir);
    }

    // --- Relative path rejection tests ---

    public function testGetConfigHomeIgnoresRelativePath(): void
    {
        putenv('XDG_CONFIG_HOME=relative/path');
        putenv('HOME=/tmp/xdg-test-home');
        $this->assertSame('/tmp/xdg-test-home/.config/reli', XdgDirectory::getConfigHome());
    }

    public function testGetCacheHomeIgnoresRelativePath(): void
    {
        putenv('XDG_CACHE_HOME=relative/path');
        putenv('HOME=/tmp/xdg-test-home');
        $this->assertSame('/tmp/xdg-test-home/.cache/reli', XdgDirectory::getCacheHome());
    }

    public function testGetDataHomeIgnoresRelativePath(): void
    {
        putenv('XDG_DATA_HOME=relative/path');
        putenv('HOME=/tmp/xdg-test-home');
        $this->assertSame('/tmp/xdg-test-home/.local/share/reli', XdgDirectory::getDataHome());
    }

    public function testGetStateHomeIgnoresRelativePath(): void
    {
        putenv('XDG_STATE_HOME=relative/path');
        putenv('HOME=/tmp/xdg-test-home');
        $this->assertSame('/tmp/xdg-test-home/.local/state/reli', XdgDirectory::getStateHome());
    }

    // --- XDG_RUNTIME_DIR tests ---

    public function testGetRuntimeDirWithXdgEnv(): void
    {
        putenv('XDG_RUNTIME_DIR=/run/user/1000');
        $this->assertSame('/run/user/1000/reli', XdgDirectory::getRuntimeDir());
    }

    public function testGetRuntimeDirWithoutXdgEnv(): void
    {
        putenv('XDG_RUNTIME_DIR');
        $this->assertNull(XdgDirectory::getRuntimeDir());
    }

    public function testGetRuntimeDirIgnoresRelativePath(): void
    {
        putenv('XDG_RUNTIME_DIR=relative/runtime');
        $this->assertNull(XdgDirectory::getRuntimeDir());
    }

    // --- XDG_CONFIG_DIRS tests ---

    public function testGetConfigDirsWithXdgEnv(): void
    {
        putenv('XDG_CONFIG_HOME=/tmp/xdg-config');
        putenv('XDG_CONFIG_DIRS=/etc/xdg:/opt/config');
        $this->assertSame(
            ['/tmp/xdg-config/reli', '/etc/xdg/reli', '/opt/config/reli'],
            XdgDirectory::getConfigDirs()
        );
    }

    public function testGetConfigDirsDefault(): void
    {
        putenv('XDG_CONFIG_HOME=/tmp/xdg-config');
        putenv('XDG_CONFIG_DIRS');
        $this->assertSame(
            ['/tmp/xdg-config/reli', '/etc/xdg/reli'],
            XdgDirectory::getConfigDirs()
        );
    }

    public function testGetConfigDirsIgnoresRelativePaths(): void
    {
        putenv('XDG_CONFIG_HOME=/tmp/xdg-config');
        putenv('XDG_CONFIG_DIRS=/etc/xdg:relative/path:/opt/config');
        $this->assertSame(
            ['/tmp/xdg-config/reli', '/etc/xdg/reli', '/opt/config/reli'],
            XdgDirectory::getConfigDirs()
        );
    }

    // --- XDG_DATA_DIRS tests ---

    public function testGetDataDirsWithXdgEnv(): void
    {
        putenv('XDG_DATA_HOME=/tmp/xdg-data');
        putenv('XDG_DATA_DIRS=/usr/local/share:/usr/share');
        $this->assertSame(
            ['/tmp/xdg-data/reli', '/usr/local/share/reli', '/usr/share/reli'],
            XdgDirectory::getDataDirs()
        );
    }

    public function testGetDataDirsDefault(): void
    {
        putenv('XDG_DATA_HOME=/tmp/xdg-data');
        putenv('XDG_DATA_DIRS');
        $this->assertSame(
            ['/tmp/xdg-data/reli', '/usr/local/share/reli', '/usr/share/reli'],
            XdgDirectory::getDataDirs()
        );
    }

    public function testGetDataDirsIgnoresRelativePaths(): void
    {
        putenv('XDG_DATA_HOME=/tmp/xdg-data');
        putenv('XDG_DATA_DIRS=/usr/share:not-absolute:/opt/data');
        $this->assertSame(
            ['/tmp/xdg-data/reli', '/usr/share/reli', '/opt/data/reli'],
            XdgDirectory::getDataDirs()
        );
    }
}
