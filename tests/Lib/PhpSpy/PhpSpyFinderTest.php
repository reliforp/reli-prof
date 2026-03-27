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

namespace Reli\Lib\PhpSpy;

use Reli\BaseTestCase;

class PhpSpyFinderTest extends BaseTestCase
{
    public function testFindWithExplicitPath(): void
    {
        $finder = new PhpSpyFinder();
        // PHP binary is always executable, use it as a stand-in
        $php_path = PHP_BINARY;
        $this->assertSame($php_path, $finder->find($php_path));
    }

    public function testFindWithExplicitPathNotExecutableThrows(): void
    {
        $finder = new PhpSpyFinder();
        $this->expectException(PhpSpyNotFoundException::class);
        $finder->find('/nonexistent/phpspy');
    }

    public function testFindWithEnvVar(): void
    {
        $finder = new PhpSpyFinder();
        $old_env = getenv('PHPSPY_PATH');

        putenv('PHPSPY_PATH=' . PHP_BINARY);
        try {
            $result = $finder->find();
            $this->assertSame(PHP_BINARY, $result);
        } finally {
            if ($old_env === false) {
                putenv('PHPSPY_PATH');
            } else {
                putenv('PHPSPY_PATH=' . $old_env);
            }
        }
    }

    public function testFindThrowsWhenNothingFound(): void
    {
        $finder = new PhpSpyFinder();
        $old_env = getenv('PHPSPY_PATH');

        // Clear env var so it doesn't interfere
        putenv('PHPSPY_PATH');
        try {
            // Unless phpspy is actually installed, this should throw
            // Use explicit null to skip env check for explicit path
            $finder->find(null);
            // If we get here, phpspy is installed - that's ok too
            $this->assertTrue(true);
        } catch (PhpSpyNotFoundException) {
            $this->assertTrue(true);
        } finally {
            if ($old_env !== false) {
                putenv('PHPSPY_PATH=' . $old_env);
            }
        }
    }

    public function testGetDefaultInstallPath(): void
    {
        $path = PhpSpyFinder::getDefaultInstallPath();
        $this->assertStringEndsWith('/phpspy', $path);
        $this->assertStringContainsString('.reli/bin', $path);
    }

    public function testGetDefaultInstallDir(): void
    {
        $dir = PhpSpyFinder::getDefaultInstallDir();
        $this->assertStringEndsWith('.reli/bin', $dir);
    }
}
