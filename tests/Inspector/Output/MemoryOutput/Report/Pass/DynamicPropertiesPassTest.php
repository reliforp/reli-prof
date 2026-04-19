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

class DynamicPropertiesPassTest extends BaseTestCase
{
    private string $db_path = '';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $tmp_path = tempnam(sys_get_temp_dir(), 'reli_dynprops_test_');
        $this->assertNotFalse($tmp_path);
        $this->db_path = $tmp_path . '.db';
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->db_path);
        parent::tearDown();
    }

    /**
     * 15 instances of App\Sloppy each carry a dynamic_properties table.
     * The substrate path should fire (since the substrate is supplied
     * and has a tree-link index) and report the class once.
     */
    public function testReportsDynamicPropertiesAboveThresholdViaSubstrate(): void
    {
        $db = $this->createDirectDb();
        $this->seedDynamicProperties(
            $db,
            instance_count: 15,
            header_size: 56,
            used_table_size: 192,
            unused_table_size: 128,
            property_count: 4,
        );

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $this->assertTrue($substrate->hasTreeLinkIndex(), 'substrate index expected');

        $pass = new DynamicPropertiesPass($db, 1, $substrate);
        $findings = $pass->analyze();

        $this->assertCount(1, $findings);
        $finding = $findings[0];
        $this->assertSame('dynamic_properties_overhead', $finding->kind);
        $this->assertSame('App\\Sloppy', $finding->facts['class_name']);
        $this->assertSame(15, $finding->facts['count']);
        $this->assertSame(15 * 376, $finding->facts['dynamic_properties_size']);
        $this->assertSame(15 * 56, $finding->facts['header_bytes']);
        $this->assertSame(15 * 192, $finding->facts['used_table_bytes']);
        $this->assertSame(15 * 128, $finding->facts['unused_table_bytes']);
        $this->assertSame(15 * 4, $finding->facts['property_count']);
        $this->assertSame(4.0, $finding->facts['avg_properties_per_object']);
        $this->assertSame(376, $finding->facts['avg_structural_bytes_per_object']);
        $this->assertSame(15, $finding->facts['objects_with_unused_capacity']);
        $this->assertSame(15 * 312, $finding->facts['estimated_avoidable_bytes_if_static']);
        $this->assertSame(15 * 312, $finding->impact_bytes);
        // Below the 1MiB severity threshold → Low.
        $this->assertSame(FindingSeverity::Low, $finding->severity);
    }

    /**
     * Exactly 10 instances → the HAVING count(*) > 10 (and the
     * substrate path's matching `if ($s['cnt'] > 10)`) should both
     * filter the class out.
     */
    public function testIgnoresClassesAtOrBelow10Instances(): void
    {
        $db = $this->createDirectDb();
        $this->seedDynamicProperties($db, instance_count: 10);

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $pass = new DynamicPropertiesPass($db, 1, $substrate);

        $this->assertSame([], $pass->analyze());
    }

    /**
     * Total dynamic-property overhead crosses the 1 MiB severity
     * threshold → finding promoted to Medium. Same fixture shape
     * as the first test, just with bigger DP tables.
     */
    public function testEscalatesSeverityAbove1MiBOverhead(): void
    {
        $db = $this->createDirectDb();
        // 12 * (~100 KiB avoidable) = ~1.2 MiB total → Medium.
        $this->seedDynamicProperties(
            $db,
            instance_count: 12,
            header_size: 56,
            used_table_size: 100 * 1024,
            unused_table_size: 0,
            property_count: 4,
        );

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $pass = new DynamicPropertiesPass($db, 1, $substrate);
        $findings = $pass->analyze();

        $this->assertCount(1, $findings);
        $this->assertSame(FindingSeverity::Medium, $findings[0]->severity);
    }

    /**
     * Without a substrate the pass falls back to the raw SQL path.
     * Same fixture, same expectation: one finding for the class above
     * the threshold.
     */
    public function testSqlFallbackPathReturnsSameResult(): void
    {
        $db = $this->createDirectDb();
        $this->seedDynamicProperties(
            $db,
            instance_count: 15,
            header_size: 56,
            used_table_size: 192,
            unused_table_size: 128,
            property_count: 4,
        );

        // No substrate argument → SQL path.
        $pass = new DynamicPropertiesPass($db, 1);
        $findings = $pass->analyze();

        $this->assertCount(1, $findings);
        $this->assertSame('App\\Sloppy', $findings[0]->facts['class_name']);
        $this->assertSame(15, $findings[0]->facts['count']);
        $this->assertSame(15 * 376, $findings[0]->facts['dynamic_properties_size']);
        $this->assertSame(15 * 312, $findings[0]->facts['estimated_avoidable_bytes_if_static']);
    }

    /**
     * Insert N App\Sloppy instances under a synthetic root, each one
     * carrying a `dynamic_properties` array header plus its used table,
     * spare capacity region, and N property entries.
     */
    private function seedDynamicProperties(
        \PDO $db,
        int $instance_count,
        int $header_size = 56,
        int $used_table_size = 192,
        int $unused_table_size = 128,
        int $property_count = 4,
    ): void {
        $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
            (1, 1, 'RootContext')
        ");
        $db->exec("INSERT INTO context_edges
            (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
            (1, NULL, 1, 'call_frames', 1, 'strong')
        ");

        $next_id = 2;
        $next_addr = 0x1000;
        for ($i = 0; $i < $instance_count; $i++) {
            $obj_id = $next_id++;
            $dp_id = $next_id++;
            $elements_id = $next_id++;
            $unused_id = $next_id++;
            $obj_addr = $next_addr++;
            $dp_addr = $next_addr++;
            $elements_addr = $next_addr++;
            $unused_addr = $next_addr++;

            $property_node_ids = [];
            for ($j = 0; $j < $property_count; $j++) {
                $property_node_ids[] = $next_id++;
            }

            $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
                (1, {$obj_id}, 'ObjectContext'),
                (1, {$dp_id}, 'ArrayHeaderContext'),
                (1, {$elements_id}, 'ArrayElementsContext'),
                (1, {$unused_id}, 'ArrayPossibleOverheadContext')
            ");
            foreach ($property_node_ids as $property_node_id) {
                $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
                    (1, {$property_node_id}, 'ArrayElementContext')
                ");
            }
            $db->exec("INSERT INTO context_node_locations
                (run_id, node_id, address, size, location_type, class_name) VALUES
                (1, {$obj_id}, {$obj_addr}, 64, 'ZendObjectMemoryLocation', 'App\\Sloppy'),
                (1, {$dp_id}, {$dp_addr}, {$header_size}, 'ZendArrayMemoryLocation', NULL),
                (1, {$elements_id}, {$elements_addr}, {$used_table_size}, 'ZendArrayTableMemoryLocation', NULL),
                (1, {$unused_id}, {$unused_addr}, {$unused_table_size}, 'ZendArrayTableOverheadMemoryLocation', NULL)
            ");
            $db->exec("INSERT INTO context_edges
                (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
                (1, 1, {$obj_id}, 'obj', 1, 'strong'),
                (1, {$obj_id}, {$dp_id}, 'dynamic_properties', 1, 'strong'),
                (1, {$dp_id}, {$elements_id}, 'array_elements', 1, 'strong'),
                (1, {$dp_id}, {$unused_id}, 'possible_unused_area', 1, 'strong')
            ");
            foreach ($property_node_ids as $index => $property_node_id) {
                $key = 'prop_' . $index;
                $db->exec("INSERT INTO context_edges
                    (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
                    (1, {$elements_id}, {$property_node_id}, '{$key}', 1, 'strong')
                ");
            }
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

        return $db;
    }
}
