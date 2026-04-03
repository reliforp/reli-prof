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
}
