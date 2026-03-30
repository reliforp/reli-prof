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

class HeapStatsTest extends TestCase
{
    public function testParseSize(): void
    {
        $this->assertSame(256 * 1024 * 1024, HeapStats::parseSize('256M'));
        $this->assertSame(1024 * 1024 * 1024, HeapStats::parseSize('1G'));
        $this->assertSame(512 * 1024, HeapStats::parseSize('512K'));
        $this->assertSame(1234, HeapStats::parseSize('1234'));
    }

    public function testHumanReadableBytes(): void
    {
        $this->assertSame('0B', HeapStats::humanReadableBytes(0));
        $this->assertSame('512B', HeapStats::humanReadableBytes(512));
        $this->assertSame('1.0K', HeapStats::humanReadableBytes(1024));
        $this->assertSame('1.5M', HeapStats::humanReadableBytes((int)(1.5 * 1024 * 1024)));
        $this->assertSame('2.0G', HeapStats::humanReadableBytes(2 * 1024 * 1024 * 1024));
    }

    public function testFormatSize(): void
    {
        $stats = new HeapStats(
            size: 10 * 1024 * 1024,
            real_size: 16 * 1024 * 1024,
            peak: 12 * 1024 * 1024,
            limit: 128 * 1024 * 1024,
        );
        $this->assertSame('10.0M', $stats->formatSize());
    }
}
