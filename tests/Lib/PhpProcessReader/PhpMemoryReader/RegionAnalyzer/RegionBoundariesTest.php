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
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MemoryLocations;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\Result\RegionsSummary;
use Reli\Lib\Process\MemoryLocation;

class RegionBoundariesTest extends BaseTestCase
{
    private function createDb(): \PDO
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
            region TEXT
        )');
        return $db;
    }

    public function testBackfillRegionsClassifiesNullRows(): void
    {
        $db = $this->createDb();

        // Chunk at 0x100000, size 2MB (0x100000..0x300000)
        $chunk = new MemoryLocations([new MemoryLocation(0x100000, 2 * 1024 * 1024)]);
        // Huge at 0x400000, size 4MB
        $huge = new MemoryLocations([new MemoryLocation(0x400000, 4 * 1024 * 1024)]);
        // VM stack inside chunk at 0x100000, size 64KB (0x100000..0x110000)
        $vm_stack = new MemoryLocations([new MemoryLocation(0x100000, 65536)]);
        // Compiler arena inside chunk at 0x180000, size 32KB (0x180000..0x188000)
        $compiler_arena = new MemoryLocations([new MemoryLocation(0x180000, 32768)]);

        $boundaries = new RegionBoundaries($chunk, $huge, $vm_stack, $compiler_arena);

        $stmt = $db->prepare(
            'INSERT INTO context_node_locations'
            . ' (run_id, node_id, address, size, location_type, region)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
        );
        // In chunk, not vm_stack or compiler_arena → zend_mm_heap
        $stmt->execute([1, 1, 0x150000, 100, 'ZendStringMemoryLocation', null]);
        // In vm_stack → vm_stack
        $stmt->execute([1, 2, 0x100100, 50, 'ZendStringMemoryLocation', null]);
        // In compiler_arena → compiler_arena
        $stmt->execute([1, 3, 0x180100, 30, 'ZendStringMemoryLocation', null]);
        // In huge → zend_mm_huge
        $stmt->execute([1, 4, 0x400100, 200, 'ZendArrayMemoryLocation', null]);
        // Outside all regions → outside
        $stmt->execute([1, 5, 0xF00000, 10, 'ZendStringMemoryLocation', null]);
        // Already has region → should NOT be updated
        $stmt->execute([1, 6, 0x150100, 80, 'ZendStringMemoryLocation', 'zend_mm_heap']);
        // Different run_id → should NOT be updated
        $stmt->execute([2, 7, 0x150200, 60, 'ZendStringMemoryLocation', null]);

        $boundaries->backfillRegions($db, 1);

        // Verify results
        $rows = $db->query(
            'SELECT id, region FROM context_node_locations ORDER BY id'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertSame('zend_mm_heap', $rows[0]['region']);     // id=1
        $this->assertSame('vm_stack', $rows[1]['region']);          // id=2
        $this->assertSame('compiler_arena', $rows[2]['region']);    // id=3
        $this->assertSame('zend_mm_huge', $rows[3]['region']);      // id=4
        $this->assertSame('outside', $rows[4]['region']);           // id=5
        $this->assertSame('zend_mm_heap', $rows[5]['region']);      // id=6 (unchanged)
        $this->assertNull($rows[6]['region']);                      // id=7 (different run)
    }

    public function testBackfillRegionsWithNoNullRows(): void
    {
        $db = $this->createDb();

        $chunk = new MemoryLocations([new MemoryLocation(0x1000, 2 * 1024 * 1024)]);
        $boundaries = new RegionBoundaries(
            $chunk,
            new MemoryLocations(),
            new MemoryLocations(),
            new MemoryLocations(),
        );

        $stmt = $db->prepare(
            'INSERT INTO context_node_locations'
            . ' (run_id, node_id, address, size, location_type, region)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([1, 1, 0x10000, 100, 'ZendStringMemoryLocation', 'zend_mm_heap']);

        // Should not error
        $boundaries->backfillRegions($db, 1);

        $row = $db->query(
            'SELECT region FROM context_node_locations WHERE id = 1'
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('zend_mm_heap', $row['region']);
    }

    /**
     * End-to-end test simulating the streaming mode percentage calculation.
     *
     * Models a realistic ZendMM layout where vm_stack and compiler_arena
     * are allocated WITHIN a chunk.  All rows start with region=NULL (as
     * in real streaming collection), then backfill → queryRegionSums →
     * correctedToArray → percentage.
     */
    public function testEndToEndPercentageWithVmStackInsideChunk(): void
    {
        $db = $this->createDb();

        // ---- region layout (mirrors real ZendMM) ----
        // chunk:          0x7F00_0000 .. +2 MB
        // vm_stack:       0x7F00_1000 .. +64 KB  (inside chunk)
        // compiler_arena: 0x7F10_0000 .. +32 KB  (inside chunk)
        // huge:           0x7E00_0000 .. +4 MB    (separate mmap)
        $chunk_addr  = 0x7F000000;
        $chunk_size  = 2 * 1024 * 1024;  // 2 MB
        $vm_addr     = 0x7F001000;
        $vm_size     = 65536;            // 64 KB
        $ca_addr     = 0x7F100000;
        $ca_size     = 32768;            // 32 KB
        $huge_addr   = 0x7E000000;
        $huge_size   = 4 * 1024 * 1024;  // 4 MB

        $chunk = new MemoryLocations([new MemoryLocation($chunk_addr, $chunk_size)]);
        $huge  = new MemoryLocations([new MemoryLocation($huge_addr, $huge_size)]);
        $vm    = new MemoryLocations([new MemoryLocation($vm_addr, $vm_size)]);
        $ca    = new MemoryLocations([new MemoryLocation($ca_addr, $ca_size)]);

        $boundaries = new RegionBoundaries($chunk, $huge, $vm, $ca);

        // ---- insert location rows with NULL region ----
        $ins = $db->prepare(
            'INSERT INTO context_node_locations'
            . ' (run_id, node_id, address, size, location_type, region)'
            . ' VALUES (?, ?, ?, ?, ?, NULL)'
        );

        // heap allocations (in chunk, outside vm_stack & compiler_arena)
        $ins->execute([1, 1, 0x7F050000, 40000, 'ZendObjectMemoryLocation']);
        $ins->execute([1, 2, 0x7F060000, 30000, 'ZendStringMemoryLocation']);
        $ins->execute([1, 3, 0x7F070000, 20000, 'ZendArrayMemoryLocation']);
        // vm_stack content (inside vm_stack range, thus inside chunk)
        $ins->execute([1, 4, 0x7F001100, 4096,  'ZendStringMemoryLocation']);
        $ins->execute([1, 5, 0x7F002000, 2048,  'ZendStringMemoryLocation']);
        // compiler_arena content (inside compiler_arena range)
        $ins->execute([1, 6, 0x7F100100, 1024,  'ZendOpArrayHeaderMemoryLocation']);
        // huge allocation
        $ins->execute([1, 7, 0x7E000100, 500000, 'ZendArrayMemoryLocation']);

        // ---- backfill ----
        $boundaries->backfillRegions($db, 1);

        // ---- verify region classification ----
        /** @var array<int, string> */
        $regions = [];
        $rows = $db->query(
            'SELECT node_id, region FROM context_node_locations WHERE run_id = 1 ORDER BY node_id'
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $regions[(int)$r['node_id']] = $r['region'];
        }

        // heap objects in chunk but outside vm_stack/compiler_arena → zend_mm_heap
        $this->assertSame('zend_mm_heap', $regions[1]);
        $this->assertSame('zend_mm_heap', $regions[2]);
        $this->assertSame('zend_mm_heap', $regions[3]);
        // vm_stack content → vm_stack (even though it is INSIDE the chunk)
        $this->assertSame('vm_stack', $regions[4]);
        $this->assertSame('vm_stack', $regions[5]);
        // compiler_arena content → compiler_arena
        $this->assertSame('compiler_arena', $regions[6]);
        // huge allocation → zend_mm_huge
        $this->assertSame('zend_mm_huge', $regions[7]);

        // ---- queryRegionSums ----
        $sums = RegionsSummary::queryRegionSums($db, 1);

        $this->assertSame(90000, $sums['zend_mm_heap']);  // 40000+30000+20000
        $this->assertSame(6144, $sums['vm_stack']);    // 4096+2048
        $this->assertSame(1024, $sums['compiler_arena']);
        $this->assertSame(500000, $sums['zend_mm_huge']);

        // ---- correctedToArray ----
        // In streaming mode the RegionAnalyzer produces a summary with
        // overhead=0 (no ZendMmChunkMemoryLocation), usage from individual
        // locations = 0 (lightweight), but totals are correct.
        $base_summary = new RegionsSummary(
            zend_mm_heap_total:  $chunk_size + $huge_size,  // chunk + huge
            zend_mm_heap_usage:  $vm_size + $ca_size,       // streaming: only totals
            zend_mm_chunk_total: $chunk_size,
            zend_mm_chunk_usage: $vm_size + $ca_size,
            zend_mm_huge_total:  $huge_size,
            zend_mm_huge_usage:  0,
            vm_stack_total:      $vm_size,
            vm_stack_usage:      0,
            compiler_arena_total: $ca_size,
            compiler_arena_usage: 0,
            possible_allocation_overhead_total: 0,
            possible_array_overhead_total: 0,
        );

        $corrected = $base_summary->correctedToArray($sums);

        // chunk_usage = db_heap(90000) + overhead(0) + vm_total(65536) + ca_total(32768)
        $expected_chunk_usage = 90000 + 0 + $vm_size + $ca_size;
        $this->assertSame($expected_chunk_usage, $corrected['zend_mm_chunk_usage']);
        $this->assertSame(500000, $corrected['zend_mm_huge_usage']);
        $this->assertSame(
            $expected_chunk_usage + 500000,
            $corrected['zend_mm_heap_usage']
        );
        $this->assertSame(6144, $corrected['vm_stack_usage']);
        $this->assertSame(1024, $corrected['compiler_arena_usage']);

        // ---- percentage ----
        // memory_get_usage typically ≈ heap_usage.  Use a plausible value.
        $memory_get_usage = 700000;
        $pct = (float)$corrected['zend_mm_heap_usage']
            / (float)$memory_get_usage * 100.0;

        // Should be well above 0 (the bug gave near-zero).
        $this->assertGreaterThan(50.0, $pct, 'percentage must not be near-zero');
        // Exact expected: (188304 + 500000) / 700000 * 100 ≈ 98.3%
        $this->assertEqualsWithDelta(98.3, $pct, 0.5);
    }
}
