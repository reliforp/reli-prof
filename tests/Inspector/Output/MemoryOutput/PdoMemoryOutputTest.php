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

namespace Reli\Inspector\Output\MemoryOutput;

use Reli\BaseTestCase;
use Reli\Inspector\Output\MemoryOutput\PdoDriver\PdoDriverInterface;
use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;
use Reli\Lib\Process\MemoryLocation;

class PdoMemoryOutputTest extends BaseTestCase
{
    private string $db_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db_path = tempnam(sys_get_temp_dir(), 'reli_test_') . '.db';
    }

    protected function tearDown(): void
    {
        @unlink($this->db_path);
        parent::tearDown();
    }

    public function testOutputCreatesAllTables(): void
    {
        $output = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        $output->output($this->createMinimalResult());

        $db = new \PDO('sqlite:' . $this->db_path);
        $tables = $this->getTableNames($db);

        $this->assertContains('runs', $tables);
        $this->assertContains('summary', $tables);
        $this->assertContains('context_nodes', $tables);
        $this->assertContains('context_edges', $tables);
        $this->assertContains('context_node_locations', $tables);
        $this->assertContains('context_node_attributes', $tables);
        $this->assertContains('location_types_summary', $tables);
        $this->assertContains('class_objects_summary', $tables);
    }

    public function testOutputCreatesViews(): void
    {
        $output = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        $output->output($this->createMinimalResult());

        $db = new \PDO('sqlite:' . $this->db_path);
        $views = $this->getViewNames($db);

        $this->assertContains('v_node_paths', $views);
        $this->assertContains('v_arrays', $views);
    }

    public function testOutputCreatesIndexes(): void
    {
        $output = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        $output->output($this->createMinimalResult());

        $db = new \PDO('sqlite:' . $this->db_path);
        $indexes = $this->getIndexNames($db);

        $this->assertContains('idx_context_edges_run_parent_tree', $indexes);
        $this->assertContains('idx_context_edges_run_child', $indexes);
        $this->assertContains('idx_context_node_locations_run_node', $indexes);
        $this->assertContains('idx_context_node_locations_run_class', $indexes);
        $this->assertContains('idx_context_node_attributes_run_node', $indexes);
    }

    public function testOutputInsertsSummaryData(): void
    {
        $output = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        $summary = [
            ['memory_usage' => '1024'],
            ['peak_memory_usage' => '2048'],
        ];
        $output->output($this->createMinimalResult($summary));

        $db = new \PDO('sqlite:' . $this->db_path);
        $rows = $db->query(
            'SELECT "key", "value" FROM summary WHERE run_id = 1 ORDER BY "key"'
        )->fetchAll(\PDO::FETCH_KEY_PAIR);

        $this->assertSame('1024', $rows['memory_usage']);
        $this->assertSame('2048', $rows['peak_memory_usage']);
    }

    public function testOutputInsertsRunRecord(): void
    {
        $output = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        $output->output($this->createMinimalResult());

        $db = new \PDO('sqlite:' . $this->db_path);
        $runs = $db->query('SELECT run_id, created_at FROM runs')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $runs);
        $this->assertEquals(1, $runs[0]['run_id']);
        $this->assertNotEmpty($runs[0]['created_at']);
    }

    public function testMultipleRunsGetDistinctIds(): void
    {
        $output = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        $output->output($this->createMinimalResult());
        $output->output($this->createMinimalResult());

        $db = new \PDO('sqlite:' . $this->db_path);
        $runs = $db->query('SELECT run_id FROM runs ORDER BY run_id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([1, 2], $runs);
    }

    public function testOutputInsertsNodesAndEdges(): void
    {
        // ContextAnalyzer iterates the top context's getLinks(), so we need:
        // top -> "root_link" -> rootNode -> "child_link" -> childNode
        $child = $this->createMockContext('child_type', [], []);
        $root = $this->createMockContext('root_type', ['child_link' => $child], []);
        $top = $this->createMockContext('top', ['root_link' => $root], []);

        $result = new MemoryAnalysisResult([], $top);
        $output = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        $output->output($result);

        $db = new \PDO('sqlite:' . $this->db_path);
        $nodes = $db->query(
            'SELECT run_id, node_id, type FROM context_nodes ORDER BY node_id'
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(2, $nodes);
        $this->assertEquals(1, $nodes[0]['run_id']);
        $this->assertSame('root_type', $nodes[0]['type']);
        $this->assertSame('child_type', $nodes[1]['type']);

        $edges = $db->query(
            'SELECT run_id, parent_node_id, child_node_id, link_name, is_tree'
            . ' FROM context_edges ORDER BY child_node_id'
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(2, $edges);

        // Root node edge (parent=null)
        $root_edge = $edges[0];
        $this->assertEquals(1, $root_edge['run_id']);
        $this->assertNull($root_edge['parent_node_id']);
        $this->assertEquals(0, $root_edge['child_node_id']);
        $this->assertSame('root_link', $root_edge['link_name']);
        $this->assertEquals(1, $root_edge['is_tree']);

        // Child node edge (parent=root)
        $child_edge = $edges[1];
        $this->assertEquals(0, $child_edge['parent_node_id']);
        $this->assertEquals(1, $child_edge['child_node_id']);
        $this->assertSame('child_link', $child_edge['link_name']);
        $this->assertEquals(1, $child_edge['is_tree']);
    }

    public function testOutputInsertsLocations(): void
    {
        $location = new ZendObjectMemoryLocation(
            address: 0x1000,
            size: 64,
            refcount: 1,
            type_info: 7,
            class_name: 'App\\MyClass',
        );
        // Wrap: top -> "entry" -> holder (with location)
        $holder = $this->createMockContext('object_holder', [], [$location]);
        $top = $this->createMockContext('top', ['entry' => $holder], []);

        $result = new MemoryAnalysisResult([], $top);
        $output = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        $output->output($result);

        $db = new \PDO('sqlite:' . $this->db_path);
        $locs = $db->query('SELECT * FROM context_node_locations')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $locs);
        $this->assertEquals(1, $locs[0]['run_id']);
        $this->assertEquals(0, $locs[0]['node_id']);
        $this->assertEquals(4096, $locs[0]['address']); // 0x1000
        $this->assertEquals(64, $locs[0]['size']);
        $this->assertSame('ZendObjectMemoryLocation', $locs[0]['location_type']);
        $this->assertSame('App\\MyClass', $locs[0]['class_name']);
        $this->assertEquals(1, $locs[0]['refcount']);
    }

    public function testOutputInsertsStringLocations(): void
    {
        $location = new ZendStringMemoryLocation(
            address: 0x2000,
            size: 48,
            refcount: 2,
            type_info: 6,
            value: 'hello world',
        );
        $holder = $this->createMockContext('string_holder', [], [$location]);
        $top = $this->createMockContext('top', ['entry' => $holder], []);

        $result = new MemoryAnalysisResult([], $top);
        $output = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        $output->output($result);

        $db = new \PDO('sqlite:' . $this->db_path);
        $locs = $db->query('SELECT * FROM context_node_locations')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $locs);
        $this->assertSame('ZendStringMemoryLocation', $locs[0]['location_type']);
        $this->assertSame('hello world', $locs[0]['string_value']);
        $this->assertNull($locs[0]['class_name']);
    }

    public function testOutputInsertsAttributes(): void
    {
        $holder = $this->createMockContext(
            'node_with_attrs',
            [],
            [],
            ['#count' => '5', '#type' => 'array'],
        );
        $top = $this->createMockContext('top', ['entry' => $holder], []);

        $result = new MemoryAnalysisResult([], $top);
        $output = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        $output->output($result);

        $db = new \PDO('sqlite:' . $this->db_path);
        $attrs = $db->query(
            'SELECT "key", "value" FROM context_node_attributes WHERE run_id = 1 ORDER BY "key"'
        )->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame('5', $attrs['#count']);
        $this->assertSame('array', $attrs['#type']);
    }

    public function testSharedReferenceCreatesNonTreeEdge(): void
    {
        // shared is linked from both parent1 and parent2
        // ContextAnalyzer uses WeakMap: first visit -> is_tree=1, re-visit -> is_tree=0
        $shared = $this->createMockContext('shared_type', [], []);
        $parent1 = $this->createMockContext('parent1', ['link_a' => $shared], []);
        $parent2 = $this->createMockContext('parent2', ['link_b' => $shared], []);
        $top = $this->createMockContext('top', ['p1' => $parent1, 'p2' => $parent2], []);

        $result = new MemoryAnalysisResult([], $top);
        $output = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        $output->output($result);

        $db = new \PDO('sqlite:' . $this->db_path);
        $edges = $db->query('SELECT * FROM context_edges WHERE is_tree = 0')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $edges);
        $this->assertSame('link_b', $edges[0]['link_name']);
        $this->assertEquals(0, $edges[0]['is_tree']);
    }

    public function testNodePathsViewWorks(): void
    {
        $leaf = $this->createMockContext('leaf', [], []);
        $middle = $this->createMockContext('middle', ['to_leaf' => $leaf], []);
        $top = $this->createMockContext('top', ['to_middle' => $middle], []);

        $result = new MemoryAnalysisResult([], $top);
        $output = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        $output->output($result);

        $db = new \PDO('sqlite:' . $this->db_path);
        $paths = $db->query(
            'SELECT run_id, node_id, path, depth FROM v_node_paths ORDER BY depth, node_id'
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(2, $paths);
        // depth 0: to_middle
        $this->assertEquals(1, $paths[0]['run_id']);
        $this->assertEquals(0, $paths[0]['depth']);
        $this->assertSame('to_middle', $paths[0]['path']);
        // depth 1: to_middle -> to_leaf
        $this->assertEquals(1, $paths[1]['depth']);
        $this->assertSame('to_middle -> to_leaf', $paths[1]['path']);
    }

    public function testLocationTypesSummaryPopulated(): void
    {
        $location = new ZendObjectMemoryLocation(
            address: 0x1000,
            size: 128,
            refcount: 1,
            type_info: 7,
            class_name: 'App\\Foo',
        );
        $holder = $this->createMockContext('holder', [], [$location]);
        $top = $this->createMockContext('top', ['entry' => $holder], []);

        $result = new MemoryAnalysisResult([], $top);
        $output = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        $output->output($result);

        $db = new \PDO('sqlite:' . $this->db_path);
        $rows = $db->query(
            'SELECT run_id, "type", "count", memory_usage FROM location_types_summary'
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertEquals(1, $rows[0]['run_id']);
        $this->assertSame('ZendObjectMemoryLocation', $rows[0]['type']);
        $this->assertEquals(1, $rows[0]['count']);
        $this->assertEquals(128, $rows[0]['memory_usage']);
    }

    public function testClassObjectsSummaryPopulated(): void
    {
        $loc1 = new ZendObjectMemoryLocation(0x1000, 64, 1, 7, 'App\\Foo');
        $loc2 = new ZendObjectMemoryLocation(0x2000, 128, 1, 7, 'App\\Foo');
        $holder = $this->createMockContext('holder', [], [$loc1, $loc2]);
        $top = $this->createMockContext('top', ['entry' => $holder], []);

        $result = new MemoryAnalysisResult([], $top);
        $output = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        $output->output($result);

        $db = new \PDO('sqlite:' . $this->db_path);
        $rows = $db->query(
            'SELECT run_id, class_name, "count", memory_usage FROM class_objects_summary'
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertEquals(1, $rows[0]['run_id']);
        $this->assertSame('App\\Foo', $rows[0]['class_name']);
        $this->assertEquals(2, $rows[0]['count']);
        $this->assertEquals(192, $rows[0]['memory_usage']); // 64 + 128
    }

    public function testTransactionRollsBackOnError(): void
    {
        $driver = new SqliteDriver(':memory:');

        // Create a context that will throw during analysis
        $badContext = \Mockery::mock(ReferenceContext::class);
        $badContext->allows('getLinks')->andThrows(new \RuntimeException('test error'));
        $badContext->allows('getName')->andReturns('bad');
        $badContext->allows('getLocations')->andReturns([]);
        $badContext->allows('getContexts')->andReturns([]);
        $badContext->allows('releaseLinks');
        $badContext->allows('getLinkStrength')->andReturns(EdgeStrength::Strong);

        $result = new MemoryAnalysisResult([], $badContext);
        $output = new PdoMemoryOutput($driver);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('test error');
        $output->output($result);
    }

    /**
     * @param array<int, array<string, mixed>> $summary
     */
    private function createMinimalResult(array $summary = []): MemoryAnalysisResult
    {
        $context = $this->createMockContext('empty', [], []);
        return new MemoryAnalysisResult($summary, $context);
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
        $context->allows('getLinkStrength')->andReturns(EdgeStrength::Strong);
        return $context;
    }

    /**
     * @return list<string>
     */
    private function getTableNames(\PDO $db): array
    {
        return $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * @return list<string>
     */
    private function getViewNames(\PDO $db): array
    {
        return $db->query("SELECT name FROM sqlite_master WHERE type='view'")->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * @return list<string>
     */
    private function getIndexNames(\PDO $db): array
    {
        return $db->query("SELECT name FROM sqlite_master WHERE type='index'")->fetchAll(\PDO::FETCH_COLUMN);
    }
}
