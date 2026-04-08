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

class SummaryPassesTest extends BaseTestCase
{
    // ---- OverviewPass ----

    public function testOverviewPassEmitsOverviewFinding(): void
    {
        $pass = new OverviewPass([
            [
                'zend_mm_heap_total' => 10485760,
                'zend_mm_heap_usage' => 8388608,
                'vm_stack_total' => 65536,
                'compiler_arena_total' => 32768,
                'heap_memory_analyzed_percentage' => 99.0,
                'memory_get_usage' => 8400000,
                'memory_get_real_usage' => 10485760,
                'memory_get_peak_usage' => 9000000,
                'memory_limit' => 134217728,
                'rss' => 20971520,
            ],
        ]);
        $findings = $pass->analyze();

        $overview = array_filter($findings, fn(Finding $f) => $f->kind === 'overview');
        $this->assertCount(1, $overview);
        $f = array_values($overview)[0];
        $this->assertStringContainsString('99.0%', $f->summary);
        $this->assertStringContainsString('memory_get_usage()', $f->summary);
        $this->assertStringContainsString('peak:', $f->summary);
        $this->assertStringContainsString('memory_limit:', $f->summary);
        $this->assertStringContainsString('RSS:', $f->summary);
        $this->assertSame(8400000, $f->facts['memory_get_usage']);
        $this->assertSame(10485760, $f->facts['memory_get_real_usage']);
        $this->assertSame(9000000, $f->facts['memory_get_peak_usage']);
        $this->assertSame(134217728, $f->facts['memory_limit']);
        $this->assertSame(20971520, $f->facts['rss']);
    }

    public function testOverviewPassOmitsRssWhenUnavailable(): void
    {
        $pass = new OverviewPass([
            [
                'zend_mm_heap_total' => 10485760,
                'zend_mm_heap_usage' => 8388608,
                'vm_stack_total' => 65536,
                'compiler_arena_total' => 32768,
                'heap_memory_analyzed_percentage' => 99.0,
                'memory_get_usage' => 8400000,
                'memory_get_real_usage' => 10485760,
            ],
        ]);
        $findings = $pass->analyze();

        $overview = array_filter($findings, fn(Finding $f) => $f->kind === 'overview');
        $f = array_values($overview)[0];
        $this->assertStringNotContainsString('RSS:', $f->summary);
        $this->assertArrayNotHasKey('rss', $f->facts);
    }

    public function testOverviewPassDetectsNearMemoryLimit(): void
    {
        $pass = new OverviewPass([
            [
                'zend_mm_heap_total' => 134217728,
                'zend_mm_heap_usage' => 130000000,
                'heap_memory_analyzed_percentage' => 99.0,
                'memory_get_usage' => 130000000,
                'memory_get_real_usage' => 134217728,
                'memory_get_peak_usage' => 130000000,  // ~96.9% of limit
                'memory_limit' => 134217728,
            ],
        ]);
        $findings = $pass->analyze();

        $near_limit = array_filter($findings, fn(Finding $f) => $f->kind === 'near_memory_limit');
        $this->assertCount(1, $near_limit);
        $f = array_values($near_limit)[0];
        $this->assertSame('high', $f->severity->value);
        $this->assertStringContainsString('headroom', $f->summary);
    }

    public function testOverviewPassNoNearLimitWhenPlentyOfHeadroom(): void
    {
        $pass = new OverviewPass([
            [
                'zend_mm_heap_total' => 10485760,
                'zend_mm_heap_usage' => 8388608,
                'heap_memory_analyzed_percentage' => 99.0,
                'memory_get_usage' => 8400000,
                'memory_get_real_usage' => 10485760,
                'memory_get_peak_usage' => 9000000,  // ~6.7% of limit
                'memory_limit' => 134217728,
            ],
        ]);
        $findings = $pass->analyze();

        $near_limit = array_filter($findings, fn(Finding $f) => $f->kind === 'near_memory_limit');
        $this->assertCount(0, $near_limit);
    }

    public function testOverviewPassDetectsCoverageGap(): void
    {
        $pass = new OverviewPass([
            [
                'zend_mm_heap_total' => 10485760,
                'zend_mm_heap_usage' => 8388608,
                'heap_memory_analyzed_percentage' => 80.0,
            ],
        ]);
        $findings = $pass->analyze();

        $gaps = array_filter($findings, fn(Finding $f) => $f->kind === 'coverage_gap');
        $this->assertCount(1, $gaps);
        $this->assertSame('warning', array_values($gaps)[0]->severity->value);
    }

