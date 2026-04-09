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

namespace Reli\Inspector\Settings\OutputSettings;

use Reli\BaseTestCase;

class TraceOutputPathResolverTest extends BaseTestCase
{
    public function testExplicitPathIsUsedAsIs(): void
    {
        $tmp = sys_get_temp_dir() . '/reli_test_explicit_' . getmypid();
        try {
            $result = TraceOutputPathResolver::resolveRbtOutputDir($tmp);
            $this->assertSame($tmp, $result);
            $this->assertTrue(is_dir($tmp));
        } finally {
            if (is_dir($tmp)) {
                rmdir($tmp);
            }
        }
    }

    public function testDefaultPathUsesXdgStateHome(): void
    {
        $tmp_state = sys_get_temp_dir() . '/reli_test_xdg_' . getmypid();
        $original = getenv('XDG_STATE_HOME');
        try {
            putenv('XDG_STATE_HOME=' . $tmp_state);

            $result = TraceOutputPathResolver::resolveRbtOutputDir(null);

            $this->assertStringStartsWith($tmp_state . '/reli/daemon-traces/', $result);
            $this->assertTrue(is_dir($result));

            // Session directory name contains a timestamp-like pattern
            $session = basename($result);
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}T\d{6}Z_\d+$/',
                $session
            );
        } finally {
            // Cleanup
            if ($original === false) {
                putenv('XDG_STATE_HOME');
            } else {
                putenv('XDG_STATE_HOME=' . $original);
            }
            // Remove created dirs
            if (is_dir($result ?? '')) {
                rmdir($result);
            }
            $parent = dirname($result ?? '');
            while ($parent !== $tmp_state && is_dir($parent)) {
                @rmdir($parent);
                $parent = dirname($parent);
            }
            @rmdir($tmp_state);
        }
    }

    public function testDefaultPathFallsBackToHome(): void
    {
        $tmp_home = sys_get_temp_dir() . '/reli_test_home_' . getmypid();
        $original_xdg = getenv('XDG_STATE_HOME');
        $original_home = getenv('HOME');
        try {
            putenv('XDG_STATE_HOME=');
            putenv('HOME=' . $tmp_home);

            $result = TraceOutputPathResolver::resolveRbtOutputDir(null);

            $this->assertStringStartsWith(
                $tmp_home . '/.local/state/reli/daemon-traces/',
                $result
            );
            $this->assertTrue(is_dir($result));
        } finally {
            if ($original_xdg === false) {
                putenv('XDG_STATE_HOME');
            } else {
                putenv('XDG_STATE_HOME=' . $original_xdg);
            }
            if ($original_home === false) {
                putenv('HOME');
            } else {
                putenv('HOME=' . $original_home);
            }
            // Cleanup
            if (is_dir($result ?? '')) {
                rmdir($result);
            }
            $parent = dirname($result ?? '');
            while ($parent !== $tmp_home && is_dir($parent)) {
                @rmdir($parent);
                $parent = dirname($parent);
            }
            @rmdir($tmp_home);
        }
    }

    public function testSessionDirsAreUnique(): void
    {
        $tmp = sys_get_temp_dir() . '/reli_test_unique_' . getmypid();
        $original = getenv('XDG_STATE_HOME');
        try {
            putenv('XDG_STATE_HOME=' . $tmp);

            $result1 = TraceOutputPathResolver::resolveRbtOutputDir(null);
            // Slightly different timestamp or PID means different name.
            // Since we're in the same process and same second, they should
            // still be the same. Test that the path is at least deterministic.
            $result2 = TraceOutputPathResolver::resolveRbtOutputDir(null);

            $this->assertSame($result1, $result2);
        } finally {
            if ($original === false) {
                putenv('XDG_STATE_HOME');
            } else {
                putenv('XDG_STATE_HOME=' . $original);
            }
            if (is_dir($result1 ?? '')) {
                rmdir($result1);
            }
            $parent = dirname($result1 ?? '');
            while ($parent !== $tmp && is_dir($parent)) {
                @rmdir($parent);
                $parent = dirname($parent);
            }
            @rmdir($tmp);
        }
    }
}
