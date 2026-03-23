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

class AppDirectoryTest extends BaseTestCase
{
    private string|false $originalHome;
    private string|false $originalXdgConfigHome;
    private string|false $originalXdgCacheHome;
    private string|false $originalXdgDataHome;
    private string|false $originalXdgStateHome;

    public function setUp(): void
    {
        $this->originalHome = getenv('HOME');
        $this->originalXdgConfigHome = getenv('XDG_CONFIG_HOME');
        $this->originalXdgCacheHome = getenv('XDG_CACHE_HOME');
        $this->originalXdgDataHome = getenv('XDG_DATA_HOME');
        $this->originalXdgStateHome = getenv('XDG_STATE_HOME');
    }

    public function tearDown(): void
    {
        $this->restoreEnv('HOME', $this->originalHome);
        $this->restoreEnv('XDG_CONFIG_HOME', $this->originalXdgConfigHome);
        $this->restoreEnv('XDG_CACHE_HOME', $this->originalXdgCacheHome);
        $this->restoreEnv('XDG_DATA_HOME', $this->originalXdgDataHome);
        $this->restoreEnv('XDG_STATE_HOME', $this->originalXdgStateHome);
    }

    private function restoreEnv(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);
        } else {
            putenv("{$name}={$value}");
        }
    }

    // --- Config: delegates to XDG, no fallback ---

    public function testGetConfigDirWithHome(): void
    {
        putenv('XDG_CONFIG_HOME');
        putenv('HOME=/tmp/app-test-home');
        $this->assertSame('/tmp/app-test-home/.config/reli', AppDirectory::getConfigDir());
    }

    public function testGetConfigDirWithXdgEnv(): void
    {
        putenv('XDG_CONFIG_HOME=/tmp/app-xdg-config');
        $this->assertSame('/tmp/app-xdg-config/reli', AppDirectory::getConfigDir());
    }

    // --- Cache: XDG when available, temp fallback otherwise ---

    public function testGetCacheDirWithHome(): void
    {
        putenv('XDG_CACHE_HOME');
        putenv('HOME=/tmp/app-test-home');
        $this->assertSame('/tmp/app-test-home/.cache/reli', AppDirectory::getCacheDir());
    }

    public function testGetCacheDirWithXdgEnv(): void
    {
        putenv('XDG_CACHE_HOME=/tmp/app-xdg-cache');
        $this->assertSame('/tmp/app-xdg-cache/reli', AppDirectory::getCacheDir());
    }

    public function testGetCacheDirWithoutHomeReturnsAbsolutePath(): void
    {
        putenv('HOME');
        putenv('XDG_CACHE_HOME');
        $result = AppDirectory::getCacheDir();
        // passwd fallback or temp fallback — either way, must be absolute and contain 'reli'
        $this->assertStringStartsWith('/', $result);
        $this->assertStringContainsString('reli', $result);
    }

    // --- State: XDG when available, temp fallback otherwise ---

    public function testGetStateDirWithHome(): void
    {
        putenv('XDG_STATE_HOME');
        putenv('HOME=/tmp/app-test-home');
        $this->assertSame('/tmp/app-test-home/.local/state/reli', AppDirectory::getStateDir());
    }

    public function testGetStateDirWithoutHomeReturnsAbsolutePath(): void
    {
        putenv('HOME');
        putenv('XDG_STATE_HOME');
        $result = AppDirectory::getStateDir();
        $this->assertStringStartsWith('/', $result);
        $this->assertStringContainsString('reli', $result);
    }

    // --- Data: XDG when available, temp fallback otherwise ---

    public function testGetDataDirWithHome(): void
    {
        putenv('XDG_DATA_HOME');
        putenv('HOME=/tmp/app-test-home');
        $this->assertSame('/tmp/app-test-home/.local/share/reli', AppDirectory::getDataDir());
    }

    public function testGetDataDirWithoutHomeReturnsAbsolutePath(): void
    {
        putenv('HOME');
        putenv('XDG_DATA_HOME');
        $result = AppDirectory::getDataDir();
        $this->assertStringStartsWith('/', $result);
        $this->assertStringContainsString('reli', $result);
    }

    // --- Log path ---

    public function testGetLogPathWithHome(): void
    {
        putenv('XDG_STATE_HOME');
        putenv('HOME=/tmp/app-test-home');
        $this->assertSame('/tmp/app-test-home/.local/state/reli/reli.log', AppDirectory::getLogPath());
    }

    public function testGetLogPathWithoutHomeReturnsAbsolutePath(): void
    {
        putenv('HOME');
        putenv('XDG_STATE_HOME');
        $result = AppDirectory::getLogPath();
        $this->assertStringStartsWith('/', $result);
        $this->assertStringEndsWith('/reli.log', $result);
    }

    // --- ensureDirectoryExists delegates ---

    public function testEnsureDirectoryExists(): void
    {
        $testDir = sys_get_temp_dir() . '/app-dir-test-' . uniqid();
        $this->assertDirectoryDoesNotExist($testDir);

        $result = AppDirectory::ensureDirectoryExists($testDir);

        $this->assertSame($testDir, $result);
        $this->assertDirectoryExists($testDir);

        rmdir($testDir);
    }

    // --- loadConfig ---

    public function testLoadConfigReturnsDefaultWhenNoUserConfig(): void
    {
        $defaultConfig = sys_get_temp_dir() . '/reli-test-config-' . uniqid();
        mkdir($defaultConfig, 0700, true);
        $defaultConfigFile = $defaultConfig . '/config.php';
        file_put_contents($defaultConfigFile, '<?php return ["log" => ["level" => "info"]];');

        // Point XDG config to a directory with no config.php
        $emptyConfigDir = sys_get_temp_dir() . '/reli-test-xdg-empty-' . uniqid() . '/reli';
        mkdir($emptyConfigDir, 0700, true);
        putenv('XDG_CONFIG_HOME=' . dirname($emptyConfigDir));

        $config = AppDirectory::loadConfig($defaultConfigFile);

        $this->assertSame('info', $config->get('log.level'));

        unlink($defaultConfigFile);
        rmdir($defaultConfig);
        rmdir($emptyConfigDir);
        rmdir(dirname($emptyConfigDir));
    }

    public function testLoadConfigMergesUserConfig(): void
    {
        $defaultConfig = sys_get_temp_dir() . '/reli-test-config-' . uniqid();
        mkdir($defaultConfig, 0700, true);
        $defaultConfigFile = $defaultConfig . '/config.php';
        file_put_contents(
            $defaultConfigFile,
            '<?php return ["log" => ["level" => "info"], "output" => ["template" => ["default" => "phpspy"]]];'
        );

        // Create user config that overrides log level
        $userConfigBase = sys_get_temp_dir() . '/reli-test-xdg-user-' . uniqid();
        $userConfigDir = $userConfigBase . '/reli';
        mkdir($userConfigDir, 0700, true);
        file_put_contents(
            $userConfigDir . '/config.php',
            '<?php return ["log" => ["level" => "debug"]];'
        );
        putenv('XDG_CONFIG_HOME=' . $userConfigBase);

        $config = AppDirectory::loadConfig($defaultConfigFile);

        // User override takes effect
        $this->assertSame('debug', $config->get('log.level'));
        // Default value preserved for non-overridden keys
        $this->assertSame('phpspy', $config->get('output.template.default'));

        unlink($defaultConfigFile);
        rmdir($defaultConfig);
        unlink($userConfigDir . '/config.php');
        rmdir($userConfigDir);
        rmdir($userConfigBase);
    }

    public function testLoadConfigWorksWithoutHome(): void
    {
        $defaultConfig = sys_get_temp_dir() . '/reli-test-config-' . uniqid();
        mkdir($defaultConfig, 0700, true);
        $defaultConfigFile = $defaultConfig . '/config.php';
        file_put_contents($defaultConfigFile, '<?php return ["log" => ["level" => "warning"]];');

        putenv('HOME');
        putenv('XDG_CONFIG_HOME');

        $config = AppDirectory::loadConfig($defaultConfigFile);

        // Should fall back to defaults without throwing
        $this->assertSame('warning', $config->get('log.level'));

        unlink($defaultConfigFile);
        rmdir($defaultConfig);
    }
}
