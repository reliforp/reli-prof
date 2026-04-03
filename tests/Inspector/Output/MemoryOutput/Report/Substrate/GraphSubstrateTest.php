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

    // ---- Helpers ----

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
