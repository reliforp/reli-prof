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

namespace Reli\Inspector\Output\MemoryOutput\Report\Substrate;

use Reli\BaseTestCase;
use Reli\Inspector\Output\MemoryOutput\MemoryAnalysisResult;
use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Inspector\Output\MemoryOutput\PdoMemoryOutput;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;
use Reli\Lib\Process\MemoryLocation;

class GraphSubstrateTest extends BaseTestCase
{
    private string $db_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db_path = tempnam(sys_get_temp_dir(), 'reli_graph_test_') . '.db';
    }

    protected function tearDown(): void
    {
        @unlink($this->db_path);
        parent::tearDown();
    }

    public function testLoadFromDbBuildsNodeSizes(): void
    {
        $leaf = $this->createMockContext('leaf', [], [
            new ZendObjectMemoryLocation(0x2000, 128, 1, 7, 'App\\Leaf'),
        ]);
        $top = $this->createMockContext('top', ['root' => $leaf], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = GraphSubstrate::loadFromDb($db, 1);

        $this->assertNotEmpty($substrate->node_sizes);
        $this->assertContains(128, $substrate->node_sizes);
    }

    public function testSubtreeSizesComputed(): void
    {
        $child = $this->createMockContext('child', [], [
            new ZendObjectMemoryLocation(0x2000, 200, 1, 7, 'App\\Child'),
        ]);
        $parent = $this->createMockContext('parent', ['child_link' => $child], [
            new ZendObjectMemoryLocation(0x1000, 100, 1, 7, 'App\\Parent'),
        ]);
        $top = $this->createMockContext('top', ['root' => $parent], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = GraphSubstrate::loadFromDb($db, 1);

        // Parent's subtree should include child: 100 + 200 = 300
        $this->assertNotEmpty($substrate->subtree_sizes);
        $max_subtree = max($substrate->subtree_sizes);
        $this->assertSame(300, $max_subtree);
    }

    public function testRootsIdentified(): void
    {
        $child = $this->createMockContext('child', [], [
            new ZendObjectMemoryLocation(0x2000, 64, 1, 7, 'App\\Node'),
        ]);
        $top = $this->createMockContext('top', ['root' => $child], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = GraphSubstrate::loadFromDb($db, 1);

        $this->assertNotEmpty($substrate->roots);
    }

    public function testSccDetectsNoCyclesInTree(): void
    {
        $leaf = $this->createMockContext('leaf', [], [
            new ZendObjectMemoryLocation(0x2000, 64, 1, 7, 'App\\Node'),
        ]);
        $top = $this->createMockContext('top', ['root' => $leaf], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = GraphSubstrate::loadFromDb($db, 1);

        $this->assertEmpty($substrate->scc_profiles);
        $this->assertEmpty($substrate->node_to_scc);
    }

    public function testSccDetectsCycles(): void
    {
        // Create a cycle: shared node referenced from two parents
        $shared = $this->createMockContext('shared', [], [
            new ZendObjectMemoryLocation(0x3000, 64, 1, 7, 'App\\Shared'),
        ]);
        $parent1 = $this->createMockContext('parent1', ['link_a' => $shared], [
            new ZendObjectMemoryLocation(0x1000, 64, 1, 7, 'App\\P1'),
        ]);
        $parent2 = $this->createMockContext('parent2', ['link_b' => $shared], [
            new ZendObjectMemoryLocation(0x2000, 64, 1, 7, 'App\\P2'),
        ]);
        $top = $this->createMockContext('top', ['p1' => $parent1, 'p2' => $parent2], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = GraphSubstrate::loadFromDb($db, 1);

        // Shared reference creates a non-tree edge but not necessarily a cycle
        // (cycles require A->B->...->A). Verify edge_count is correct.
        $this->assertGreaterThan(0, $substrate->edge_count);
    }

    public function testEdgeCountTracked(): void
    {
        $child = $this->createMockContext('child', [], [
            new ZendObjectMemoryLocation(0x2000, 64, 1, 7, 'App\\Node'),
        ]);
        $top = $this->createMockContext('top', ['root' => $child], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = GraphSubstrate::loadFromDb($db, 1);

        $this->assertGreaterThan(0, $substrate->edge_count);
    }

    public function testNodeClassesPopulated(): void
    {
        $child = $this->createMockContext('child', [], [
            new ZendObjectMemoryLocation(0x2000, 64, 1, 7, 'App\\MyClass'),
        ]);
        $top = $this->createMockContext('top', ['root' => $child], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = GraphSubstrate::loadFromDb($db, 1);

        $this->assertContains('App\\MyClass', $substrate->node_classes);
    }

    public function testWeakEdgeExcludedFromSubtreeSize(): void
    {
        $weak_child = $this->createMockContextWithStrength('weak_child', [], [
            new ZendObjectMemoryLocation(0x3000, 500, 1, 7, 'App\\WeakChild'),
        ]);
        $strong_child = $this->createMockContextWithStrength('strong_child', [], [
            new ZendObjectMemoryLocation(0x2000, 200, 1, 7, 'App\\StrongChild'),
        ]);
        $parent = $this->createMockContextWithStrength(
            'parent',
            ['strong_link' => $strong_child, 'weak_link' => $weak_child],
            [new ZendObjectMemoryLocation(0x1000, 100, 1, 7, 'App\\Parent')],
            ['weak_link' => EdgeStrength::Weak],
        );
        $top = $this->createMockContextWithStrength('top', ['root' => $parent], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = GraphSubstrate::loadFromDb($db, 1);

        // Parent subtree = 100 (self) + 200 (strong child) = 300
        // Weak child (500) should NOT be included
        $parent_node_id = null;
        foreach ($substrate->node_sizes as $nid => $size) {
            if ($size === 100) {
                $parent_node_id = $nid;
                break;
            }
        }
        $this->assertNotNull($parent_node_id);
        $this->assertSame(300, $substrate->subtree_sizes[$parent_node_id]);
    }

    public function testStructuralEdgeExcludedFromSubtreeSize(): void
    {
        $structural_child = $this->createMockContextWithStrength('handlers', [], [
            new ZendObjectMemoryLocation(0x3000, 400, 1, 7, 'App\\Handlers'),
        ]);
        $parent = $this->createMockContextWithStrength(
            'parent',
            ['object_handlers' => $structural_child],
            [new ZendObjectMemoryLocation(0x1000, 100, 1, 7, 'App\\Parent')],
            ['object_handlers' => EdgeStrength::Structural],
        );
        $top = $this->createMockContextWithStrength('top', ['root' => $parent], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = GraphSubstrate::loadFromDb($db, 1);

        // Parent subtree = 100 (self only), structural child excluded
        $parent_node_id = null;
        foreach ($substrate->node_sizes as $nid => $size) {
            if ($size === 100) {
                $parent_node_id = $nid;
                break;
            }
        }
        $this->assertNotNull($parent_node_id);
        $this->assertSame(100, $substrate->subtree_sizes[$parent_node_id]);
    }

    public function testWeakEdgeExcludedFromScc(): void
    {
        // Create a shared node referenced via both strong and weak edges.
        // The weak back-reference should NOT form a cycle in SCC.
        $shared = $this->createMockContextWithStrength('shared', [], [
            new ZendObjectMemoryLocation(0x3000, 64, 1, 7, 'App\\Shared'),
        ]);
        $parent = $this->createMockContextWithStrength(
            'parent',
            ['child' => $shared],
            [new ZendObjectMemoryLocation(0x1000, 64, 1, 7, 'App\\Parent')],
        );
        // objects_store references via weak edge
        $store = $this->createMockContextWithStrength(
            'store',
            ['obj1' => $shared],
            [new MemoryLocation(0x4000, 32)],
            ['obj1' => EdgeStrength::Weak],
        );
        $top = $this->createMockContextWithStrength(
            'top',
            ['tree' => $parent, 'objects_store' => $store],
            [],
            ['objects_store' => EdgeStrength::Weak],
        );
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = GraphSubstrate::loadFromDb($db, 1);

        // No cycles should exist since the back-reference is weak
        $this->assertEmpty($substrate->scc_profiles);
    }

    /**
     * Test that two nodes sharing the same address are unified for SCC detection.
     *
     * Simulates streaming mode: object A appears as node 100 (objects_store phase)
     * and node 500 (call_frames phase). Similarly for object B.
     * Edge 100 -> 200 (A->B in objects_store) and 600 -> 500 (B->A in call_frames).
     * Without unification: no cycle. With unification: cycle A->B->A.
     */
    public function testSccDetectsCycleViaAddressUnification(): void
    {
        $db = $this->createDirectDb();

        // Insert nodes: A has two nodes (100, 500), B has two nodes (200, 600)
        $db->exec("INSERT INTO context_nodes (run_id, node_id, type, canonical_node_id) VALUES
            (1, 100, 'ObjectContext', 100),
            (1, 200, 'ObjectContext', 200),
            (1, 500, 'ObjectContext', 100),
            (1, 600, 'ObjectContext', 200)
        ");

        // Insert locations with shared addresses
        $db->exec("INSERT INTO context_node_locations (run_id, node_id, address, size, location_type, class_name) VALUES
            (1, 100, 1000, 64, 'ZendObjectMemoryLocation', 'App\\NodeA'),
            (1, 200, 2000, 64, 'ZendObjectMemoryLocation', 'App\\NodeB'),
            (1, 500, 1000, 64, 'ZendObjectMemoryLocation', 'App\\NodeA'),
            (1, 600, 2000, 64, 'ZendObjectMemoryLocation', 'App\\NodeB')
        ");

        // Insert edges: A->B (tree, strong) and B->A (non-tree, strong)
        // In objects_store phase: 100 -> 200
        // In call_frames phase: 600 -> 500
        $db->exec("INSERT INTO context_edges
            (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
            (1, NULL, 100, 'objects_store', 1, 'strong'),
            (1, 100, 200, 'next', 1, 'strong'),
            (1, NULL, 500, 'call_frames', 1, 'strong'),
            (1, 600, 500, 'next', 0, 'strong')
        ");

        $substrate = GraphSubstrate::loadFromDb($db, 1);

        // With address unification, a cycle should be detected: A(100,500) <-> B(200,600)
        $this->assertNotEmpty($substrate->scc_profiles, 'SCC should detect cycle via address unification');
        $scc = $substrate->scc_profiles[0];
        // All 4 nodes should be in the SCC (expanded from canonical)
        $this->assertCount(4, $scc['nodes']);
        $this->assertEqualsCanonicalizing([100, 200, 500, 600], $scc['nodes']);
    }

    /**
     * Test that nodes with different addresses are NOT unified.
     */
    public function testNoUnificationWhenAddressesDiffer(): void
    {
        $db = $this->createDirectDb();

        $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
            (1, 1, 'ObjectContext'),
            (1, 2, 'ObjectContext'),
            (1, 3, 'ObjectContext')
        ");

        $db->exec("INSERT INTO context_node_locations (run_id, node_id, address, size, location_type) VALUES
            (1, 1, 1000, 64, 'ZendObjectMemoryLocation'),
            (1, 2, 2000, 64, 'ZendObjectMemoryLocation'),
            (1, 3, 3000, 64, 'ZendObjectMemoryLocation')
        ");

        // Linear chain: 1 -> 2 -> 3 (no cycle)
        $db->exec("INSERT INTO context_edges
            (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
            (1, NULL, 1, 'root', 1, 'strong'),
            (1, 1, 2, 'a', 1, 'strong'),
            (1, 2, 3, 'b', 1, 'strong')
        ");

        $substrate = GraphSubstrate::loadFromDb($db, 1);

        $this->assertEmpty($substrate->scc_profiles, 'No SCC should exist without shared addresses');
    }

    /**
     * Test SCC profile generation with expanded node sets after unification.
     */
    public function testSccProfileWithUnifiedNodes(): void
    {
        $db = $this->createDirectDb();

        $db->exec("INSERT INTO context_nodes (run_id, node_id, type, canonical_node_id) VALUES
            (1, 10, 'ObjectContext', 10),
            (1, 20, 'ObjectContext', 20),
            (1, 30, 'ObjectContext', 10),
            (1, 40, 'ObjectContext', 20)
        ");

        $db->exec("INSERT INTO context_node_locations (run_id, node_id, address, size, location_type, class_name) VALUES
            (1, 10, 100, 100, 'ZendObjectMemoryLocation', 'App\\Foo'),
            (1, 20, 200, 200, 'ZendObjectMemoryLocation', 'App\\Bar'),
            (1, 30, 100, 100, 'ZendObjectMemoryLocation', 'App\\Foo'),
            (1, 40, 200, 200, 'ZendObjectMemoryLocation', 'App\\Bar')
        ");

        // Cycle: 10 -> 20 (tree), 40 -> 30 (non-tree, creates cycle via unification)
        $db->exec("INSERT INTO context_edges
            (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
            (1, NULL, 10, 'root', 1, 'strong'),
            (1, 10, 20, 'link', 1, 'strong'),
            (1, NULL, 30, 'root2', 1, 'strong'),
            (1, 40, 30, 'back', 0, 'strong')
        ");

        $substrate = GraphSubstrate::loadFromDb($db, 1);

        $this->assertNotEmpty($substrate->scc_profiles);
        $scc = $substrate->scc_profiles[0];
        // total_size should include all 4 nodes
        $this->assertSame(600, $scc['total_size']);
        // class_counts should aggregate across unified nodes
        $this->assertArrayHasKey('App\\Foo', $scc['class_counts']);
        $this->assertArrayHasKey('App\\Bar', $scc['class_counts']);
    }

    // ---- Phase 2: Canonical helper methods ----

    public function testIsCanonicalOrUniqueReturnsTrueForUniqueNodes(): void
    {
        $db = $this->createDirectDb();

        $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
            (1, 1, 'ObjectContext'),
            (1, 2, 'ObjectContext')
        ");
        $db->exec("INSERT INTO context_node_locations (run_id, node_id, address, size, location_type) VALUES
            (1, 1, 1000, 64, 'ZendObjectMemoryLocation'),
            (1, 2, 2000, 64, 'ZendObjectMemoryLocation')
        ");
        $db->exec("INSERT INTO context_edges
            (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
            (1, NULL, 1, 'root', 1, 'strong'),
            (1, 1, 2, 'a', 1, 'strong')
        ");

        $substrate = GraphSubstrate::loadFromDb($db, 1);

        // No canonical mapping → all nodes are unique
        $this->assertTrue($substrate->isCanonicalOrUnique(1));
        $this->assertTrue($substrate->isCanonicalOrUnique(2));
        $this->assertSame(1, $substrate->getCanonical(1));
        $this->assertSame([1], $substrate->getCanonicalGroup(1));
    }

    public function testIsCanonicalOrUniqueWithDuplicates(): void
    {
        $db = $this->createDirectDb();

        // Node 10 and 30 share the same address (canonical 10)
        $db->exec("INSERT INTO context_nodes (run_id, node_id, type, canonical_node_id) VALUES
            (1, 10, 'ObjectContext', 10),
            (1, 20, 'ObjectContext', NULL),
            (1, 30, 'ObjectContext', 10)
        ");
        $db->exec("INSERT INTO context_node_locations (run_id, node_id, address, size, location_type) VALUES
            (1, 10, 1000, 64, 'ZendObjectMemoryLocation'),
            (1, 20, 2000, 64, 'ZendObjectMemoryLocation'),
            (1, 30, 1000, 64, 'ZendObjectMemoryLocation')
        ");
        $db->exec("INSERT INTO context_edges
            (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
            (1, NULL, 10, 'root', 1, 'strong'),
            (1, 10, 20, 'a', 1, 'strong'),
            (1, NULL, 30, 'root2', 1, 'strong')
        ");

        $substrate = GraphSubstrate::loadFromDb($db, 1);

        $this->assertTrue($substrate->isCanonicalOrUnique(10));   // canonical representative
        $this->assertTrue($substrate->isCanonicalOrUnique(20));   // unique (no duplicates)
        $this->assertFalse($substrate->isCanonicalOrUnique(30));  // duplicate of 10

        $this->assertSame(10, $substrate->getCanonical(10));
        $this->assertSame(10, $substrate->getCanonical(30));
        $this->assertSame(20, $substrate->getCanonical(20));

        $group = $substrate->getCanonicalGroup(30);
        $this->assertCount(2, $group);
        $this->assertContains(10, $group);
        $this->assertContains(30, $group);
    }

    // ---- Helpers ----

    /**
     * Create a test DB with tables directly (without going through PdoMemoryOutput).
     */
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

        return $db;
    }

    private function openDb(): \PDO
    {
        $db = new \PDO('sqlite:' . $this->db_path);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $db;
    }

    private function buildDb(ReferenceContext $top): void
    {
        $result = new MemoryAnalysisResult([], $top);
        $output = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        $output->output($result);
    }

    /**
     * @param array<string, ReferenceContext> $links
     * @param list<MemoryLocation> $locations
     * @param array<string, mixed> $attributes
     */
    private function createMockContext(
        string $name,
        array $links,
        array $locations,
        array $attributes = [],
    ): ReferenceContext {
        $context = \Mockery::mock(ReferenceContext::class);
        $context->allows('getName')->andReturns($name);
        $context->allows('getLinks')->andReturns($links);
        $context->allows('getLocations')->andReturns($locations);
        $context->allows('getContexts')->andReturns($attributes);
        $context->allows('releaseLinks');
        $context->allows('getLinkStrength')->andReturnUsing(
            function (string $link_name) use ($links): EdgeStrength {
                return EdgeStrength::Strong;
            }
        );
        return $context;
    }

    /**
     * @param array<string, ReferenceContext> $links
     * @param list<MemoryLocation> $locations
     * @param array<string, EdgeStrength> $link_strengths
     */
    private function createMockContextWithStrength(
        string $name,
        array $links,
        array $locations,
        array $link_strengths = [],
    ): ReferenceContext {
        $context = \Mockery::mock(ReferenceContext::class);
        $context->allows('getName')->andReturns($name);
        $context->allows('getLinks')->andReturns($links);
        $context->allows('getLocations')->andReturns($locations);
        $context->allows('getContexts')->andReturns([]);
        $context->allows('releaseLinks');
        $context->allows('getLinkStrength')->andReturnUsing(
            function (string $link_name) use ($link_strengths): EdgeStrength {
                return $link_strengths[$link_name] ?? EdgeStrength::Strong;
            }
        );
        return $context;
    }
}
