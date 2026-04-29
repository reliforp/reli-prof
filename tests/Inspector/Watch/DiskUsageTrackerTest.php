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

namespace Reli\Inspector\Watch;

use PHPUnit\Framework\TestCase;

class DiskUsageTrackerTest extends TestCase
{
    public function testCanWriteWithinLimit(): void
    {
        $tracker = new DiskUsageTracker(1024);
        $this->assertTrue($tracker->canWrite());
    }

    public function testCanWriteExceedsLimit(): void
    {
        $tracker = new DiskUsageTracker(100);

        $tmpFile = tempnam(sys_get_temp_dir(), 'reli_test_');
        $this->assertNotFalse($tmpFile);
        file_put_contents($tmpFile, str_repeat('x', 200));

        $tracker->recordFile($tmpFile);
        $this->assertFalse($tracker->canWrite());

        unlink($tmpFile);
    }

    public function testUnlimitedWhenZero(): void
    {
        $tracker = new DiskUsageTracker(0);
        $this->assertTrue($tracker->canWrite());
    }

    public function testGetTotalBytes(): void
    {
        $tracker = new DiskUsageTracker(1024 * 1024);
        $this->assertSame(0, $tracker->getTotalBytes());

        $tmpFile = tempnam(sys_get_temp_dir(), 'reli_test_');
        $this->assertNotFalse($tmpFile);
        file_put_contents($tmpFile, str_repeat('x', 500));

        $tracker->recordFile($tmpFile);
        $this->assertSame(500, $tracker->getTotalBytes());

        unlink($tmpFile);
    }

    public function testScansExistingFilesOnConstruction(): void
    {
        $dir = sys_get_temp_dir() . '/reli_disk_test_' . uniqid();
        mkdir($dir);

        // Create existing dump files
        file_put_contents($dir . '/watch-123-20260330.rdump', str_repeat('x', 300));
        file_put_contents($dir . '/watch-456-20260330.rdump', str_repeat('x', 200));
        // Non-matching file should be ignored
        file_put_contents($dir . '/other.txt', str_repeat('x', 999));

        $tracker = new DiskUsageTracker(1000, $dir);
        $this->assertSame(500, $tracker->getTotalBytes());

        // Adding more should accumulate
        file_put_contents($dir . '/watch-789-20260330.rdump', str_repeat('x', 400));
        $tracker->recordFile($dir . '/watch-789-20260330.rdump');
        $this->assertSame(900, $tracker->getTotalBytes());
        $this->assertTrue($tracker->canWrite());

        // Over limit
        file_put_contents($dir . '/watch-999-20260330.rdump', str_repeat('x', 200));
        $tracker->recordFile($dir . '/watch-999-20260330.rdump');
        $this->assertFalse($tracker->canWrite());

        // Cleanup
        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }
}
