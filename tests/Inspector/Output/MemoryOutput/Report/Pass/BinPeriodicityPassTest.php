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

namespace Reli\Inspector\Output\MemoryOutput\Report\Pass;

use Reli\BaseTestCase;
use Reli\Inspector\Output\MemoryOutput\Report\Finding;
use Reli\Inspector\Output\MemoryOutput\Report\FindingSeverity;

class BinPeriodicityPassTest extends BaseTestCase
{
    public function testEmitsNothingWithoutGroups(): void
    {
        $pass = new BinPeriodicityPass([['memory_get_usage' => 1]]);
        $this->assertSame([], $pass->analyze());
    }

    public function testEmitsHotspotWhenGroupDominatesBin(): void
    {
        $pass = new BinPeriodicityPass([[
            'bin_walk' => json_encode([
                'histogram' => [3 => ['count' => 16000, 'total_bytes' => 16000 * 32]],
                'live_small_slot_count' => 16000,
                'live_small_slot_bytes' => 16000 * 32,
                'large_run_count' => 0,
                'large_run_bytes' => 0,
                'walked_chunk_count' => 1,
                'partial' => false,
            ]),
            'bin_walk_periodic_groups' => json_encode([
                [
                    'bin_num' => 3,
                    'bin_size' => 32,
                    'count' => 16000,
                    'stride' => 32,
                    'fingerprint' => str_repeat('ab', 24),
                    'sample_addr' => 0x7f0000000000,
                ],
            ]),
        ]]);
        $findings = $pass->analyze();
        $this->assertCount(1, $findings);
        $this->assertSame('bin_periodic_hotspot', $findings[0]->kind);
        $this->assertSame(FindingSeverity::Medium, $findings[0]->severity);
    }

    public function testEmitsInfoWhenGroupDoesNotDominate(): void
    {
        $pass = new BinPeriodicityPass([[
            'bin_walk' => json_encode([
                'histogram' => [3 => ['count' => 1000, 'total_bytes' => 32000]],
            ]),
            'bin_walk_periodic_groups' => json_encode([
                [
                    'bin_num' => 3,
                    'bin_size' => 32,
                    'count' => 100,
                    'stride' => 32,
                    'fingerprint' => '',
                    'sample_addr' => 0,
                ],
            ]),
        ]]);
        $findings = $pass->analyze();
        $this->assertCount(1, $findings);
        $this->assertSame('bin_periodic_group', $findings[0]->kind);
        $this->assertSame(FindingSeverity::Info, $findings[0]->severity);
    }

    public function testToleratesArrayInputWithoutJsonRoundTrip(): void
    {
        $pass = new BinPeriodicityPass([[
            'bin_walk_periodic_groups' => [
                [
                    'bin_num' => 3,
                    'bin_size' => 32,
                    'count' => 50,
                    'stride' => 32,
                    'fingerprint' => '',
                    'sample_addr' => 0,
                ],
            ],
        ]]);
        $findings = $pass->analyze();
        $this->assertCount(1, $findings);
    }

    public function testOrphanHotspotIsHighSeverity(): void
    {
        // Orphan + dominates the bin = strongest leak signal we can
        // emit without going to a bytes-level decoder.
        $pass = new BinPeriodicityPass([[
            'bin_walk' => json_encode([
                'histogram' => [3 => ['count' => 16000, 'total_bytes' => 16000 * 32]],
            ]),
            'bin_walk_periodic_groups' => json_encode([
                [
                    'bin_num' => 3,
                    'bin_size' => 32,
                    'count' => 16000,
                    'stride' => 32,
                    'fingerprint' => '',
                    'sample_addr' => 0,
                    'reachability' => 'orphan',
                    'reachable_samples' => 0,
                    'sampled_count' => 16,
                ],
            ]),
        ]]);
        $findings = $pass->analyze();
        $this->assertCount(1, $findings);
        $this->assertSame('bin_periodic_hotspot', $findings[0]->kind);
        $this->assertSame(FindingSeverity::High, $findings[0]->severity);
    }

    public function testReachableGroupDemotedToInfo(): void
    {
        // Same shape, dominates the bin, but every sample is claimed
        // by reli's root walker — this is normal data the detectors
        // matched by accident. Demote out of the hotspot bucket.
        $pass = new BinPeriodicityPass([[
            'bin_walk' => json_encode([
                'histogram' => [3 => ['count' => 16000, 'total_bytes' => 16000 * 32]],
            ]),
            'bin_walk_periodic_groups' => json_encode([
                [
                    'bin_num' => 3,
                    'bin_size' => 32,
                    'count' => 16000,
                    'stride' => 32,
                    'fingerprint' => '',
                    'sample_addr' => 0,
                    'reachability' => 'reachable',
                    'reachable_samples' => 16,
                    'sampled_count' => 16,
                ],
            ]),
        ]]);
        $findings = $pass->analyze();
        $this->assertCount(1, $findings);
        $this->assertSame('bin_periodic_reachable', $findings[0]->kind);
        $this->assertSame(FindingSeverity::Info, $findings[0]->severity);
    }

    public function testOrphanWithoutHotspotIsInfoOrphanKind(): void
    {
        // Orphan, but only 100 of 1000 slots in the bin — not a hotspot,
        // so this is a candidate, not a confirmed leak.
        $pass = new BinPeriodicityPass([[
            'bin_walk' => json_encode([
                'histogram' => [3 => ['count' => 1000, 'total_bytes' => 32000]],
            ]),
            'bin_walk_periodic_groups' => json_encode([
                [
                    'bin_num' => 3,
                    'bin_size' => 32,
                    'count' => 100,
                    'stride' => 32,
                    'fingerprint' => '',
                    'sample_addr' => 0,
                    'reachability' => 'orphan',
                    'reachable_samples' => 0,
                    'sampled_count' => 16,
                ],
            ]),
        ]]);
        $findings = $pass->analyze();
        $this->assertCount(1, $findings);
        $this->assertSame('bin_periodic_orphan', $findings[0]->kind);
        $this->assertSame(FindingSeverity::Info, $findings[0]->severity);
    }
}
