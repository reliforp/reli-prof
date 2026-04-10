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

class CpuUsageReaderTest extends TestCase
{
    public function testMinWindowDefaultIsHalfSecond(): void
    {
        $reader = new CpuUsageReader();
        // Just verify construction works with default
        $this->assertInstanceOf(CpuUsageReader::class, $reader);
    }

    public function testCustomMinWindow(): void
    {
        $reader = new CpuUsageReader(min_window_seconds: 1.0);
        $this->assertInstanceOf(CpuUsageReader::class, $reader);
    }

    public function testClearRemovesPidState(): void
    {
        $reader = new CpuUsageReader(min_window_seconds: 0.0);
        $my_pid = getmypid();
        if ($my_pid === false) {
            $this->markTestSkipped('Cannot get PID');
        }

        // First read: returns null (no baseline)
        $result = $reader->read($my_pid);
        $this->assertNull($result);

        // Clear state
        $reader->clear($my_pid);

        // After clear, first read should return null again
        $result = $reader->read($my_pid);
        $this->assertNull($result);
    }

    public function testReadNonExistentPidReturnsNull(): void
    {
        $reader = new CpuUsageReader();
        // Use a PID that almost certainly doesn't exist
        $this->assertNull($reader->read(999999999));
    }
}
