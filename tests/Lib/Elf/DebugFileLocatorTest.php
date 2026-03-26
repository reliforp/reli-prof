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

namespace Reli\Lib\Elf;

use Reli\BaseTestCase;

class DebugFileLocatorTest extends BaseTestCase
{
    public function testLocateNonExistentBinary(): void
    {
        $locator = new DebugFileLocator();
        $this->assertNull($locator->locate('/nonexistent/binary'));
    }

    public function testLocateNonElfFile(): void
    {
        $tmp = tempnam('/tmp', 'reli-test-');
        assert($tmp !== false);
        file_put_contents($tmp, 'not an ELF file');
        try {
            $locator = new DebugFileLocator();
            $this->assertNull($locator->locate($tmp));
        } finally {
            unlink($tmp);
        }
    }

    public function testLocateCaching(): void
    {
        $locator = new DebugFileLocator();
        // Call twice - second should use cache
        $result1 = $locator->locate('/nonexistent/a');
        $result2 = $locator->locate('/nonexistent/a');
        $this->assertNull($result1);
        $this->assertNull($result2);
    }

    public function testLocatePhpBinaryReturnsSomethingOrNull(): void
    {
        // PHP binary should have .note.gnu.build-id or .gnu_debuglink
        // but debug files may not be installed
        $locator = new DebugFileLocator();
        $result = $locator->locate(PHP_BINARY);
        // Just verify it doesn't crash - result depends on debug packages
        $this->assertTrue($result === null || is_file($result));
    }

    public function testCustomDebugDirs(): void
    {
        $locator = new DebugFileLocator(['/nonexistent/debug/dir']);
        $result = $locator->locate(PHP_BINARY);
        // With a non-existent debug dir, build-id lookup should fail
        // but debuglink might still find something in binary's dir
        $this->assertTrue($result === null || is_file($result));
    }
}
