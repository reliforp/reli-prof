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
    private string $db_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db_path = tempnam(sys_get_temp_dir(), 'reli_dynprops_test_') . '.db';
    }

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
        $this->seedDynamicProperties($db, instance_count: 15, dp_size: 256);

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $this->assertTrue($substrate->hasTreeLinkIndex(), 'substrate index expected');

        $pass = new DynamicPropertiesPass($db, 1, $substrate);
        $findings = $pass->analyze();

        $this->assertCount(1, $findings);
        $finding = $findings[0];
        $this->assertSame('dynamic_properties_overhead', $finding->kind);
        $this->assertSame('App\\Sloppy', $finding->facts['class_name']);
        $this->assertSame(15, $finding->facts['count']);
        $this->assertSame(15 * 256, $finding->facts['dynamic_properties_size']);
        $this->assertSame(15 * 256, $finding->impact_bytes);
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
        $this->seedDynamicProperties($db, instance_count: 10, dp_size: 64);

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
        // 12 * 100 KiB = ~1.2 MiB total → Medium.
        $this->seedDynamicProperties($db, instance_count: 12, dp_size: 100 * 1024);

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
        $this->seedDynamicProperties($db, instance_count: 15, dp_size: 256);

        // No substrate argument → SQL path.
        $pass = new DynamicPropertiesPass($db, 1);
        $findings = $pass->analyze();

        $this->assertCount(1, $findings);
        $this->assertSame('App\\Sloppy', $findings[0]->facts['class_name']);
        $this->assertSame(15, $findings[0]->facts['count']);
        $this->assertSame(15 * 256, $findings[0]->facts['dynamic_properties_size']);
    }

    /**
     * Insert N App\Sloppy instances under a synthetic root, each one
     * pointing at a `dynamic_properties` table sized $dp_size bytes.
     * Mirrors the shape PdoMemoryOutput emits for objects with extra
     * runtime-added properties.
     */
    private function seedDynamicProperties(\PDO $db, int $instance_count, int $dp_size): void
    {
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
            $obj_addr = $next_addr++;
            $dp_addr = $next_addr++;

            $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
                (1, {$obj_id}, 'ObjectContext'),
                (1, {$dp_id}, 'ArrayContext')
            ");
            $db->exec("INSERT INTO context_node_locations
                (run_id, node_id, address, size, location_type, class_name) VALUES
                (1, {$obj_id}, {$obj_addr}, 64, 'ZendObjectMemoryLocation', 'App\\Sloppy'),
                (1, {$dp_id}, {$dp_addr}, {$dp_size}, 'ZendArrayMemoryLocation', NULL)
            ");
            $db->exec("INSERT INTO context_edges
                (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
                (1, 1, {$obj_id}, 'obj', 1, 'strong'),
                (1, {$obj_id}, {$dp_id}, 'dynamic_properties', 1, 'strong')
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

        return $db;
    }
}
