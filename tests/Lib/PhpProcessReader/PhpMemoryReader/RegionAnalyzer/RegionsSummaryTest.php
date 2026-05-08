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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer;

use Reli\BaseTestCase;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\Result\RegionsSummary;

class RegionsSummaryTest extends BaseTestCase
{
    private function makeSummary(
        int $heap_total = 4194304,
        int $heap_usage = 100,
        int $chunk_total = 2097152,
        int $chunk_usage = 100,
        int $huge_total = 2097152,
        int $huge_usage = 0,
        int $vm_stack_total = 65536,
        int $vm_stack_usage = 0,
        int $compiler_arena_total = 32768,
        int $compiler_arena_usage = 0,
        int $overhead = 500,
        int $array_overhead = 0,
    ): RegionsSummary {
        return new RegionsSummary(
            $heap_total,
            $heap_usage,
            $chunk_total,
            $chunk_usage,
            $huge_total,
            $huge_usage,
            $vm_stack_total,
            $vm_stack_usage,
            $compiler_arena_total,
            $compiler_arena_usage,
            $overhead,
            $array_overhead,
        );
    }

    public function testCorrectedToArrayUsesDbSums(): void
    {
        // Simulate streaming mode: RegionAnalyzer saw no individual locations,
        // so usage fields are near-zero (only overhead + region totals).
        $summary = $this->makeSummary(
            heap_usage: 65536 + 32768 + 500, // vm_stack_total + compiler_arena_total + overhead
            chunk_usage: 65536 + 32768 + 500,
            huge_usage: 0,
        );

        $region_sums = [
            'zend_mm_heap' => 800000,
            'zend_mm_huge' => 200000,
            'vm_stack' => 4096,
            'compiler_arena' => 2048,
        ];

        $corrected = $summary->correctedToArray($region_sums);

        // chunk_usage = db_chunk(800000) + overhead(500) + vm_stack_total(65536) + compiler_arena_total(32768)
        $expected_chunk = 800000 + 500 + 65536 + 32768;
        $this->assertSame($expected_chunk, $corrected['zend_mm_chunk_usage']);
        $this->assertSame(200000, $corrected['zend_mm_huge_usage']);
        $this->assertSame($expected_chunk + 200000, $corrected['zend_mm_heap_usage']);
        $this->assertSame(4096, $corrected['vm_stack_usage']);
        $this->assertSame(2048, $corrected['compiler_arena_usage']);

        // Totals should be unchanged
        $this->assertSame(4194304, $corrected['zend_mm_heap_total']);
        $this->assertSame(2097152, $corrected['zend_mm_chunk_total']);
        $this->assertSame(2097152, $corrected['zend_mm_huge_total']);
        $this->assertSame(65536, $corrected['vm_stack_total']);
        $this->assertSame(32768, $corrected['compiler_arena_total']);
    }

    public function testCorrectedToArrayWithEmptyRegionSums(): void
    {
        $summary = $this->makeSummary();

        // Empty region sums (no data in DB)
        $corrected = $summary->correctedToArray([]);

        // Should still include overhead + region totals
        $expected_chunk = 0 + 500 + 65536 + 32768;
        $this->assertSame($expected_chunk, $corrected['zend_mm_chunk_usage']);
        $this->assertSame(0, $corrected['zend_mm_huge_usage']);
    }

    public function testQueryRegionSums(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE context_node_locations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id INTEGER NOT NULL,
            node_id INTEGER NOT NULL,
            address BIGINT,
            size BIGINT,
            location_type TEXT NOT NULL,
            class_name TEXT,
            string_value TEXT,
            refcount BIGINT,
            type_info BIGINT,
            region TEXT,
            bin_overhead BIGINT DEFAULT 0
        )');

