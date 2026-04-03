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
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate;

class CycleClusterPassTest extends BaseTestCase
{
    private string $db_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db_path = tempnam(sys_get_temp_dir(), 'reli_cycle_test_') . '.db';
    }

    protected function tearDown(): void
    {
        @unlink($this->db_path);
        parent::tearDown();
    }

    /**
     * PDO downstream of a cycle that is only reachable from objects_store
     * (GC candidate) should be reported as resource_leak_risk.
     */
    public function testResourceLeakReportedForGcCandidateCycle(): void
    {
        $db = $this->createDirectDb();

        // Nodes: two cyclic objects (10, 20) and a PDO (30), all under objects_store
        $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
            (1, 1, 'RootContext'),
            (1, 10, 'ObjectContext'),
            (1, 20, 'ObjectContext'),
            (1, 30, 'ObjectContext')
        ");
        $db->exec("INSERT INTO context_node_locations
            (run_id, node_id, address, size, location_type, class_name) VALUES
            (1, 10, 1000, 64, 'ZendObjectMemoryLocation', 'App\\Service'),
            (1, 20, 2000, 64, 'ZendObjectMemoryLocation', 'App\\Repository'),
            (1, 30, 3000, 64, 'ZendObjectMemoryLocation', 'PDO')
        ");
        // objects_store -> 10 -> 20 (tree), 20 -> 10 (non-tree, creates cycle)
        // 10 -> 30 (tree, PDO downstream)
        $db->exec("INSERT INTO context_edges
            (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
            (1, NULL, 1, 'objects_store', 1, 'weak'),
            (1, 1, 10, 'obj_0', 1, 'strong'),
            (1, 1, 20, 'obj_1', 1, 'strong'),
            (1, 10, 20, 'repo', 1, 'strong'),
            (1, 20, 10, 'service', 0, 'strong'),
            (1, 10, 30, 'pdo', 1, 'strong')
        ");

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $this->assertNotEmpty($substrate->getSccProfiles(), 'SCC should be detected');

        $pass = new CycleClusterPass($substrate, $db, 1);
        $findings = $pass->analyze();

        $resource_risks = array_filter(
            $findings,
            fn(Finding $f) => $f->kind === 'resource_leak_risk'
        );
        $this->assertNotEmpty(
            $resource_risks,
            'PDO downstream of GC-only cycle should be reported'
        );
    }

    /**
     * PDO downstream of a cycle that is reachable from a strong root
     * (e.g. DI container in Laravel) should NOT be reported.
     */
    public function testResourceLeakNotReportedForStronglyRootedCycle(): void
    {
        $db = $this->createDirectDb();

        // Two roots: call_frames (strong) and objects_store (weak)
        // The cycle nodes are reachable from call_frames → not GC candidates
        $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
            (1, 1, 'RootContext'),
            (1, 2, 'RootContext'),
            (1, 10, 'ObjectContext'),
            (1, 20, 'ObjectContext'),
            (1, 30, 'ObjectContext')
        ");
        $db->exec("INSERT INTO context_node_locations
            (run_id, node_id, address, size, location_type, class_name) VALUES
            (1, 10, 1000, 64, 'ZendObjectMemoryLocation', 'App\\Container'),
            (1, 20, 2000, 64, 'ZendObjectMemoryLocation', 'App\\Service'),
            (1, 30, 3000, 64, 'ZendObjectMemoryLocation', 'PDO')
        ");
        // call_frames (strong) -> 10 -> 20 (tree), 20 -> 10 (non-tree, cycle)
        // objects_store (weak) -> 10, 20 (also reachable, but weak)
        // 10 -> 30 (PDO downstream)
        $db->exec("INSERT INTO context_edges
            (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
            (1, NULL, 1, 'call_frames', 1, 'strong'),
            (1, 1, 10, 'container', 1, 'strong'),
            (1, 10, 20, 'service', 1, 'strong'),
            (1, 20, 10, 'container', 0, 'strong'),
            (1, 10, 30, 'pdo', 1, 'strong'),
            (1, NULL, 2, 'objects_store', 1, 'weak'),
            (1, 2, 10, 'obj_0', 0, 'weak'),
            (1, 2, 20, 'obj_1', 0, 'weak'),
            (1, 2, 30, 'obj_2', 0, 'weak')
        ");

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $this->assertNotEmpty($substrate->getSccProfiles(), 'SCC should be detected');

        $pass = new CycleClusterPass($substrate, $db, 1);
        $findings = $pass->analyze();

        $resource_risks = array_filter(
            $findings,
            fn(Finding $f) => $f->kind === 'resource_leak_risk'
        );
        $this->assertEmpty(
            $resource_risks,
            'PDO downstream of strongly-rooted cycle should NOT be reported'
        );
    }

    /**
     * When there is no objects_store root at all, no resource leak risks
     * should be reported (conservative: cannot determine GC candidacy).
     */
    public function testResourceLeakNotReportedWithoutObjectsStore(): void
    {
        $db = $this->createDirectDb();

        $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
            (1, 1, 'RootContext'),
            (1, 10, 'ObjectContext'),
            (1, 20, 'ObjectContext'),
            (1, 30, 'ObjectContext')
        ");
        $db->exec("INSERT INTO context_node_locations
            (run_id, node_id, address, size, location_type, class_name) VALUES
            (1, 10, 1000, 64, 'ZendObjectMemoryLocation', 'App\\A'),
            (1, 20, 2000, 64, 'ZendObjectMemoryLocation', 'App\\B'),
            (1, 30, 3000, 64, 'ZendObjectMemoryLocation', 'PDO')
        ");
        $db->exec("INSERT INTO context_edges
            (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
            (1, NULL, 1, 'call_frames', 1, 'strong'),
            (1, 1, 10, 'a', 1, 'strong'),
            (1, 10, 20, 'b', 1, 'strong'),
            (1, 20, 10, 'back', 0, 'strong'),
            (1, 10, 30, 'pdo', 1, 'strong')
        ");

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $pass = new CycleClusterPass($substrate, $db, 1);
        $findings = $pass->analyze();

        $resource_risks = array_filter(
            $findings,
            fn(Finding $f) => $f->kind === 'resource_leak_risk'
        );
        $this->assertEmpty(
            $resource_risks,
            'No resource_leak_risk without objects_store root'
        );
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
