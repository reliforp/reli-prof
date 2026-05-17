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

namespace Reli\Inspector\Output\MemoryOutput\Report;

use PHPUnit\Framework\TestCase;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader;
use Reli\Inspector\Output\MemoryOutput\BinaryMemoryOutput;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\BinaryContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\CallFrameHeaderMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ObjectsStoreMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\Process\MemoryLocation;

class BinaryReportDataProviderTest extends TestCase
{
    private string $rmem_path = '';

    #[\Override]
    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'reli_test_');
        self::assertNotFalse($path);
        $this->rmem_path = $path . '.rmem';
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (file_exists($this->rmem_path)) {
            @unlink($this->rmem_path);
        }
    }

    private function buildFixture(): Reader
    {
        $sink = new BinaryContextTreeSink(batch_size: 10);

        // Node 1: root object of class App\TestClass
        $obj_loc1 = new ZendObjectMemoryLocation(
            address: 0x1000,
            size: 64,
            refcount: 1,
            type_info: 7,
            class_name: 'App\\TestClass',
        );
        $sink->emitNode(
            node_id: 1,
            parent_node_id: null,
            link_name: 'root_entry',
            type: 'ObjectContext',
            locations: [$obj_loc1],
            attributes: ['function_name' => 'main', 'lineno' => '42'],
        );

        // Node 2: string child of node 1
        $str_loc1 = new ZendStringMemoryLocation(
            address: 0x2000,
            size: 100,
            refcount: 1,
            type_info: 6,
            value: 'test string value',
        );
        $sink->emitNode(
            node_id: 2,
            parent_node_id: 1,
            link_name: 'name',
            type: 'StringContext',
            locations: [$str_loc1],
            attributes: [],
        );

        // Node 3: another object of same class App\TestClass
        $obj_loc2 = new ZendObjectMemoryLocation(
            address: 0x3000,
            size: 128,
            refcount: 1,
            type_info: 7,
            class_name: 'App\\TestClass',
        );
        $sink->emitNode(
            node_id: 3,
            parent_node_id: 1,
            link_name: 'child_obj',
            type: 'ObjectContext',
            locations: [$obj_loc2],
            attributes: ['function_name' => 'process'],
        );

        // Node 4: another string child with different value
        $str_loc2 = new ZendStringMemoryLocation(
            address: 0x4000,
            size: 50,
            refcount: 1,
            type_info: 6,
            value: 'another string',
        );
        $sink->emitNode(
            node_id: 4,
            parent_node_id: 3,
            link_name: 'description',
            type: 'StringContext',
            locations: [$str_loc2],
            attributes: [],
        );

        $binary_output = new BinaryMemoryOutput($this->rmem_path);
        $binary_output->finalizeStreaming($sink, [
            ['zend_mm_heap_usage' => '342', 'php_version' => '8.2.0'],
        ]);

        return Reader::open($this->rmem_path);
    }

    public function testGetTopStringsReturnsSortedBySize(): void
    {
        $reader = $this->buildFixture();

        $result = BinaryReportDataProvider::getTopStrings($reader, 10);

        $this->assertNotEmpty($result);

        // The larger string (size=100) should come first
        $this->assertSame(100, $result[0]['size']);
        $this->assertStringContainsString('test string value', $result[0]['preview']);

        // The smaller string (size=50) should come second
        $this->assertSame(50, $result[1]['size']);
        $this->assertStringContainsString('another string', $result[1]['preview']);
    }

    public function testGetTopStringsRespectsLimit(): void
    {
        $reader = $this->buildFixture();

        $result = BinaryReportDataProvider::getTopStrings($reader, 1);

        $this->assertCount(1, $result);
        $this->assertSame(100, $result[0]['size']);
    }

    public function testGetTopStringsReturnsExpectedKeys(): void
    {
        $reader = $this->buildFixture();

        $result = BinaryReportDataProvider::getTopStrings($reader, 10);

        $this->assertNotEmpty($result);
        foreach ($result as $entry) {
            $this->assertArrayHasKey('node_id', $entry);
            $this->assertArrayHasKey('size', $entry);
            $this->assertArrayHasKey('preview', $entry);
        }
    }

    public function testSubstrateNodesByLocationTypeOverRmemFixture(): void
    {
        // `BinaryReportDataProvider::getNodesByLocationType` moved onto
        // `GraphSubstrate` in #787 Stage B follow-up so the bucket-level
        // primitive can't drift between the .db and .rmem paths.
        // Asserts here exercise the same end-to-end shape over an .rmem
        // fixture (substrate loaded via `loadFromBinary`).
        $reader = $this->buildFixture();
        $substrate = \Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate::loadFromBinary($reader);

        $object_nodes = $substrate->getNodesByLocationType('ZendObjectMemoryLocation');
        $this->assertArrayHasKey(1, $object_nodes);
        $this->assertArrayHasKey(3, $object_nodes);
        $this->assertArrayNotHasKey(2, $object_nodes);
        $this->assertArrayNotHasKey(4, $object_nodes);

        $string_nodes = $substrate->getNodesByLocationType('ZendStringMemoryLocation');
        $this->assertArrayHasKey(2, $string_nodes);
        $this->assertArrayHasKey(4, $string_nodes);
        $this->assertArrayNotHasKey(1, $string_nodes);
        $this->assertArrayNotHasKey(3, $string_nodes);

        $this->assertEmpty($substrate->getNodesByLocationType('NonExistentLocationType'));
    }

    public function testSubstrateNodesByLocationTypeMatchesAnyRowNotJustPrimaryLexMin(): void
    {
        // Regression for the Stage B follow-up review: `getNodesByLocationType`
        // mirrors the OLD `SELECT DISTINCT node_id … WHERE location_type = ?`
        // semantics ("any row of this type"), NOT the lex-min "primary
        // type" semantics that backs `getNodeLocationType()`. A node with
        // multiple location rows of different types must be discoverable
        // by every type it carries, even when its lex-min slot is taken
        // by an alphabetically-smaller type.
        $sink = new BinaryContextTreeSink(batch_size: 8);
        $sink->emitNode(
            node_id: 1,
            parent_node_id: null,
            link_name: 'root',
            type: 'RootContext',
            // Two locations on one node, ObjectsStore + CallFrameHeader.
            // 'C' < 'O' lexicographically, so the lex-min primary is
            // CallFrameHeader — yet a query for ObjectsStoreMemoryLocation
            // must still find this node.
            locations: [
                new ObjectsStoreMemoryLocation(0x1000, 256),
                new CallFrameHeaderMemoryLocation(0x2000, 128),
            ],
            attributes: [],
        );
        $binary_output = new BinaryMemoryOutput($this->rmem_path);
        $binary_output->finalizeStreaming($sink, [
            ['zend_mm_heap_usage' => '384'],
        ]);

        $reader = Reader::open($this->rmem_path);
        $substrate = \Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate::loadFromBinary($reader);

        $this->assertSame(
            'CallFrameHeaderMemoryLocation',
            $substrate->getNodeLocationType(1),
            'primary location type is lex-min',
        );
        $this->assertArrayHasKey(
            1,
            $substrate->getNodesByLocationType('ObjectsStoreMemoryLocation'),
            'node 1 must be discoverable via its non-primary ObjectsStore row',
        );
        $this->assertArrayHasKey(
            1,
            $substrate->getNodesByLocationType('CallFrameHeaderMemoryLocation'),
            'node 1 must also be discoverable via its primary CallFrameHeader row',
        );
    }

    public function testSubstrateGetNodeClassPicksLexMinOnRmemPath(): void
    {
        // Pre-fix the binary writer / loader captured class names as
        // "first non-null encountered", whereas the PDO loaders used
        // SQLite `min(class_name)` — so a node carrying multiple
        // ZendObjectMemoryLocation rows with different class names
        // could resolve to a different class on the .rmem path than on
        // the .db path. Post-fix both paths converge on the lex-min.
        $sink = new BinaryContextTreeSink(batch_size: 8);
        $sink->emitNode(
            node_id: 1,
            parent_node_id: null,
            link_name: 'root',
            type: 'RootContext',
            // Two object locations on a single node. Apple < Banana
            // lex-wise, so the captured class should be 'App\\Apple'.
            // Order of emission deliberately picks Banana first to
            // make sure the writer isn't just doing first-non-null.
            locations: [
                new ZendObjectMemoryLocation(0x1000, 64, 1, 7, 'App\\Banana'),
                new ZendObjectMemoryLocation(0x1100, 64, 1, 7, 'App\\Apple'),
            ],
            attributes: [],
        );
        $binary_output = new BinaryMemoryOutput($this->rmem_path);
        $binary_output->finalizeStreaming($sink, [
            ['zend_mm_heap_usage' => '128'],
        ]);

        $reader = Reader::open($this->rmem_path);
        $substrate = \Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate::loadFromBinary($reader);
        $this->assertSame(
            'App\\Apple',
            $substrate->getNodeClass(1),
            'binary loader must pick lex-min class name on multi-class node',
        );
    }

    public function testLoadFrameLabelsReturnsFunctionNameAndLineno(): void
    {
        $reader = $this->buildFixture();

        $labels = BinaryReportDataProvider::loadFrameLabels($reader);

        // Node 1 has function_name=main, lineno=42
        $this->assertArrayHasKey(1, $labels);
        $this->assertSame('main:42', $labels[1]);

        // Node 3 has function_name=process, no lineno
        $this->assertArrayHasKey(3, $labels);
        $this->assertSame('process', $labels[3]);

        // Nodes 2 and 4 have no function_name attribute
        $this->assertArrayNotHasKey(2, $labels);
        $this->assertArrayNotHasKey(4, $labels);
    }

    public function testSubstrateNonTreeEdgeStatsWithNoNonTreeEdges(): void
    {
        // Same end-to-end coverage as the old
        // `BinaryReportDataProvider::getNonTreeEdgeStats` test, now via
        // the substrate primitive (the BinaryReportDataProvider method
        // was deleted in #787 Stage B follow-up).
        $reader = $this->buildFixture();
        $substrate = \Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate::loadFromBinary($reader);
        $this->assertEmpty($substrate->getNonTreeEdgeStats(20));
    }

    public function testSubstrateNonTreeEdgeStatsWithReferences(): void
    {
        // Build a fixture with enough non-tree references to pass the > 10 filter
        $sink = new BinaryContextTreeSink(batch_size: 64);

        $sink->emitNode(
            node_id: 1,
            parent_node_id: null,
            link_name: 'root',
            type: 'RootContext',
            locations: [new MemoryLocation(0x1000, 100)],
            attributes: [],
        );

        // Create a shared target node
        $sink->emitNode(
            node_id: 2,
            parent_node_id: 1,
            link_name: 'shared_target',
            type: 'TargetContext',
            locations: [new MemoryLocation(0x2000, 50)],
            attributes: [],
        );

        // Create many parent nodes that reference node 2 via non-tree edges
        for ($i = 0; $i < 15; $i++) {
            $parent_id = 100 + $i;
            $sink->emitNode(
                node_id: $parent_id,
                parent_node_id: 1,
                link_name: "parent_{$i}",
                type: 'ParentContext',
                locations: [new MemoryLocation(0x10000 + $i * 0x100, 32)],
                attributes: [],
            );
            $sink->emitReference(
                reference_node_id: 2,
                parent_node_id: $parent_id,
                link_name: 'shared_ref',
            );
        }

        $binary_output = new BinaryMemoryOutput($this->rmem_path);
        $binary_output->finalizeStreaming($sink, [
            ['zend_mm_heap_usage' => '1000'],
        ]);

        $reader = Reader::open($this->rmem_path);
        $substrate = \Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate::loadFromBinary($reader);
        $result = $substrate->getNonTreeEdgeStats(20);

        $this->assertNotEmpty($result);
        // Should have one group with link_name 'shared_ref' and ref_count >= 15
        $found = false;
        foreach ($result as $entry) {
            if ($entry['link_name'] === 'shared_ref') {
                $found = true;
                $this->assertSame(15, $entry['ref_count']);
                $this->assertSame(1, $entry['target_count']); // all point to same target
                break;
            }
        }
        $this->assertTrue($found, 'Expected to find shared_ref in non-tree edge stats');
    }

    public function testGetTopStringsWithEmptyFile(): void
    {
        // Build a file with no string locations
        $sink = new BinaryContextTreeSink(batch_size: 10);
        $sink->emitNode(
            node_id: 1,
            parent_node_id: null,
            link_name: 'root',
            type: 'RootContext',
            locations: [new MemoryLocation(0x1000, 100)],
            attributes: [],
        );

        $binary_output = new BinaryMemoryOutput($this->rmem_path);
        $binary_output->finalizeStreaming($sink, [
            ['zend_mm_heap_usage' => '100'],
        ]);

        $reader = Reader::open($this->rmem_path);
        $result = BinaryReportDataProvider::getTopStrings($reader, 10);

        $this->assertEmpty($result);
    }

    public function testLoadSummaryFlattensEntriesAndCoercesNumerics(): void
    {
        $sink = new BinaryContextTreeSink(batch_size: 10);
        $sink->emitNode(
            node_id: 1,
            parent_node_id: null,
            link_name: 'root',
            type: 'ObjectContext',
            locations: [new MemoryLocation(0x1000, 64)],
            attributes: [],
        );

        $binary_output = new BinaryMemoryOutput($this->rmem_path);
        $binary_output->finalizeStreaming($sink, [
            [
                'zend_mm_heap_usage' => '8388608',
                'heap_memory_analyzed_percentage' => '99.5',
                'php_version' => '8.4.1',
            ],
        ]);

        $reader = Reader::open($this->rmem_path);
        $summary = BinaryReportDataProvider::loadSummary($reader);

        // Callers (OverviewPass etc.) expect a list of a single flat map.
        $this->assertCount(1, $summary);
        $flat = $summary[0];

        // Integers come back as int, floats as float, non-numerics as string.
        $this->assertSame(8388608, $flat['zend_mm_heap_usage']);
        $this->assertSame(99.5, $flat['heap_memory_analyzed_percentage']);
        $this->assertSame('8.4.1', $flat['php_version']);
    }

    public function testLoadCapturedAtReturnsIsoTimestamp(): void
    {
        $sink = new BinaryContextTreeSink(batch_size: 10);
        $sink->emitNode(
            node_id: 1,
            parent_node_id: null,
            link_name: 'root',
            type: 'ObjectContext',
            locations: [new MemoryLocation(0x1000, 64)],
            attributes: [],
        );

        $binary_output = new BinaryMemoryOutput($this->rmem_path);
        $binary_output->finalizeStreaming($sink, [
            ['zend_mm_heap_usage' => '100'],
        ]);

        $reader = Reader::open($this->rmem_path);
        $captured_at = BinaryReportDataProvider::loadCapturedAt($reader);

        // finalizeStreaming stamps gmdate('Y-m-d\TH:i:s\Z') into the runs section.
        $this->assertIsString($captured_at);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $captured_at,
        );
    }
}
