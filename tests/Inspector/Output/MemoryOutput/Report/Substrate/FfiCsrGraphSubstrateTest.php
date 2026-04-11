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

class FfiCsrGraphSubstrateTest extends BaseTestCase
{
    private string $db_path;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('ffi')) {
            $this->markTestSkipped('FFI extension not available');
        }
        $this->db_path = tempnam(sys_get_temp_dir(), 'reli_ffi_csr_test_') . '.db';
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
        $substrate = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        $this->assertInstanceOf(FfiCsrGraphSubstrate::class, $substrate);
        // Check via accessor method
        $found128 = false;
        foreach ($substrate->iterateNodeSizes() as $size) {
            if ($size === 128) {
                $found128 = true;
            }
        }
        $this->assertTrue($found128);
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
        $substrate = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        $this->assertTrue($substrate->hasSubtreeSizes());

        // Parent's subtree should include child: 100 + 200 = 300
        $max_subtree = 0;
        foreach ($substrate->iterateSubtreeSizes() as $size) {
            $max_subtree = max($max_subtree, $size);
        }
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
        $substrate = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        $this->assertNotEmpty($substrate->getRoots());
    }

    public function testSccDetectsNoCyclesInTree(): void
    {
        $leaf = $this->createMockContext('leaf', [], [
            new ZendObjectMemoryLocation(0x2000, 64, 1, 7, 'App\\Node'),
        ]);
        $top = $this->createMockContext('top', ['root' => $leaf], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        $this->assertEmpty($substrate->getSccProfiles());
        $sccEntries = iterator_to_array($substrate->iterateNodeToScc());
        $this->assertEmpty($sccEntries);
    }

    public function testEdgeCountTracked(): void
    {
        $child = $this->createMockContext('child', [], [
            new ZendObjectMemoryLocation(0x2000, 64, 1, 7, 'App\\Node'),
        ]);
        $top = $this->createMockContext('top', ['root' => $child], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        $this->assertGreaterThan(0, $substrate->getEdgeCount());
    }

    public function testNodeClassesPopulated(): void
    {
        $child = $this->createMockContext('child', [], [
            new ZendObjectMemoryLocation(0x2000, 64, 1, 7, 'App\\MyClass'),
        ]);
        $top = $this->createMockContext('top', ['root' => $child], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        $found = false;
        foreach ($substrate->iterateNodeClasses() as $cls) {
            if ($cls === 'App\\MyClass') {
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    public function testGetChildrenReturnsCorrectChildren(): void
    {
        $grandchild = $this->createMockContext('grandchild', [], [
            new ZendObjectMemoryLocation(0x3000, 32, 1, 7, 'App\\GC'),
        ]);
        $child = $this->createMockContext('child', ['gc_link' => $grandchild], [
            new ZendObjectMemoryLocation(0x2000, 64, 1, 7, 'App\\Child'),
        ]);
        $parent = $this->createMockContext('parent', ['child_link' => $child], [
            new ZendObjectMemoryLocation(0x1000, 128, 1, 7, 'App\\Parent'),
        ]);
        $top = $this->createMockContext('top', ['root' => $parent], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        // Find the parent node and check it has children
        $parent_node_id = null;
        foreach ($substrate->iterateNodeClasses() as $nid => $cls) {
            if ($cls === 'App\\Parent') {
                $parent_node_id = $nid;
                break;
            }
        }
        $this->assertNotNull($parent_node_id);
        $children = $substrate->getChildren($parent_node_id);
        $this->assertNotEmpty($children);
    }

    public function testGetNodeSizeReturnsZeroForUnknownNode(): void
    {
        $child = $this->createMockContext('child', [], [
            new ZendObjectMemoryLocation(0x2000, 64, 1, 7, 'App\\Node'),
        ]);
        $top = $this->createMockContext('top', ['root' => $child], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        $this->assertSame(0, $substrate->getNodeSize(999999999));
    }

    public function testGetNodeSizesSumMatchesIteration(): void
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
        $substrate = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        $iterSum = 0;
        foreach ($substrate->iterateNodeSizes() as $size) {
            $iterSum += $size;
        }
        $this->assertSame($iterSum, $substrate->getNodeSizesSum());
    }

    public function testConsistencyWithPhpArrayVersion(): void
    {
        $child1 = $this->createMockContext('child1', [], [
            new ZendObjectMemoryLocation(0x3000, 50, 1, 7, 'App\\A'),
        ]);
        $child2 = $this->createMockContext('child2', [], [
            new ZendObjectMemoryLocation(0x4000, 75, 1, 7, 'App\\B'),
        ]);
        $parent = $this->createMockContext('parent', [
            'link_a' => $child1,
            'link_b' => $child2,
        ], [
            new ZendObjectMemoryLocation(0x1000, 100, 1, 7, 'App\\Parent'),
        ]);
        $top = $this->createMockContext('top', ['root' => $parent], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $php = GraphSubstrate::loadFromDb($db, 1);
        $ffi = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        // Compare roots
        $this->assertEqualsCanonicalizing($php->getRoots(), $ffi->getRoots());

        // Compare edge counts
        $this->assertSame($php->getEdgeCount(), $ffi->getEdgeCount());

        // Compare node sizes sum
        $this->assertSame($php->getNodeSizesSum(), $ffi->getNodeSizesSum());

        // Compare subtree sizes for all nodes
        foreach ($php->iterateSubtreeSizes() as $nid => $size) {
            $this->assertSame(
                $size,
                $ffi->getSubtreeSize($nid),
                "Subtree size mismatch for node {$nid}"
            );
        }

        // Compare SCC profiles count
        $this->assertSame(
            count($php->getSccProfiles()),
            count($ffi->getSccProfiles())
        );
    }

    public function testConsistencyWithPhpArrayVersionForUnifiedScc(): void
    {
        $db = $this->createDirectDb();

        $db->exec("INSERT INTO context_nodes (run_id, node_id, type, canonical_node_id) VALUES
            (1, 100, 'ObjectContext', 100),
            (1, 200, 'ObjectContext', 200),
            (1, 500, 'ObjectContext', 100),
            (1, 600, 'ObjectContext', 200)
        ");

        $db->exec("INSERT INTO context_node_locations (run_id, node_id, address, size, location_type, class_name) VALUES
            (1, 100, 1000, 64, 'ZendObjectMemoryLocation', 'App\\NodeA'),
            (1, 200, 2000, 64, 'ZendObjectMemoryLocation', 'App\\NodeB'),
            (1, 500, 1000, 64, 'ZendObjectMemoryLocation', 'App\\NodeA'),
            (1, 600, 2000, 64, 'ZendObjectMemoryLocation', 'App\\NodeB')
        ");

        $db->exec("INSERT INTO context_edges
            (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
            (1, NULL, 100, 'objects_store', 1, 'strong'),
            (1, 100, 200, 'next', 1, 'strong'),
            (1, NULL, 500, 'call_frames', 1, 'strong'),
            (1, 600, 500, 'next', 0, 'strong')
        ");

        $php = GraphSubstrate::loadFromDb($db, 1);
        $ffi = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        $this->assertSame(count($php->getSccProfiles()), count($ffi->getSccProfiles()));
        $this->assertNotEmpty($ffi->getSccProfiles());

        $php_scc = $php->getSccProfiles()[0];
        $ffi_scc = $ffi->getSccProfiles()[0];

        $this->assertEqualsCanonicalizing($php_scc['nodes'], $ffi_scc['nodes']);
        $this->assertSame($php_scc['node_count'], $ffi_scc['node_count']);
        $this->assertSame($php_scc['total_size'], $ffi_scc['total_size']);
        $this->assertSame($php_scc['ext_in'], $ffi_scc['ext_in']);
        $this->assertSame($php_scc['ext_out'], $ffi_scc['ext_out']);
    }

    public function testCreateFromDbSelectsCorrectImplementation(): void
    {
        $child = $this->createMockContext('child', [], [
            new ZendObjectMemoryLocation(0x2000, 64, 1, 7, 'App\\Node'),
        ]);
        $top = $this->createMockContext('top', ['root' => $child], []);
        $this->buildDb($top);

        $db = $this->openDb();
        // Small graph should use PHP array version
        $substrate = GraphSubstrate::createFromDb($db, 1);
        $this->assertInstanceOf(GraphSubstrate::class, $substrate);
        // With small data, it should NOT be FfiCsr
        $this->assertNotInstanceOf(FfiCsrGraphSubstrate::class, $substrate);
    }

    // ---- Helpers ----

    private function openDb(): \PDO
    {
        $db = new \PDO('sqlite:' . $this->db_path);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $db;
    }

    private function createDirectDb(): \PDO
    {
        $db = $this->openDb();

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

    public function testGetAllParentsReturnsRealParentNodeIds(): void
    {
        $db = $this->openManualDb();

        // Linear chain: NULL -> 1 -> 2 -> 3
        $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
            (1, 1, 'a'), (1, 2, 'a'), (1, 3, 'a')");
        $db->exec("INSERT INTO context_node_locations
            (run_id, node_id, address, size, location_type, class_name) VALUES
            (1, 1, 0, 10, 'ZendObjectMemoryLocation', 'C'),
            (1, 2, 0, 10, 'ZendObjectMemoryLocation', 'C'),
            (1, 3, 0, 10, 'ZendObjectMemoryLocation', 'C')");
        $db->exec("INSERT INTO context_edges
            (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
            (1, NULL, 1, 'r', 1, 'strong'),
            (1,    1, 2, 'l', 1, 'strong'),
            (1,    2, 3, 'l', 1, 'strong')");

        $substrate = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        // The previous implementation stored raw parent node_ids in
        // revEdges and read them back through indexToNodeFfi as if
        // they were CSR indices, so non-root nodes silently returned
        // wrong parents and the actual root crashed with an FFI
        // out-of-bounds. Pin both behaviours.
        $this->assertSame([1], $substrate->getAllParents(2));
        $this->assertSame([2], $substrate->getAllParents(3));
        // Root parent slot is the synthetic -1 sentinel.
        $this->assertSame([-1], $substrate->getAllParents(1));
    }

    public function testGetTreeLinkNameAndParentReturnInternedValues(): void
    {
        $db = $this->openManualDb();

        $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
            (1, 1, 'a'), (1, 2, 'a'), (1, 3, 'a')");
        $db->exec("INSERT INTO context_node_locations
            (run_id, node_id, address, size, location_type, class_name) VALUES
            (1, 1, 0, 10, 'ZendObjectMemoryLocation', 'C'),
            (1, 2, 0, 10, 'ZendObjectMemoryLocation', 'C'),
            (1, 3, 0, 10, 'ZendObjectMemoryLocation', 'C')");
        $db->exec("INSERT INTO context_edges
            (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
            (1, NULL, 1, 'objects_store', 1, 'strong'),
            (1,    1, 2, 'object_properties', 1, 'strong'),
            (1,    2, 3, 'name', 1, 'strong')");

        $substrate = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        $this->assertTrue($substrate->hasTreeLinkIndex());
        $this->assertSame('objects_store', $substrate->getTreeLinkName(1));
        $this->assertSame('object_properties', $substrate->getTreeLinkName(2));
        $this->assertSame('name', $substrate->getTreeLinkName(3));
        $this->assertNull($substrate->getTreeLinkName(999));

        // The synthetic root has no parent.
        $this->assertNull($substrate->getTreeParentNodeId(1));
        $this->assertSame(1, $substrate->getTreeParentNodeId(2));
        $this->assertSame(2, $substrate->getTreeParentNodeId(3));
    }

    public function testTreeLinkIndexUsesInternDictForRepeats(): void
    {
        $db = $this->openManualDb();

        // Many siblings sharing a single link_name string.
        $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
            (1, 1, 'a'), (1, 2, 'a'), (1, 3, 'a'), (1, 4, 'a'), (1, 5, 'a')");
        $db->exec("INSERT INTO context_node_locations
            (run_id, node_id, address, size, location_type, class_name) VALUES
            (1, 1, 0, 10, 'ZendObjectMemoryLocation', 'C'),
            (1, 2, 0, 10, 'ZendObjectMemoryLocation', 'C'),
            (1, 3, 0, 10, 'ZendObjectMemoryLocation', 'C'),
            (1, 4, 0, 10, 'ZendObjectMemoryLocation', 'C'),
            (1, 5, 0, 10, 'ZendObjectMemoryLocation', 'C')");
        $db->exec("INSERT INTO context_edges
            (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
            (1, NULL, 1, 'r', 1, 'strong'),
            (1,    1, 2, 'array_elements', 1, 'strong'),
            (1,    1, 3, 'array_elements', 1, 'strong'),
            (1,    1, 4, 'array_elements', 1, 'strong'),
            (1,    1, 5, 'array_elements', 1, 'strong')");

        $substrate = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        $this->assertSame('array_elements', $substrate->getTreeLinkName(2));
        $this->assertSame('array_elements', $substrate->getTreeLinkName(3));
        $this->assertSame('array_elements', $substrate->getTreeLinkName(4));
        $this->assertSame('array_elements', $substrate->getTreeLinkName(5));
        $this->assertSame('r', $substrate->getTreeLinkName(1));
    }

    public function testIterateAllParentsMatchesGetAllParents(): void
    {
        $db = $this->openManualDb();

        // Diamond: 1 -> {2, 3} -> 4 (4 has two parents)
        $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
            (1, 1, 'a'), (1, 2, 'a'), (1, 3, 'a'), (1, 4, 'a')");
        $db->exec("INSERT INTO context_node_locations
            (run_id, node_id, address, size, location_type, class_name) VALUES
            (1, 1, 0, 10, 'ZendObjectMemoryLocation', 'C'),
            (1, 2, 0, 10, 'ZendObjectMemoryLocation', 'C'),
            (1, 3, 0, 10, 'ZendObjectMemoryLocation', 'C'),
            (1, 4, 0, 10, 'ZendObjectMemoryLocation', 'C')");
        $db->exec("INSERT INTO context_edges
            (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
            (1, NULL, 1, 'r', 1, 'strong'),
            (1,    1, 2, 'a', 1, 'strong'),
            (1,    1, 3, 'b', 1, 'strong'),
            (1,    2, 4, 'c', 1, 'strong'),
            (1,    3, 4, 'd', 0, 'strong')");

        $substrate = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        $parents_via_iterator = [];
        foreach ($substrate->iterateAllParents() as $child => $parents) {
            sort($parents);
            $parents_via_iterator[$child] = $parents;
        }
        $this->assertSame([1], $parents_via_iterator[2] ?? null);
        $this->assertSame([1], $parents_via_iterator[3] ?? null);
        $this->assertSame([2, 3], $parents_via_iterator[4] ?? null);

        $direct_parents_4 = $substrate->getAllParents(4);
        sort($direct_parents_4);
        $this->assertSame([2, 3], $direct_parents_4);
    }

    private function openManualDb(): \PDO
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('
            CREATE TABLE context_nodes (
                run_id INTEGER NOT NULL,
                node_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                canonical_node_id INTEGER,
                PRIMARY KEY (run_id, node_id)
            )');
        $db->exec("
            CREATE TABLE context_edges (
                id INTEGER PRIMARY KEY,
                run_id INTEGER NOT NULL,
                parent_node_id INTEGER,
                child_node_id INTEGER NOT NULL,
                link_name TEXT NOT NULL,
                is_tree INTEGER NOT NULL,
                strength TEXT NOT NULL DEFAULT 'strong'
            )");
        $db->exec('
            CREATE TABLE context_node_locations (
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
            )');
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
        $memo = null;
        $context->allows('getMemoNodeId')->andReturnUsing(
            static function () use (&$memo): ?int {
                return $memo;
            }
        );
        $context->allows('setMemoNodeId')->andReturnUsing(
            static function (?int $id) use (&$memo): void {
                $memo = $id;
            }
        );
        $context->allows('getName')->andReturns($name);
        $context->allows('getLinks')->andReturns($links);
        $context->allows('getLocations')->andReturns($locations);
        $context->allows('getContexts')->andReturns($attributes);
        $context->allows('releaseLinks');
        $context->allows('getLinkStrength')->andReturns(EdgeStrength::Strong);
        return $context;
    }
}
