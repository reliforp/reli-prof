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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk;

use Reli\BaseTestCase;

class PeriodicityDetectorTest extends BaseTestCase
{
    public function testReturnsEmptyForNoData(): void
    {
        $this->assertSame([], PeriodicityDetector::detect([]));
    }

    public function testIgnoresGroupBelowThreshold(): void
    {
        $bin = 3; // 32-byte bin
        $slots = [];
        for ($i = 0; $i < 5; $i++) {
            $slots[] = ['addr' => 0x1000 + $i * 32, 'fp' => 'A'];
        }
        $groups = PeriodicityDetector::detect(
            [$bin => [0x1000 => $slots]],
            threshold: 32,
        );
        $this->assertSame([], $groups);
    }

    public function testEmitsGroupAboveThreshold(): void
    {
        $bin = 3;
        $slots = [];
        for ($i = 0; $i < 100; $i++) {
            $slots[] = ['addr' => 0x1000 + $i * 32, 'fp' => 'A'];
        }
        $groups = PeriodicityDetector::detect(
            [$bin => [0x1000 => $slots]],
            threshold: 32,
        );
        $this->assertCount(1, $groups);
        $this->assertSame(100, $groups[0]->count);
        $this->assertSame(32, $groups[0]->stride);
        $this->assertSame(32, $groups[0]->bin_size);
        $this->assertSame(0x1000, $groups[0]->sample_addr);
        $this->assertSame(bin2hex('A'), $groups[0]->fingerprint_hex);
    }

    public function testSplitsByFingerprint(): void
    {
        $bin = 3;
        $slots = [];
        for ($i = 0; $i < 50; $i++) {
            $slots[] = ['addr' => 0x1000 + $i * 32, 'fp' => 'A'];
        }
        // Interleaved with shape B (also 50, but should not pool with A).
        for ($i = 0; $i < 50; $i++) {
            $slots[] = ['addr' => 0x2000 + $i * 32, 'fp' => 'B'];
        }
        $groups = PeriodicityDetector::detect(
            [$bin => [0x0 => $slots]],
            threshold: 32,
        );
        $this->assertCount(2, $groups);
        // Both should have count 50 and stride 32.
        foreach ($groups as $g) {
            $this->assertSame(50, $g->count);
            $this->assertSame(32, $g->stride);
        }
    }

    public function testSortsByCountDescending(): void
    {
        $bin = 3;
        $small = [];
        for ($i = 0; $i < 40; $i++) {
            $small[] = ['addr' => 0x1000 + $i * 32, 'fp' => 'small'];
        }
        $big = [];
        for ($i = 0; $i < 200; $i++) {
            $big[] = ['addr' => 0x10000 + $i * 32, 'fp' => 'big'];
        }
        $groups = PeriodicityDetector::detect(
            [$bin => [0x0 => array_merge($small, $big)]],
            threshold: 32,
        );
        $this->assertCount(2, $groups);
        $this->assertSame(200, $groups[0]->count);
        $this->assertSame(40, $groups[1]->count);
    }

    public function testModeStrideOfEmptyOrSingleton(): void
    {
        $this->assertSame(0, PeriodicityDetector::modeStride([]));
        $this->assertSame(0, PeriodicityDetector::modeStride([100]));
    }

    public function testModeStridePicksMostFrequent(): void
    {
        // Mostly 32-byte stride with one 64-byte gap; mode is 32.
        $addrs = [0, 32, 64, 96, 128, 192, 224, 256];
        $this->assertSame(32, PeriodicityDetector::modeStride($addrs));
    }

    public function testReachabilityNullByDefault(): void
    {
        $bin = 3;
        $slots = [];
        for ($i = 0; $i < 50; $i++) {
            $slots[] = ['addr' => 0x1000 + $i * 32, 'fp' => 'A'];
        }
        $groups = PeriodicityDetector::detect(
            [$bin => [0x1000 => $slots]],
            threshold: 32,
        );
        $this->assertCount(1, $groups);
        $this->assertNull($groups[0]->reachability);
        $this->assertSame(0, $groups[0]->reachable_samples);
    }

    public function testReachabilityOrphanWhenNoSampleClaimed(): void
    {
        $bin = 3;
        $slots = [];
        for ($i = 0; $i < 50; $i++) {
            $slots[] = ['addr' => 0x1000 + $i * 32, 'fp' => 'A'];
        }
        $groups = PeriodicityDetector::detect(
            [$bin => [0x1000 => $slots]],
            threshold: 32,
            reachable_set: [],
        );
        $this->assertCount(1, $groups);
        $this->assertSame(PeriodicGroup::REACHABILITY_ORPHAN, $groups[0]->reachability);
        $this->assertSame(0, $groups[0]->reachable_samples);
        $this->assertGreaterThan(0, $groups[0]->sampled_count);
    }

    public function testReachabilityReachableWhenAllSamplesClaimed(): void
    {
        $bin = 3;
        $slots = [];
        $reachable = [];
        for ($i = 0; $i < 50; $i++) {
            $addr = 0x1000 + $i * 32;
            $slots[] = ['addr' => $addr, 'fp' => 'A'];
            $reachable[$addr] = 1;
        }
        $groups = PeriodicityDetector::detect(
            [$bin => [0x1000 => $slots]],
            threshold: 32,
            reachable_set: $reachable,
        );
        $this->assertCount(1, $groups);
        $this->assertSame(PeriodicGroup::REACHABILITY_REACHABLE, $groups[0]->reachability);
        $this->assertSame($groups[0]->sampled_count, $groups[0]->reachable_samples);
    }

    public function testReachabilityMixedWhenSomeSamplesClaimed(): void
    {
        $bin = 3;
        $slots = [];
        $reachable = [];
        for ($i = 0; $i < 50; $i++) {
            $addr = 0x1000 + $i * 32;
            $slots[] = ['addr' => $addr, 'fp' => 'A'];
            // Claim every other slot — guaranteed to hit head + tail
            // samples, so the verdict must be MIXED.
            if ($i % 2 === 0) {
                $reachable[$addr] = 1;
            }
        }
        $groups = PeriodicityDetector::detect(
            [$bin => [0x1000 => $slots]],
            threshold: 32,
            reachable_set: $reachable,
        );
        $this->assertCount(1, $groups);
        $this->assertSame(PeriodicGroup::REACHABILITY_MIXED, $groups[0]->reachability);
    }

    public function testSampleAddrsCappedAtMaxSamples(): void
    {
        $bin = 3;
        $slots = [];
        for ($i = 0; $i < 200; $i++) {
            $slots[] = ['addr' => 0x10000 + $i * 32, 'fp' => 'A'];
        }
        $groups = PeriodicityDetector::detect(
            [$bin => [0x10000 => $slots]],
            threshold: 32,
        );
        $this->assertCount(1, $groups);
        $this->assertLessThanOrEqual(
            PeriodicityDetector::MAX_SAMPLES,
            count($groups[0]->sample_addrs),
        );
        // Head-and-tail sampling: first sample is the lowest address,
        // last is the highest. That's the property the reachability
        // filter relies on.
        $this->assertSame(0x10000, $groups[0]->sample_addrs[0]);
        $this->assertSame(
            0x10000 + 199 * 32,
            $groups[0]->sample_addrs[count($groups[0]->sample_addrs) - 1],
        );
    }
}
