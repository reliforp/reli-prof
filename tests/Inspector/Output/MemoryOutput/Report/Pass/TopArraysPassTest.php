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
use Reli\Inspector\Output\MemoryOutput\Report\FindingSeverity;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate;

/**
 * Tests target the substrate (in-memory) path of TopArraysPass. The
 * SQL fallback path leans on the v_arrays view that PdoMemoryOutput
 * builds at startup, which would require recreating a non-trivial
 * chunk of the production schema in the fixture; the substrate path
 * is the one report runs actually take when the substrate is loaded
 * (which is the modern default), so it's where coverage matters most.
 */
class TopArraysPassTest extends BaseTestCase
{
    private string $db_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db_path = tempnam(sys_get_temp_dir(), 'reli_top_arrays_test_') . '.db';
    }

    protected function tearDown(): void
    {
        @unlink($this->db_path);
        parent::tearDown();
    }

    /**
     * One 12 KB array (header + element_table) above the 10 KiB threshold:
     * the substrate path should report exactly one large_array finding,
     * and the element_count should match the array_elements children.
     */
    public function testReportsLargeArrayAboveThreshold(): void
    {
        $db = $this->createDirectDb();
        $this->seedArray($db, header_size: 12000, element_count: 5);

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $pass = new TopArraysPass($substrate);
        $findings = $pass->analyze();

        $this->assertCount(1, $findings);
        $finding = $findings[0];
        $this->assertSame('large_array', $finding->kind);
        $this->assertSame(12000, $finding->facts['table_size']);
        $this->assertSame(5, $finding->facts['element_count']);
        $this->assertSame(FindingSeverity::Low, $finding->severity);
        // The fixture array node id (the second node inserted: root=1, array=2).
        $this->assertSame([2], $finding->evidence_node_ids);
    }

    /**
     * Below the 10 KiB shallow/retained cutoff the array should be
     * skipped: the early-`continue` in the loop drops it before any
     * Finding is built.
     */
    public function testIgnoresArraysBelow10KiBThreshold(): void
    {
        $db = $this->createDirectDb();
        // 8 KB array — below 10240.
        $this->seedArray($db, header_size: 8000, element_count: 3);

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $pass = new TopArraysPass($substrate);

        $this->assertSame([], $pass->analyze());
    }

    /**
     * A > 1 MiB array should be promoted from Low to Medium severity.
     */
    public function testEscalatesSeverityAbove1MiBArray(): void
    {
        $db = $this->createDirectDb();
        $this->seedArray($db, header_size: 2 * 1024 * 1024, element_count: 100);

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $pass = new TopArraysPass($substrate);
        $findings = $pass->analyze();

        $this->assertCount(1, $findings);
        $this->assertSame(FindingSeverity::Medium, $findings[0]->severity);
    }

    /**
     * Insert one array under a synthetic root with the given header
     * size and element count. Shape:
     *
     *   root → array_node →(link 'array_elements')→ table_node →(N children)
     *
     * The pass treats the array_node as the "array" because it has an
     * `array_elements` child; element_count is the number of children
     * of the table_node.
     */
    private function seedArray(\PDO $db, int $header_size, int $element_count): void
    {
        $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
            (1, 1, 'RootContext'),
            (1, 2, 'ArrayContext'),
            (1, 3, 'ArrayElementsContext')
        ");
        $db->exec("INSERT INTO context_node_locations
            (run_id, node_id, address, size, location_type, class_name) VALUES
            (1, 2, 1000, {$header_size}, 'ZendArrayMemoryLocation', NULL),
            (1, 3, 2000, 0, 'ZendArrayMemoryLocation', NULL)
        ");
        $db->exec("INSERT INTO context_edges
            (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
            (1, NULL, 1, 'call_frames', 1, 'strong'),
            (1, 1, 2, 'top_array', 1, 'strong'),
            (1, 2, 3, 'array_elements', 1, 'strong')
        ");

        $next_id = 100;
        $next_addr = 0x10000;
        for ($i = 0; $i < $element_count; $i++) {
            $elem_id = $next_id++;
            $elem_addr = $next_addr++;
            $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
                (1, {$elem_id}, 'ZvalContext')
            ");
            $db->exec("INSERT INTO context_node_locations
                (run_id, node_id, address, size, location_type, class_name) VALUES
                (1, {$elem_id}, {$elem_addr}, 16, 'ZvalMemoryLocation', NULL)
            ");
            $db->exec("INSERT INTO context_edges
                (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
                (1, 3, {$elem_id}, 'elem', 1, 'strong')
            ");
        }
    }

    private function createDirectDb(): \PDO
    {
        $db = new \PDO('sqlite:' . $this->db_path);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $db->exec('CREATE TABLE IF NOT EXISTS runs (run_id INTEGER PRIMARY KEY, created_at TEXT NOT NULL)');
        $db->exec("INSERT INTO runs (run_id, created_at) VALUES (1, '2024-01-01T00:00:00Z')");

        $db->exec('
            CREATE TABLE IF NOT EXISTS context_nodes (
                run_id INTEGER NOT NULL,
                node_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                canonical_node_id INTEGER,
                PRIMARY KEY (run_id, node_id)
            )
        ');
        $db->exec("
            CREATE TABLE IF NOT EXISTS context_edges (
                id INTEGER PRIMARY KEY,
                run_id INTEGER NOT NULL,
                parent_node_id INTEGER,
                child_node_id INTEGER NOT NULL,
                link_name TEXT NOT NULL,
                is_tree INTEGER NOT NULL,
                strength TEXT NOT NULL DEFAULT 'strong'
            )
        ");
        $db->exec('
            CREATE TABLE IF NOT EXISTS context_node_locations (
                id INTEGER PRIMARY KEY,
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
            )
        ');
        // NodeLabeler probes context_node_attributes for array key labels;
        // the table needs to exist even when empty so the JOIN succeeds.
        $db->exec('
            CREATE TABLE IF NOT EXISTS context_node_attributes (
                id INTEGER PRIMARY KEY,
                run_id INTEGER NOT NULL,
                node_id INTEGER NOT NULL,
                key TEXT NOT NULL,
                value TEXT
            )
        ');

        return $db;
    }
}
