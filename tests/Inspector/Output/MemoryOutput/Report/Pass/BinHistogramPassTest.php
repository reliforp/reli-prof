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

class BinHistogramPassTest extends BaseTestCase
{
    public function testEmitsNoFindingsWithoutBinWalkSummary(): void
    {
        $pass = new BinHistogramPass([['memory_get_usage' => 1024]]);
        $this->assertSame([], $pass->analyze());
    }

    public function testEmitsNoFindingsForEmptyHistogram(): void
    {
        $pass = new BinHistogramPass([[
            'bin_walk' => json_encode([
                'histogram' => [],
                'live_small_slot_count' => 0,
                'live_small_slot_bytes' => 0,
                'large_run_count' => 0,
                'large_run_bytes' => 0,
                'walked_chunk_count' => 1,
                'partial' => false,
            ]),
        ]]);
        $this->assertSame([], $pass->analyze());
    }

    public function testEmitsOverviewAndPerBinFindings(): void
    {
        $pass = new BinHistogramPass([[
            'bin_walk' => json_encode([
                'histogram' => [
                    3 => ['count' => 16041, 'total_bytes' => 16041 * 32],
                    13 => ['count' => 234, 'total_bytes' => 234 * 192],
                ],
                'live_small_slot_count' => 16041 + 234,
                'live_small_slot_bytes' => 16041 * 32 + 234 * 192,
                'large_run_count' => 1,
                'large_run_bytes' => 8 * 4096,
                'walked_chunk_count' => 2,
                'partial' => false,
            ]),
        ]]);
        $findings = $pass->analyze();

        $this->assertNotEmpty($findings);
        $kinds = array_map(static fn(Finding $f) => $f->kind, $findings);
        $this->assertContains('bin_histogram_overview', $kinds);
        $this->assertContains('bin_histogram_entry', $kinds);

        $entries = array_values(array_filter(
            $findings,
            static fn(Finding $f) => $f->kind === 'bin_histogram_entry',
        ));
        $this->assertCount(2, $entries);

        // Both entries are info-level (rendering data, not actionable).
        foreach ($entries as $f) {
            $this->assertSame(FindingSeverity::Info, $f->severity);
        }

        // Bin sizes resolved through ZendMmBinsInfo (3 → 32, 13 → 192).
        $bin_sizes = array_map(
            static fn(Finding $f) => $f->facts['bin_size'],
            $entries,
        );
        sort($bin_sizes);
        $this->assertSame([32, 192], $bin_sizes);
    }

    public function testTolemoratesPlainArrayValues(): void
    {
        // The pass also accepts the array straight from CollectedMemories
        // (no JSON round-trip), so in-process pipelines stay clean.
        $pass = new BinHistogramPass([[
            'bin_walk' => [
                'histogram' => [3 => ['count' => 100, 'total_bytes' => 3200]],
                'live_small_slot_count' => 100,
                'live_small_slot_bytes' => 3200,
                'large_run_count' => 0,
                'large_run_bytes' => 0,
                'walked_chunk_count' => 1,
                'partial' => false,
            ],
        ]]);
        $findings = $pass->analyze();
        $this->assertNotEmpty($findings);
    }
}
