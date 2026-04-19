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

namespace Reli\Rmem\Serve;

use PHPUnit\Framework\TestCase;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Inspector\Output\MemoryOutput\BinaryMemoryOutput;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\BinaryContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\Process\MemoryLocation;
use Reli\Rmem\Explore\RmemModel;

class RmemQueryServiceTest extends TestCase
{
    private string $rmemPath = '';
    private RmemQueryService $service;

    #[\Override]
    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'reli_query_test_');
        self::assertNotFalse($path);
        $this->rmemPath = $path . '.rmem';

        // Build a small graph:
        //   -1 (virtual root)
        //     +-- 0: RootContext (App\RootClass, size=80)
        //     |     +-- 1: ObjectContext (App\MyClass, size=100)
        //     |     |     +-- 2: StringContext (size=50, value="hello world")
        //     |     +-- 3: ArrayContext (size=200)
        $sink = new BinaryContextTreeSink(batch_size: 10);

        $loc0 = new ZendObjectMemoryLocation(0x1000, 80, 1, 7, 'App\\RootClass');
        $sink->emitNode(
            node_id: 0,
            parent_node_id: null,
            link_name: 'root_entry',
            type: 'RootContext',
            locations: [$loc0],
            attributes: [],
        );

        $loc1 = new ZendObjectMemoryLocation(0x2000, 100, 2, 7, 'App\\MyClass');
        $sink->emitNode(
            node_id: 1,
            parent_node_id: 0,
            link_name: 'my_object',
            type: 'ObjectContext',
            locations: [$loc1],
            attributes: ['#count' => '3'],
        );

        $loc2 = new ZendStringMemoryLocation(0x3000, 50, 1, 6, 'hello world');
        $sink->emitNode(
            node_id: 2,
            parent_node_id: 1,
            link_name: 'name',
            type: 'StringContext',
            locations: [$loc2],
            attributes: [],
        );

        $loc3 = new ZendArrayMemoryLocation(0x4000, 200, 1, 7);
        $sink->emitNode(
            node_id: 3,
            parent_node_id: 0,
            link_name: 'data_array',
            type: 'ArrayContext',
            locations: [$loc3],
            attributes: [],
        );

        $binaryOutput = new BinaryMemoryOutput($this->rmemPath);
        $binaryOutput->finalizeStreaming($sink, [
            ['zend_mm_heap_usage' => '430', 'php_version' => '8.2.0'],
        ]);

        $reader = BinaryReader::open($this->rmemPath);
        $substrate = GraphSubstrate::loadFromBinary($reader);
        $model = RmemModel::fromSubstrate($substrate, $reader);
        $this->service = new RmemQueryService($model, $this->rmemPath, 'test-server-id');
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (file_exists($this->rmemPath)) {
            @unlink($this->rmemPath);
        }
    }

    public function testServerHello(): void
    {
        $result = $this->service->handle(['action' => 'server.hello']);

        $this->assertTrue($result['ok']);
        $data = $result['data'];
        $this->assertSame(1, $data['protocol_version']);
        $this->assertSame('test-server-id', $data['server_id']);
        $this->assertSame($this->rmemPath, $data['rmem_path']);
        $this->assertSame(4, $data['node_count']);
        // 4 tree edges (one per emitNode) + 0 non-tree
        $this->assertGreaterThanOrEqual(4, $data['edge_count']);
    }

    public function testServerPing(): void
    {
        $result = $this->service->handle(['action' => 'server.ping']);

        $this->assertTrue($result['ok']);
        $this->assertSame('pong', $result['data']);
    }

    public function testQueryRoots(): void
    {
        $result = $this->service->handle(['action' => 'query.roots']);

        $this->assertTrue($result['ok']);
        $this->assertIsArray($result['data']);
        // Node 0 is the only root (parent_node_id=null maps to virtual root -1)
        $this->assertGreaterThanOrEqual(1, count($result['data']));

        // The root node should be present
        $rootIds = array_column($result['data'], 'node_id');
        $this->assertContains(0, $rootIds);

        // Each entry should have required keys
        $first = $result['data'][0];
        $this->assertArrayHasKey('node_id', $first);
        $this->assertArrayHasKey('retained', $first);
        $this->assertArrayHasKey('shallow', $first);
        $this->assertArrayHasKey('label', $first);
    }

    public function testQueryChildren(): void
    {
        // Children of node 0 should be nodes 1 and 3
        $result = $this->service->handle([
            'action' => 'query.children',
            'node_id' => 0,
        ]);

        $this->assertTrue($result['ok']);
        $data = $result['data'];
        $this->assertIsArray($data);

        $childIds = array_column($data, 'node_id');
        $this->assertContains(1, $childIds);
        $this->assertContains(3, $childIds);

        // Default sort is by retained, so first child should have highest retained
        if (count($data) >= 2) {
            $this->assertGreaterThanOrEqual($data[1]['retained'], $data[0]['retained']);
        }

        // Each child entry should have expected keys
        foreach ($data as $entry) {
            $this->assertArrayHasKey('node_id', $entry);
            $this->assertArrayHasKey('retained', $entry);
            $this->assertArrayHasKey('shallow', $entry);
            $this->assertArrayHasKey('link_name', $entry);
            $this->assertArrayHasKey('label', $entry);
        }
    }

    public function testQueryParents(): void
    {
        // Parents of node 1 should include node 0
        $result = $this->service->handle([
            'action' => 'query.parents',
            'node_id' => 1,
        ]);

        $this->assertTrue($result['ok']);
        $data = $result['data'];
        $this->assertIsArray($data);

        $parentIds = array_column($data, 'node_id');
        $this->assertContains(0, $parentIds);
    }

    public function testQueryNodeDetail(): void
    {
        // Detail of node 1 (ObjectContext, App\MyClass, size=100)
        $result = $this->service->handle([
            'action' => 'query.node_detail',
            'node_id' => 1,
        ]);

        $this->assertTrue($result['ok']);
        $detail = $result['data'];

        $this->assertSame(1, $detail['node_id']);
        $this->assertSame('ObjectContext', $detail['type']);
        $this->assertSame('App\\MyClass', $detail['class']);
        $this->assertSame(100, $detail['shallow']);
        // retained = shallow(1) + shallow(2) = 100 + 50 = 150
        $this->assertSame(150, $detail['retained']);
        $this->assertArrayHasKey('label', $detail);
        $this->assertArrayHasKey('address', $detail);
    }

    public function testQueryNodeDetailForString(): void
    {
        // Detail of node 2 (StringContext with string_value)
        $result = $this->service->handle([
            'action' => 'query.node_detail',
            'node_id' => 2,
        ]);

        $this->assertTrue($result['ok']);
        $detail = $result['data'];

        $this->assertSame('StringContext', $detail['type']);
        $this->assertSame(50, $detail['shallow']);
        $this->assertSame('hello world', $detail['string_value']);
    }

    public function testQuerySandwich(): void
    {
        // Sandwich of node 1: parents + detail + children
        $result = $this->service->handle([
            'action' => 'query.sandwich',
            'node_id' => 1,
        ]);

        $this->assertTrue($result['ok']);
        $data = $result['data'];

        // Should have parents, detail, children sections
        $this->assertArrayHasKey('parents', $data);
        $this->assertArrayHasKey('detail', $data);
        $this->assertArrayHasKey('children', $data);

        // Detail section
        $this->assertSame(1, $data['detail']['node_id']);
        $this->assertSame('ObjectContext', $data['detail']['type']);

        // Parents should include node 0
        $parentIds = array_column($data['parents'], 'node_id');
        $this->assertContains(0, $parentIds);

        // Children should include node 2
        $childIds = array_column($data['children'], 'node_id');
        $this->assertContains(2, $childIds);

        // Truncated flags
        $this->assertArrayHasKey('truncated', $result);
        $this->assertFalse($result['truncated']['parents']);
        $this->assertFalse($result['truncated']['children']);
    }

    public function testQueryPathToRoot(): void
    {
        // Path from node 2 to root should be [2, 1, 0]
        $result = $this->service->handle([
            'action' => 'query.path_to_root',
            'node_id' => 2,
        ]);

        $this->assertTrue($result['ok']);
        $path = $result['data'];
        $this->assertIsArray($path);
        $this->assertGreaterThanOrEqual(2, count($path));

        // First element should be node 2 itself
        $this->assertSame(2, $path[0]['node_id']);

        // Path should include the parent chain
        $pathIds = array_column($path, 'node_id');
        $this->assertContains(1, $pathIds);
        $this->assertContains(0, $pathIds);

        // Each entry should have link_name and label
        foreach ($path as $entry) {
            $this->assertArrayHasKey('node_id', $entry);
            $this->assertArrayHasKey('link_name', $entry);
            $this->assertArrayHasKey('label', $entry);
        }
    }

    public function testQueryClassRanking(): void
    {
        $result = $this->service->handle(['action' => 'query.class_ranking']);

        $this->assertTrue($result['ok']);
        $data = $result['data'];
        $this->assertIsArray($data);
        $this->assertGreaterThanOrEqual(1, count($data));

        // App\MyClass and App\RootClass should appear
        $classes = array_column($data, 'class');
        $this->assertContains('App\\MyClass', $classes);
        $this->assertContains('App\\RootClass', $classes);

        // Each entry should have expected keys
        foreach ($data as $entry) {
            $this->assertArrayHasKey('class', $entry);
            $this->assertArrayHasKey('count', $entry);
            $this->assertArrayHasKey('total_shallow', $entry);
            $this->assertArrayHasKey('avg_shallow', $entry);
        }

        // Should be sorted by total_shallow descending
        for ($i = 1; $i < count($data); $i++) {
            $this->assertGreaterThanOrEqual($data[$i]['total_shallow'], $data[$i - 1]['total_shallow']);
        }
    }

    public function testQueryTypeRanking(): void
    {
        $result = $this->service->handle(['action' => 'query.type_ranking']);

        $this->assertTrue($result['ok']);
        $data = $result['data'];
        $this->assertIsArray($data);

        $types = array_column($data, 'type');
        $this->assertContains('ObjectContext', $types);
        $this->assertContains('StringContext', $types);
        $this->assertContains('ArrayContext', $types);
        $this->assertContains('RootContext', $types);

        // Each entry should have expected keys
        foreach ($data as $entry) {
            $this->assertArrayHasKey('type', $entry);
            $this->assertArrayHasKey('count', $entry);
            $this->assertArrayHasKey('total_shallow', $entry);
        }
    }

    public function testQueryTopRetained(): void
    {
        $result = $this->service->handle([
            'action' => 'query.top_retained',
            'limit' => 10,
        ]);

        $this->assertTrue($result['ok']);
        $data = $result['data'];
        $this->assertIsArray($data);
        $this->assertGreaterThanOrEqual(1, count($data));

        // Should be sorted by retained descending
        for ($i = 1; $i < count($data); $i++) {
            $this->assertGreaterThanOrEqual($data[$i]['retained'], $data[$i - 1]['retained']);
        }

        // Node 0 has the highest retained (entire tree: 80+100+50+200=430)
        $this->assertSame(0, $data[0]['node_id']);
    }

    public function testQuerySearch(): void
    {
        // Search by class name
        $result = $this->service->handle([
            'action' => 'query.search',
            'pattern' => 'MyClass',
        ]);

        $this->assertTrue($result['ok']);
        $data = $result['data'];
        $this->assertIsArray($data);
        $this->assertGreaterThanOrEqual(1, count($data));

        // Should find node 1 (App\MyClass)
        $nodeIds = array_column($data, 'node_id');
        $this->assertContains(1, $nodeIds);
    }

    public function testQuerySearchByStringValue(): void
    {
        // Search by string value
        $result = $this->service->handle([
            'action' => 'query.search',
            'pattern' => 'hello',
        ]);

        $this->assertTrue($result['ok']);
        $data = $result['data'];
        $this->assertIsArray($data);
        $this->assertGreaterThanOrEqual(1, count($data));

        // Should find node 2 (string "hello world")
        $nodeIds = array_column($data, 'node_id');
        $this->assertContains(2, $nodeIds);
    }

    public function testQuerySearchMissingPattern(): void
    {
        $result = $this->service->handle([
            'action' => 'query.search',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testQueryNodesByClass(): void
    {
        $result = $this->service->handle([
            'action' => 'query.nodes_by_class',
            'class' => 'App\\MyClass',
        ]);

        $this->assertTrue($result['ok']);
        $data = $result['data'];
        $this->assertIsArray($data);
        $this->assertGreaterThanOrEqual(1, count($data));

        // All returned nodes should be node 1
        $nodeIds = array_column($data, 'node_id');
        $this->assertContains(1, $nodeIds);
    }

    public function testQueryNodesByClassMissingParam(): void
    {
        $result = $this->service->handle([
            'action' => 'query.nodes_by_class',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testUnknownAction(): void
    {
        $result = $this->service->handle(['action' => 'nonexistent.action']);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Unknown action', $result['error']);
    }

    public function testEmptyAction(): void
    {
        $result = $this->service->handle([]);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testPaginationTruncated(): void
    {
        // Request with limit=1 to trigger truncation on roots
        $result = $this->service->handle([
            'action' => 'query.children',
            'node_id' => 0,
            'limit' => 1,
        ]);

        $this->assertTrue($result['ok']);
        // Node 0 has 2 children (nodes 1 and 3), limit=1 should truncate
        $this->assertCount(1, $result['data']);
        $this->assertTrue($result['truncated']);
        $this->assertGreaterThanOrEqual(2, $result['total_count']);
        $this->assertSame(1, $result['limit']);
    }

    public function testPaginationNotTruncated(): void
    {
        // Request with large limit should not truncate
        $result = $this->service->handle([
            'action' => 'query.children',
            'node_id' => 0,
            'limit' => 1000,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['truncated']);
    }

    public function testQueryNodesByType(): void
    {
        $result = $this->service->handle([
            'action' => 'query.nodes_by_type',
            'type' => 'ObjectContext',
        ]);

        $this->assertTrue($result['ok']);
        $data = $result['data'];
        $this->assertIsArray($data);
        // Nodes 0 (RootContext is not ObjectContext) and 1 (ObjectContext)
        // Only node 1 should match
        $nodeIds = array_column($data, 'node_id');
        $this->assertContains(1, $nodeIds);
    }

    public function testQuerySubtreeStats(): void
    {
        $result = $this->service->handle([
            'action' => 'query.subtree_stats',
            'node_id' => 0,
        ]);

        $this->assertTrue($result['ok']);
        $data = $result['data'];

        $this->assertArrayHasKey('total_retained', $data);
        $this->assertArrayHasKey('node_count', $data);
        $this->assertArrayHasKey('type_breakdown', $data);
        $this->assertArrayHasKey('class_breakdown', $data);

        // Subtree of node 0 includes nodes 0, 1, 2, 3
        // total = 80 + 100 + 50 + 200 = 430
        $this->assertSame(430, $data['total_retained']);
        $this->assertSame(4, $data['node_count']);
        $this->assertFalse($data['truncated']);
    }

    public function testQueryFindByAddress(): void
    {
        // Node 1 was emitted at address 0x2000
        $result = $this->service->handle([
            'action' => 'query.find_by_address',
            'address' => '0x2000',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['data']['node_id']);
    }

    public function testQueryFindByAddressNotFound(): void
    {
        $result = $this->service->handle([
            'action' => 'query.find_by_address',
            'address' => '0xDEAD',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testSandwichTruncation(): void
    {
        // Request sandwich with limit=1 to trigger truncation on children
        $result = $this->service->handle([
            'action' => 'query.sandwich',
            'node_id' => 0,
            'limit' => 1,
        ]);

        $this->assertTrue($result['ok']);
        // Node 0 has 2 children, limit=1 should truncate children
        $this->assertCount(1, $result['data']['children']);
        $this->assertTrue($result['truncated']['children']);
    }
}