        $stmt = $db->prepare(
            'INSERT INTO context_node_locations (run_id, node_id, address, size, location_type, region)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([1, 1, 0x1000, 100, 'ZendStringMemoryLocation', 'zend_mm_heap']);
        $stmt->execute([1, 2, 0x2000, 200, 'ZendObjectMemoryLocation', 'zend_mm_heap']);
        $stmt->execute([1, 3, 0x3000, 500, 'ZendArrayMemoryLocation', 'zend_mm_huge']);
        $stmt->execute([1, 4, 0x4000, 50, 'ZendStringMemoryLocation', 'vm_stack']);
        $stmt->execute([1, 5, 0x5000, 25, 'ZendStringMemoryLocation', 'compiler_arena']);
        // Same address reached via different context path — must be deduped
        $stmt->execute([1, 8, 0x1000, 100, 'ZendStringMemoryLocation', 'zend_mm_heap']);
        $stmt->execute([1, 9, 0x2000, 200, 'ZendObjectMemoryLocation', 'zend_mm_heap']);
        // Different run_id — should be excluded
        $stmt->execute([2, 6, 0x6000, 999, 'ZendStringMemoryLocation', 'zend_mm_heap']);
        // NULL region — should be excluded
        $stmt->execute([1, 7, 0x7000, 888, 'ZendStringMemoryLocation', null]);

        $result = RegionsSummary::queryRegionSums($db, 1);
        $sums = $result['sums'];

        // 0x1000 and 0x2000 appear twice but must be counted once each
        $this->assertSame(300, $sums['zend_mm_heap']);
        $this->assertSame(500, $sums['zend_mm_huge']);
        $this->assertSame(50, $sums['vm_stack']);
        $this->assertSame(25, $sums['compiler_arena']);
        $this->assertArrayNotHasKey('', $sums);
        $this->assertSame(0, $result['overhead']);
    }

    public function testToArrayIsUnchanged(): void
    {
        $summary = $this->makeSummary();
        $arr = $summary->toArray();

        $this->assertSame(4194304, $arr['zend_mm_heap_total']);
        $this->assertSame(100, $arr['zend_mm_heap_usage']);
        $this->assertSame(2097152, $arr['zend_mm_chunk_total']);
        $this->assertSame(100, $arr['zend_mm_chunk_usage']);
        $this->assertSame(2097152, $arr['zend_mm_huge_total']);
        $this->assertSame(0, $arr['zend_mm_huge_usage']);
        $this->assertSame(65536, $arr['vm_stack_total']);
        $this->assertSame(0, $arr['vm_stack_usage']);
        $this->assertSame(32768, $arr['compiler_arena_total']);
        $this->assertSame(0, $arr['compiler_arena_usage']);
        $this->assertSame(500, $arr['possible_allocation_overhead_total']);
        $this->assertSame(0, $arr['possible_array_overhead_total']);
        $this->assertSame(0, $arr['possible_string_overhead_total']);
    }

    public function testToArrayIncludesPossibleStringOverheadTotal(): void
    {
        $summary = new RegionsSummary(
            zend_mm_heap_total: 4194304,
            zend_mm_heap_usage: 0,
            zend_mm_chunk_total: 2097152,
            zend_mm_chunk_usage: 0,
            zend_mm_huge_total: 2097152,
            zend_mm_huge_usage: 0,
            vm_stack_total: 0,
            vm_stack_usage: 0,
            compiler_arena_total: 0,
            compiler_arena_usage: 0,
            possible_allocation_overhead_total: 0,
            possible_array_overhead_total: 0,
            possible_string_overhead_total: 12345,
        );
        $arr = $summary->toArray();
        $this->assertSame(12345, $arr['possible_string_overhead_total']);
    }

    public function testCorrectedToArrayIncludesPossibleStringOverheadTotal(): void
    {
        $summary = new RegionsSummary(
            zend_mm_heap_total: 4194304,
            zend_mm_heap_usage: 0,
            zend_mm_chunk_total: 2097152,
            zend_mm_chunk_usage: 0,
            zend_mm_huge_total: 2097152,
            zend_mm_huge_usage: 0,
            vm_stack_total: 0,
            vm_stack_usage: 0,
            compiler_arena_total: 0,
            compiler_arena_usage: 0,
            possible_allocation_overhead_total: 0,
            possible_array_overhead_total: 0,
            possible_string_overhead_total: 6789,
        );
        $corrected = $summary->correctedToArray([]);
        $this->assertSame(6789, $corrected['possible_string_overhead_total']);
    }
}
