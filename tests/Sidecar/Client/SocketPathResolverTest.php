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

namespace Reli\Sidecar\Client;

use PHPUnit\Framework\TestCase;
use RuntimeException;

class SocketPathResolverTest extends TestCase
{
    private ?string $xdgBackup;
    private string $scratch;

    protected function setUp(): void
    {
        $xdg = getenv('XDG_RUNTIME_DIR');
        $this->xdgBackup = is_string($xdg) ? $xdg : null;
        $this->scratch = sys_get_temp_dir() . '/reli-sockres-' . getmypid() . '-' . mt_rand();
        mkdir($this->scratch, 0o700, true);
    }

    protected function tearDown(): void
    {
        if ($this->xdgBackup !== null) {
            putenv('XDG_RUNTIME_DIR=' . $this->xdgBackup);
        } else {
            putenv('XDG_RUNTIME_DIR');
        }
        $this->removeTree($this->scratch);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) && !is_link($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testResolveDefaultUsesXdgRuntimeDirWhenSet(): void
    {
        putenv('XDG_RUNTIME_DIR=' . $this->scratch);
        $this->assertSame(
            $this->scratch . '/reli/sidecar.sock',
            SocketPathResolver::resolveDefault(),
        );
    }

    public function testResolveDefaultStripsTrailingSlash(): void
    {
        putenv('XDG_RUNTIME_DIR=' . $this->scratch . '/');
        $this->assertSame(
            $this->scratch . '/reli/sidecar.sock',
            SocketPathResolver::resolveDefault(),
        );
    }

    public function testResolveDefaultThrowsWhenXdgUnset(): void
    {
        putenv('XDG_RUNTIME_DIR');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/XDG_RUNTIME_DIR/');
        SocketPathResolver::resolveDefault();
    }

    public function testResolveDefaultThrowsWhenXdgDirMissing(): void
    {
        putenv('XDG_RUNTIME_DIR=/nonexistent/path/' . mt_rand());
        $this->expectException(RuntimeException::class);
        SocketPathResolver::resolveDefault();
    }

    public function testAssertParentSafeCreatesMissingParentAt0700(): void
    {
        $socketPath = $this->scratch . '/reli/sidecar.sock';
        SocketPathResolver::assertParentSafe($socketPath);
        $this->assertDirectoryExists(dirname($socketPath));
        $this->assertSame(0o700, fileperms(dirname($socketPath)) & 0o777);
    }

    public function testAssertParentSafeAcceptsExistingCorrectDir(): void
    {
        $parent = $this->scratch . '/reli';
        mkdir($parent, 0o700);
        SocketPathResolver::assertParentSafe($parent . '/sidecar.sock');
        // no throw = pass
        $this->assertTrue(true);
    }

    public function testAssertParentSafeRejectsWrongMode(): void
    {
        $parent = $this->scratch . '/reli';
        mkdir($parent, 0o755);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/mode 0755.*expected 0700/');
        SocketPathResolver::assertParentSafe($parent . '/sidecar.sock');
    }

    public function testAssertParentSafeRejectsSymlinkParent(): void
    {
        $real = $this->scratch . '/real';
        mkdir($real, 0o700);
        $link = $this->scratch . '/link';
        symlink($real, $link);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/symlink/');
        SocketPathResolver::assertParentSafe($link . '/sidecar.sock');
    }
}
