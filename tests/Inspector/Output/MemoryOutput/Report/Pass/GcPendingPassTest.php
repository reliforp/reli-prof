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

class GcPendingPassTest extends BaseTestCase
{
    private string $db_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db_path = tempnam(sys_get_temp_dir(), 'reli_gc_pending_test_') . '.db';
    }

    protected function tearDown(): void
    {
        @unlink($this->db_path);
        parent::tearDown();
    }

    /**
     * SCC reachable only via objects_store: this is the textbook
     * "GC-pending garbage" shape (a refcount cycle that user code
     * can no longer touch). The pass should report it.
     */
    public function testReportsGcCandidateForObjectsStoreOnlyCycle(): void
    {
        $db = $this->createDirectDb();

        // Two-object cycle (10 ↔ 20) reachable only via objects_store.
        // No call_frames root → there is no "live" path that keeps the
        // cycle alive, so it's a GC candidate.
        $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
            (1, 1, 'RootContext'),
            (1, 10, 'ObjectContext'),
            (1, 20, 'ObjectContext')
        ");
        $db->exec("INSERT INTO context_node_locations
            (run_id, node_id, address, size, location_type, class_name) VALUES
            (1, 10, 1000, 64, 'ZendObjectMemoryLocation', 'App\\Cyclic'),
            (1, 20, 2000, 96, 'ZendObjectMemoryLocation', 'App\\Cyclic')
        ");
        $db->exec("INSERT INTO context_edges
            (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
            (1, NULL, 1, 'objects_store', 1, 'weak'),
            (1, 1, 10, 'obj_0', 1, 'strong'),
            (1, 10, 20, 'next', 1, 'strong'),
            (1, 20, 10, 'prev', 0, 'strong')
        ");

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $this->assertNotEmpty($substrate->getSccProfiles(), 'fixture should produce an SCC');

        $pass = new GcPendingPass($substrate, $db, 1);
        $findings = $pass->analyze();

        $this->assertCount(1, $findings);
        $finding = $findings[0];
        $this->assertSame('gc_pending_candidate', $finding->kind);
        $this->assertSame(2, $finding->facts['total_count']);
        $this->assertSame(160, $finding->facts['total_size']);
        $this->assertSame(160, $finding->impact_bytes);
        $this->assertIsArray($finding->facts['classes']);
        $this->assertArrayHasKey('App\\Cyclic', $finding->facts['classes']);
    }

    /**
     * Same cycle, but now also reachable via call_frames. The pass
     * should walk roots in the order they were inserted; the BFS
     * marks each node by the *first* root that reaches it, so a node
     * owned by call_frames is no longer "objects_store only" and
     * should be filtered out.
     */
    public function testIgnoresCycleAlsoReachableFromCallFrames(): void
    {
        $db = $this->createDirectDb();

        $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
            (1, 1, 'RootContext'),
            (1, 2, 'RootContext'),
            (1, 10, 'ObjectContext'),
            (1, 20, 'ObjectContext')
        ");
        $db->exec("INSERT INTO context_node_locations
            (run_id, node_id, address, size, location_type, class_name) VALUES
            (1, 10, 1000, 64, 'ZendObjectMemoryLocation', 'App\\Cyclic'),
            (1, 20, 2000, 96, 'ZendObjectMemoryLocation', 'App\\Cyclic')
        ");
        // call_frames is inserted FIRST so the BFS visits the cycle
        // through call_frames → both nodes are owned by call_frames,
        // not objects_store, and the pass filters them out.
        $db->exec("INSERT INTO context_edges
            (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
            (1, NULL, 1, 'call_frames', 1, 'strong'),
            (1, 1, 10, 'live_ref', 1, 'strong'),
            (1, 10, 20, 'next', 1, 'strong'),
            (1, 20, 10, 'prev', 0, 'strong'),
            (1, NULL, 2, 'objects_store', 1, 'weak'),
            (1, 2, 10, 'obj_0', 0, 'weak'),
            (1, 2, 20, 'obj_1', 0, 'weak')
        ");

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $this->assertNotEmpty($substrate->getSccProfiles(), 'cycle still detected');

        $pass = new GcPendingPass($substrate, $db, 1);
        $findings = $pass->analyze();

        $this->assertSame([], $findings);
    }

    /**
     * No SCC at all → the pass exits early with [].
     */
    public function testReturnsEmptyWhenNoSccExists(): void
    {
        $db = $this->createDirectDb();

        $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
            (1, 1, 'RootContext'),
            (1, 10, 'ObjectContext')
        ");
        $db->exec("INSERT INTO context_node_locations
            (run_id, node_id, address, size, location_type, class_name) VALUES
            (1, 10, 1000, 64, 'ZendObjectMemoryLocation', 'App\\Lonely')
        ");
        $db->exec("INSERT INTO context_edges
            (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
            (1, NULL, 1, 'objects_store', 1, 'weak'),
            (1, 1, 10, 'obj_0', 1, 'strong')
        ");

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $this->assertSame([], $substrate->getSccProfiles());

        $pass = new GcPendingPass($substrate, $db, 1);
        $this->assertSame([], $pass->analyze());
    }

    /**
     * SCC exists but no objects_store root → the cycle is "live"
     * memory and not GC-pending, so the pass returns []. Mirrors the
     * conservative behaviour CycleClusterPass enforces.
     */
    public function testReturnsEmptyWhenNoObjectsStoreRoot(): void
    {
        $db = $this->createDirectDb();

        $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
            (1, 1, 'RootContext'),
            (1, 10, 'ObjectContext'),
            (1, 20, 'ObjectContext')
        ");
        $db->exec("INSERT INTO context_node_locations
            (run_id, node_id, address, size, location_type, class_name) VALUES
            (1, 10, 1000, 64, 'ZendObjectMemoryLocation', 'App\\Cyclic'),
            (1, 20, 2000, 96, 'ZendObjectMemoryLocation', 'App\\Cyclic')
        ");
        $db->exec("INSERT INTO context_edges
            (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
            (1, NULL, 1, 'call_frames', 1, 'strong'),
            (1, 1, 10, 'a', 1, 'strong'),
            (1, 10, 20, 'b', 1, 'strong'),
            (1, 20, 10, 'back', 0, 'strong')
        ");

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $this->assertNotEmpty($substrate->getSccProfiles());

        $pass = new GcPendingPass($substrate, $db, 1);
        $this->assertSame([], $pass->analyze());
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