    public function testOverviewPassNoCoverageGapAt95Pct(): void
    {
        $pass = new OverviewPass([
            [
                'zend_mm_heap_total' => 10485760,
                'zend_mm_heap_usage' => 8388608,
                'heap_memory_analyzed_percentage' => 95.0,
            ],
        ]);
        $findings = $pass->analyze();

        $gaps = array_filter($findings, fn(Finding $f) => $f->kind === 'coverage_gap');
        $this->assertCount(0, $gaps);
    }

    // ---- TypeBreakdownPass ----

    public function testTypeBreakdownDetectsDominantType(): void
    {
        $pass = new TypeBreakdownPass([
            'ZendObjectMemoryLocation' => ['count' => 1000, 'memory_usage' => 900000],
            'ZendStringMemoryLocation' => ['count' => 100, 'memory_usage' => 100000],
        ]);
        $findings = $pass->analyze();

        $dominant = array_filter($findings, fn(Finding $f) => $f->kind === 'dominant_type');
        $this->assertCount(1, $dominant);
        $this->assertStringContainsString('ZendObject', array_values($dominant)[0]->summary);
    }

    public function testTypeBreakdownNoDominantWhenBalanced(): void
    {
        $pass = new TypeBreakdownPass([
            'ZendObjectMemoryLocation' => ['count' => 500, 'memory_usage' => 400000],
            'ZendStringMemoryLocation' => ['count' => 500, 'memory_usage' => 400000],
            'ZendArrayMemoryLocation' => ['count' => 500, 'memory_usage' => 200000],
        ]);
        $findings = $pass->analyze();

        $dominant = array_filter($findings, fn(Finding $f) => $f->kind === 'dominant_type');
        $this->assertCount(0, $dominant);
    }

    public function testTypeBreakdownAlwaysEmitsTypeRankings(): void
    {
        $pass = new TypeBreakdownPass([
            'ZendObjectMemoryLocation' => ['count' => 500, 'memory_usage' => 400000],
            'ZendStringMemoryLocation' => ['count' => 300, 'memory_usage' => 200000],
            'ZendArrayMemoryLocation' => ['count' => 100, 'memory_usage' => 100000],
        ]);
        $findings = $pass->analyze();

        $rankings = array_values(array_filter(
            $findings,
            fn(Finding $f) => $f->kind === 'type_ranking'
        ));
        $this->assertCount(3, $rankings);

        // First entry should be the largest by memory (input is already sorted by memory desc)
        $this->assertSame('ZendObjectMemoryLocation', $rankings[0]->facts['type']);
        $this->assertSame(400000, $rankings[0]->facts['memory_usage']);
        $this->assertSame(500, $rankings[0]->facts['count']);
        $this->assertSame('info', $rankings[0]->severity->value);
        $this->assertSame(400000, $rankings[0]->impact_bytes);

        // Percentage should be calculated
        $this->assertEqualsWithDelta(57.1, $rankings[0]->facts['percentage'], 0.1);
    }

    public function testTypeBreakdownHandlesEmpty(): void
    {
        $pass = new TypeBreakdownPass([]);
        $this->assertSame([], $pass->analyze());
    }

    // ---- ClassRankingPass ----

    public function testClassRankingDetectsDominantClass(): void
    {
        $pass = new ClassRankingPass([
            'App\\BigClass' => ['count' => 10000, 'memory_usage' => 5000000],
            'App\\SmallClass' => ['count' => 10, 'memory_usage' => 1000],
        ]);
        $findings = $pass->analyze();

        $dominant = array_filter($findings, fn(Finding $f) => $f->kind === 'dominant_class');
        $this->assertCount(1, $dominant);
        $f = array_values($dominant)[0];
        $this->assertSame('high', $f->severity->value);
        $this->assertStringContainsString('BigClass', $f->summary);
        $this->assertStringContainsString('10,000', $f->summary);
        // Per-entity cost: 5000000 / 10000 = 500
        $this->assertStringContainsString('500 B', $f->summary);
        $this->assertSame(500, $f->facts['avg_size']);
    }

    public function testClassRankingAlwaysEmitsClassRankings(): void
    {
        $pass = new ClassRankingPass([
            'App\\Big' => ['count' => 1000, 'memory_usage' => 500000],
            'App\\Medium' => ['count' => 500, 'memory_usage' => 200000],
            'App\\Small' => ['count' => 100, 'memory_usage' => 50000],
        ]);
        $findings = $pass->analyze();

        $rankings = array_values(array_filter(
            $findings,
            fn(Finding $f) => $f->kind === 'class_ranking'
        ));
        $this->assertCount(3, $rankings);

        // First should be #1, largest class
        $this->assertSame(1, $rankings[0]->facts['rank']);
        $this->assertSame('App\\Big', $rankings[0]->facts['class_name']);
        $this->assertSame(1000, $rankings[0]->facts['count']);
        $this->assertSame(500000, $rankings[0]->facts['memory_bytes']);
        $this->assertSame(500, $rankings[0]->facts['avg_size']);
        $this->assertSame('info', $rankings[0]->severity->value);
        $this->assertSame(500000, $rankings[0]->impact_bytes);

        // Second should be #2
        $this->assertSame(2, $rankings[1]->facts['rank']);
        $this->assertSame('App\\Medium', $rankings[1]->facts['class_name']);
    }

