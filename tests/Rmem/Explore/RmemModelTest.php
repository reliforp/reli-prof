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

namespace Reli\Rmem\Explore;

use PHPUnit\Framework\TestCase;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Inspector\Output\MemoryOutput\BinaryMemoryOutput;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\BinaryContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\Process\MemoryLocation;

class RmemModelTest extends TestCase
{
    private string $rmem_path = '';

    /**
     * Graph topology built in setUp:
     *
     *   node 1 (root, ObjectContext, App\Root, size=100, addr=0x1000, rc=1)
     *     +-- node 2 (ObjectContext, App\Child, size=200, addr=0x2000, rc=2)
     *     |     +-- node 4 (StringContext, value="hello world", size=48, addr=0x4000, rc=1)
     *     +-- node 3 (ArrayContext, size=56, addr=0x3000, rc=1)
     *           +-- node 5 (ObjectContext, App\Child, size=80, addr=0x5000, rc=1)
     *
     * Non-tree reference: node 5 -> node 4 (backref)
     */
    #[\Override]
    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'reli_model_test_');
        self::assertNotFalse($path);
        $this->rmem_path = $path . '.rmem';

        $sink = new BinaryContextTreeSink(batch_size: 10);

        // Node 1: root object
        $sink->emitNode(
            node_id: 1,
            parent_node_id: null,
            link_name: 'root_entry',
            type: 'ObjectContext',
            locations: [new ZendObjectMemoryLocation(
                address: 0x1000,
                size: 100,
                refcount: 1,
                type_info: 7,
                class_name: 'App\\Root',
            )],
            attributes: [],
        );

        // Node 2: child object of node 1
        $sink->emitNode(
            node_id: 2,
            parent_node_id: 1,
            link_name: 'child_obj',
            type: 'ObjectContext',
            locations: [new ZendObjectMemoryLocation(
                address: 0x2000,
                size: 200,
                refcount: 2,
                type_info: 7,
                class_name: 'App\\Child',
            )],
            attributes: [],
        );

        // Node 3: array child of node 1
        $sink->emitNode(
            node_id: 3,
            parent_node_id: 1,
            link_name: 'child_arr',
            type: 'ArrayContext',
            locations: [new ZendArrayMemoryLocation(
                address: 0x3000,
                size: 56,
                refcount: 1,
                type_info: 7,
            )],
            attributes: [],
        );

        // Node 4: string child of node 2
        $sink->emitNode(
            node_id: 4,
            parent_node_id: 2,
            link_name: 'str_prop',
            type: 'StringContext',
            locations: [new ZendStringMemoryLocation(
                address: 0x4000,
                size: 48,
                refcount: 1,
                type_info: 6,
                value: 'hello world',
            )],
            attributes: [],
        );

        // Node 5: object child of node 3
        $sink->emitNode(
            node_id: 5,
            parent_node_id: 3,
            link_name: 'element_0',
            type: 'ObjectContext',
            locations: [new ZendObjectMemoryLocation(
                address: 0x5000,
                size: 80,
                refcount: 1,
                type_info: 7,
                class_name: 'App\\Child',
            )],
            attributes: [],
        );

        // Non-tree reference: node 5 -> node 4
        $sink->emitReference(
            reference_node_id: 4,
            parent_node_id: 5,
            link_name: 'backref',
        );

        $binary_output = new BinaryMemoryOutput($this->rmem_path);
        $binary_output->finalizeStreaming($sink, [
            ['zend_mm_heap_usage' => '484', 'php_version' => '8.2.0'],
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (file_exists($this->rmem_path)) {
            @unlink($this->rmem_path);
        }
    }

    private function createModel(): RmemModel
    {
        $reader = BinaryReader::open($this->rmem_path);
        $substrate = GraphSubstrate::createFromBinary($reader, forceFfiCsr: false, skipScc: true);
        return RmemModel::fromSubstrate($substrate, $reader);
    }

    public function testNodeCountAndEdgeCount(): void
    {
        $model = $this->createModel();

        $this->assertSame(5, $model->nodeCount);
        // 5 tree edges (including root-to-sentinel) + 1 non-tree reference = 6
        $this->assertSame(6, $model->edgeCount);
    }

    public function testGetRootChildren(): void
    {
        $model = $this->createModel();

        $roots = $model->getRootChildren();
        $this->assertCount(1, $roots);

        $root = $roots[0];
        $this->assertSame(1, $root['node_id']);
        // retained = sum of all nodes = 100 + 200 + 56 + 48 + 80 = 484
        $this->assertSame(484, $root['retained']);
        $this->assertSame(100, $root['shallow']);
        $this->assertArrayHasKey('label', $root);
        $this->assertArrayHasKey('link_name', $root);
    }

    public function testGetChildrenSortedByRetained(): void
    {
        $model = $this->createModel();

        $children = $model->getChildren(1);
        $this->assertCount(2, $children);

        // Node 2 (retained=248: 200+48) should come before node 3 (retained=136: 56+80)
        $this->assertSame(2, $children[0]['node_id']);
        $this->assertSame(3, $children[1]['node_id']);

        // Verify structure
        foreach ($children as $child) {
            $this->assertArrayHasKey('node_id', $child);
            $this->assertArrayHasKey('retained', $child);
            $this->assertArrayHasKey('shallow', $child);
            $this->assertArrayHasKey('link_name', $child);
            $this->assertArrayHasKey('label', $child);
        }
    }

    public function testGetChildrenOfLeafNode(): void
    {
        $model = $this->createModel();

        $children = $model->getChildren(4);
        $this->assertCount(0, $children);
    }

    public function testGetParents(): void
    {
        $model = $this->createModel();

        // Node 2's tree parent is node 1
        $parents = $model->getParents(2);
        $this->assertNotEmpty($parents);

        $parentIds = array_column($parents, 'node_id');
        $this->assertContains(1, $parentIds);

        // Each parent entry has expected keys
        foreach ($parents as $parent) {
            $this->assertArrayHasKey('node_id', $parent);
            $this->assertArrayHasKey('retained', $parent);
            $this->assertArrayHasKey('shallow', $parent);
            $this->assertArrayHasKey('link_name', $parent);
            $this->assertArrayHasKey('label', $parent);
        }
    }

    public function testGetParentsWithNonTreeEdge(): void
    {
        $model = $this->createModel();

        // Node 4 has tree parent 2 and non-tree parent 5
        $parents = $model->getParents(4);
        $parentIds = array_column($parents, 'node_id');
        $this->assertContains(2, $parentIds);
        $this->assertContains(5, $parentIds);
    }

    public function testNodeDetail(): void
    {
        $model = $this->createModel();

        // Object node with class
        $detail = $model->nodeDetail(1);
        $this->assertSame('ObjectContext', $detail['type']);
        $this->assertSame('App\\Root', $detail['class']);
        $this->assertSame(100, $detail['shallow']);
        $this->assertSame(484, $detail['retained']);
        $this->assertSame(0x1000, $detail['address']);
        $this->assertSame(1, $detail['refcount']);
        $this->assertNull($detail['string_value']);

        // String node with value
        $detail4 = $model->nodeDetail(4);
        $this->assertSame('StringContext', $detail4['type']);
        $this->assertSame(48, $detail4['shallow']);
        $this->assertSame(0x4000, $detail4['address']);
        $this->assertSame('hello world', $detail4['string_value']);
        $this->assertSame(1, $detail4['refcount']);
    }

    public function testNodeDetailRefcount(): void
    {
        $model = $this->createModel();

        $detail2 = $model->nodeDetail(2);
        $this->assertSame(2, $detail2['refcount']);
    }

    public function testNodeLabel(): void
    {
        $model = $this->createModel();

        // Object node label should include class name
        $label1 = $model->nodeLabel(1);
        $this->assertStringContainsString('App\\Root', $label1);

        // String node label should include the string value once location info is loaded
        $model->ensureLocationInfoLoaded();
        $label4 = $model->nodeLabel(4);
        $this->assertStringContainsString('hello world', $label4);
    }

    public function testPathToRoot(): void
    {
        $model = $this->createModel();

        // Path from node 4 to root: 4 -> 2 -> 1
        $path = $model->pathToRoot(4);
        $this->assertGreaterThanOrEqual(3, count($path));

        $nodeIds = array_column($path, 'node_id');
        $this->assertSame(4, $nodeIds[0]); // starts at the target node
        $this->assertContains(2, $nodeIds);
        $this->assertContains(1, $nodeIds);

        // Each entry has expected keys
        foreach ($path as $entry) {
            $this->assertArrayHasKey('node_id', $entry);
            $this->assertArrayHasKey('link_name', $entry);
            $this->assertArrayHasKey('label', $entry);
        }
    }

    public function testPathToRootFromRoot(): void
    {
        $model = $this->createModel();

        $path = $model->pathToRoot(1);
        // Root node is the only entry
        $this->assertSame(1, $path[0]['node_id']);
    }

    public function testGetClassRanking(): void
    {
        $model = $this->createModel();

        $ranking = $model->getClassRanking();
        $this->assertNotEmpty($ranking);

        // Should have App\Child (2 instances: nodes 2 and 5) and App\Root (1 instance)
        $classNames = array_column($ranking, 'class');
        $this->assertContains('App\\Child', $classNames);
        $this->assertContains('App\\Root', $classNames);

        // Sorted by total_shallow descending
        for ($i = 1; $i < count($ranking); $i++) {
            $this->assertGreaterThanOrEqual(
                $ranking[$i]['total_shallow'],
                $ranking[$i - 1]['total_shallow'],
            );
        }

        // App\Child: 200 + 80 = 280
        $childIdx = array_search('App\\Child', $classNames);
        self::assertNotFalse($childIdx);
        $this->assertSame(2, $ranking[$childIdx]['count']);
        $this->assertSame(280, $ranking[$childIdx]['total_shallow']);
        $this->assertSame(140, $ranking[$childIdx]['avg_shallow']);
    }

    public function testGetTypeRanking(): void
    {
        $model = $this->createModel();

        $ranking = $model->getTypeRanking();
        $this->assertNotEmpty($ranking);

        $typeNames = array_column($ranking, 'type');
        $this->assertContains('ObjectContext', $typeNames);
        $this->assertContains('StringContext', $typeNames);
        $this->assertContains('ArrayContext', $typeNames);

        // Sorted by total_shallow descending
        for ($i = 1; $i < count($ranking); $i++) {
            $this->assertGreaterThanOrEqual(
                $ranking[$i]['total_shallow'],
                $ranking[$i - 1]['total_shallow'],
            );
        }

        // ObjectContext: 3 nodes (1, 2, 5) with sizes 100+200+80 = 380
        $objIdx = array_search('ObjectContext', $typeNames);
        self::assertNotFalse($objIdx);
        $this->assertSame(3, $ranking[$objIdx]['count']);
        $this->assertSame(380, $ranking[$objIdx]['total_shallow']);
    }

    public function testGetTopRetained(): void
    {
        $model = $this->createModel();

        $top = $model->getTopRetained(3);
        $this->assertCount(3, $top);

        // Should be sorted by retained descending
        $this->assertGreaterThanOrEqual($top[1]['retained'], $top[0]['retained']);
        $this->assertGreaterThanOrEqual($top[2]['retained'], $top[1]['retained']);

        // The top entry should be node 1 (retained=484)
        $this->assertSame(1, $top[0]['node_id']);
        $this->assertSame(484, $top[0]['retained']);

        // Each entry has expected keys
        foreach ($top as $entry) {
            $this->assertArrayHasKey('node_id', $entry);
            $this->assertArrayHasKey('retained', $entry);
            $this->assertArrayHasKey('shallow', $entry);
            $this->assertArrayHasKey('label', $entry);
        }
    }

    public function testGetTopRetainedWithLimitExceedingNodeCount(): void
    {
        $model = $this->createModel();

        $top = $model->getTopRetained(100);
        // Should return all nodes with retained > 0
        $this->assertLessThanOrEqual(5, count($top));
        $this->assertGreaterThan(0, count($top));
    }

    public function testGetNodesByClass(): void
    {
        $model = $this->createModel();

        $nodes = $model->getNodesByClass('App\\Child');
        $this->assertCount(2, $nodes);

        // Sorted by retained descending
        $this->assertGreaterThanOrEqual($nodes[1]['retained'], $nodes[0]['retained']);

        $nodeIds = array_column($nodes, 'node_id');
        $this->assertContains(2, $nodeIds);
        $this->assertContains(5, $nodeIds);
    }

    public function testGetNodesByClassNonExistent(): void
    {
        $model = $this->createModel();

        $nodes = $model->getNodesByClass('NonExistent\\Class');
        $this->assertCount(0, $nodes);
    }

    public function testGlobalSearchByClassName(): void
    {
        $model = $this->createModel();

        $results = $model->globalSearch('App\\Root');
        $this->assertNotEmpty($results);

        $nodeIds = array_column($results, 'node_id');
        $this->assertContains(1, $nodeIds);
    }

    public function testGlobalSearchByStringValue(): void
    {
        $model = $this->createModel();

        $results = $model->globalSearch('hello');
        $this->assertNotEmpty($results);

        $nodeIds = array_column($results, 'node_id');
        $this->assertContains(4, $nodeIds);
    }

    public function testGlobalSearchCaseInsensitive(): void
    {
        $model = $this->createModel();

        $results = $model->globalSearch('HELLO');
        $this->assertNotEmpty($results);

        $nodeIds = array_column($results, 'node_id');
        $this->assertContains(4, $nodeIds);
    }

    public function testGlobalSearchNoMatch(): void
    {
        $model = $this->createModel();

        $results = $model->globalSearch('zzz_nonexistent_zzz');
        $this->assertCount(0, $results);
    }

    public function testGlobalSearchResultStructure(): void
    {
        $model = $this->createModel();

        $results = $model->globalSearch('App');
        foreach ($results as $result) {
            $this->assertArrayHasKey('node_id', $result);
            $this->assertArrayHasKey('retained', $result);
            $this->assertArrayHasKey('shallow', $result);
            $this->assertArrayHasKey('label', $result);
            $this->assertArrayHasKey('match_field', $result);
        }
    }

    public function testFindNodeByAddress(): void
    {
        $model = $this->createModel();

        $this->assertSame(1, $model->findNodeByAddress(0x1000));
        $this->assertSame(2, $model->findNodeByAddress(0x2000));
        $this->assertSame(4, $model->findNodeByAddress(0x4000));
        $this->assertNull($model->findNodeByAddress(0xDEAD));
    }

    public function testRoots(): void
    {
        $model = $this->createModel();

        $this->assertSame([1], $model->roots);
    }
}
