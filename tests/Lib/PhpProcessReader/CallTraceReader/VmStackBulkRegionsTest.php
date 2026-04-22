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

namespace Reli\Lib\PhpProcessReader\CallTraceReader;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use Reli\BaseTestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class VmStackBulkRegionsTest extends BaseTestCase
{
    #[Test]
    public function testShallowStackReturnsSingleRegionCoveringFromBaseToMargin(): void
    {
        $base = 0x10_000_000;
        $top = $base + 2000;            // 2KB used
        $end = $base + 262144;          // 256KB segment

        $regions = CallTraceReader::computeVmStackBulkRegions($base, $top, $end);

        // Shallow: hot window (top-8K..top+16K) reaches below base, so we emit
        // a single region starting at base up to top+16KB.
        $this->assertCount(1, $regions);
        $this->assertSame($base, $regions[0]['address']);
        $this->assertSame($top + 16384 - $base, $regions[0]['size']);
    }

    #[Test]
    public function testDeepStackSplitsHotThenCold(): void
    {
        $base = 0x10_000_000;
        $top = $base + 180_000;         // 180KB used (deep)
        $end = $base + 262144;

        $regions = CallTraceReader::computeVmStackBulkRegions($base, $top, $end);

        // Hot chunk: [top-8K, top+16K]; cold chunk: [base, top-8K]
        $this->assertCount(2, $regions);

        // iov[1] (hot) comes first
        $hot_start = $top - 8192;
        $hot_end = min($end, $top + 16384);
        $this->assertSame($hot_start, $regions[0]['address']);
        $this->assertSame($hot_end - $hot_start, $regions[0]['size']);

        // iov[2] (cold) covers the base up to the hot chunk
        $this->assertSame($base, $regions[1]['address']);
        $this->assertSame($hot_start - $base, $regions[1]['size']);
    }

    #[Test]
    public function testHotChunkIsClampedAtSegmentEnd(): void
    {
        $base = 0x10_000_000;
        $end = $base + 262144;
        $top = $end - 4000;             // top near segment end

        $regions = CallTraceReader::computeVmStackBulkRegions($base, $top, $end);

        // Hot chunk must not exceed $end even though top+16K would
        $this->assertCount(2, $regions);
        $this->assertSame($end, $regions[0]['address'] + $regions[0]['size']);
    }

    #[Test]
    public function testHotStartIsClampedAtVmStackBase(): void
    {
        $base = 0x10_000_000;
        $top = $base + 4000;            // top within 8K of base
        $end = $base + 262144;

        $regions = CallTraceReader::computeVmStackBulkRegions($base, $top, $end);

        // Hot window reaches below base -> single-region path
        $this->assertCount(1, $regions);
        $this->assertSame($base, $regions[0]['address']);
    }

    #[Test]
    public function testZeroStackReturnsEmpty(): void
    {
        $base = 0x10_000_000;
        $regions = CallTraceReader::computeVmStackBulkRegions($base, $base, $base);

        $this->assertSame([], $regions);
    }

    #[Test]
    public function testHotChunkIncludesVmStackTopAndFramesBelow(): void
    {
        $base = 0x10_000_000;
        $top = $base + 100_000;         // 100KB used
        $end = $base + 262144;

        $regions = CallTraceReader::computeVmStackBulkRegions($base, $top, $end);

        // The single most important property: the hot region MUST cover the
        // frame CED points to, which lives in [top - frame_size, top].
        $this->assertCount(2, $regions);
        $hot_addr = $regions[0]['address'];
        $hot_size = $regions[0]['size'];
        $this->assertLessThanOrEqual($top, $hot_addr);
        $this->assertGreaterThanOrEqual($top, $hot_addr + $hot_size - 1);
    }
}