    public function testClassRankingLimitsTo20(): void
    {
        $classes = [];
        for ($i = 1; $i <= 25; $i++) {
            $classes["App\\Class{$i}"] = ['count' => 100, 'memory_usage' => 10000 * (26 - $i)];
        }
        $pass = new ClassRankingPass($classes);
        $findings = $pass->analyze();

        $rankings = array_filter($findings, fn(Finding $f) => $f->kind === 'class_ranking');
        $this->assertCount(20, $rankings);
    }

    public function testClassRankingHandlesEmpty(): void
    {
        $pass = new ClassRankingPass([]);
        $this->assertSame([], $pass->analyze());
    }

    // ---- CompanionDetectionPass ----

    public function testCompanionDetectionFindsMatchingCounts(): void
    {
        $pass = new CompanionDetectionPass([
            'App\\Parent' => ['count' => 200, 'memory_usage' => 50000],
            'App\\Child' => ['count' => 200, 'memory_usage' => 30000],
        ]);
        $findings = $pass->analyze();

        $companions = array_filter($findings, fn(Finding $f) => $f->kind === 'companion_cluster');
        $this->assertCount(1, $companions);
    }

    public function testCompanionDetectionIgnoresSmallCounts(): void
    {
        $pass = new CompanionDetectionPass([
            'App\\A' => ['count' => 10, 'memory_usage' => 500],
            'App\\B' => ['count' => 10, 'memory_usage' => 500],
        ]);
        $findings = $pass->analyze();

        $companions = array_filter($findings, fn(Finding $f) => $f->kind === 'companion_cluster');
        $this->assertCount(0, $companions);
    }

    public function testCompanionDetectionIgnoresDifferentCounts(): void
    {
        $pass = new CompanionDetectionPass([
            'App\\A' => ['count' => 200, 'memory_usage' => 50000],
            'App\\B' => ['count' => 500, 'memory_usage' => 30000],
        ]);
        $findings = $pass->analyze();

        $this->assertCount(0, $findings);
    }

    public function testCompanionDetectionGroupsIntoCluster(): void
    {
        // 6 classes all with ~1800 count should produce 1 cluster, not 15 pairs
        $pass = new CompanionDetectionPass([
            'App\\A' => ['count' => 1800, 'memory_usage' => 10000],
            'App\\B' => ['count' => 1800, 'memory_usage' => 10000],
            'App\\C' => ['count' => 1802, 'memory_usage' => 10000],
            'App\\D' => ['count' => 1798, 'memory_usage' => 10000],
            'App\\E' => ['count' => 1801, 'memory_usage' => 10000],
            'App\\F' => ['count' => 1799, 'memory_usage' => 10000],
        ]);
        $findings = $pass->analyze();

        // Should be exactly 1 companion_cluster, not 15 companion_pairs
        $this->assertCount(1, $findings);
        $this->assertSame('companion_cluster', $findings[0]->kind);
        $this->assertStringContainsString('6 classes', $findings[0]->summary);
    }

    // ---- RetainedSizeConfidencePass ----

    public function testRetainedExactWhenNoCycles(): void
    {
        $substrate = new \Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate();
        $pass = new RetainedSizeConfidencePass($substrate);
        $findings = $pass->analyze();

        $this->assertCount(1, $findings);
        $this->assertSame('retained_exact', $findings[0]->kind);
        $this->assertStringContainsString('No cycles', $findings[0]->summary);
    }

    public function testRetainedApproximateWhenCyclesExist(): void
    {
        $substrate = new \Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate();
        $substrate->scc_profiles = [
            [
                'id' => 0,
                'nodes' => [1, 2],
                'node_count' => 2,
                'total_size' => 128,
                'ext_in' => 1,
                'ext_out' => 1,
                'class_counts' => ['A' => 2],
                'signature' => 'A:2',
                'single_owner_likelihood' => 'high',
            ],
        ];
        $pass = new RetainedSizeConfidencePass($substrate);
        $findings = $pass->analyze();

        $this->assertCount(1, $findings);
        $this->assertSame('retained_approximate', $findings[0]->kind);
        $this->assertStringContainsString('1 cycle', $findings[0]->summary);
    }
}
