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

namespace Reli\Inspector\Output\MemoryOutput\BinaryFormat;

use PHPUnit\Framework\TestCase;
use Reli\Inspector\Output\MemoryOutput\BinaryMemoryOutput;
use Reli\Inspector\Output\MemoryOutput\Report\BinaryReportDataProvider;
use Reli\Inspector\Output\MemoryOutput\Report\ReportGenerator;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\FfiCsrGraphSubstrate;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\BinaryContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MemoryLocations;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendMmChunkMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;
use Reli\Lib\Process\MemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;

class BinaryFormatRoundTripTest extends TestCase
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

    public function testStringDictRoundTrip(): void
    {
        $dict = new StringDict();
        $id0 = $dict->intern('hello');
        $id1 = $dict->intern('world');
        $id2 = $dict->intern('hello'); // duplicate

        $this->assertSame(0, $id0);
        $this->assertSame(1, $id1);
        $this->assertSame(0, $id2); // same as first

        $this->assertSame('hello', $dict->lookup(0));
        $this->assertSame('world', $dict->lookup(1));
        $this->assertNull($dict->lookup(Format::NULL_STRING_ID));

        // Round-trip through serialize/deserialize
        $serialized = $dict->serialize();
        $dict2 = StringDict::deserialize($serialized);
        $this->assertSame('hello', $dict2->lookup(0));
        $this->assertSame('world', $dict2->lookup(1));
        $this->assertSame(2, $dict2->count());
    }

    public function testWriterReaderRoundTrip(): void
    {
        // Write a minimal .rmem file
        $writer = new Writer($this->rmem_path);
        $writer->writeSection('test_sec', 'hello world', 1);
        $writer->writeSection('another', pack('VV', 42, 99), 2);
        $writer->finish();

        // Read it back
        $reader = Reader::open($this->rmem_path);
        $this->assertTrue($reader->hasSection('test_sec'));
        $this->assertTrue($reader->hasSection('another'));
        $this->assertFalse($reader->hasSection('nonexistent'));

        $this->assertSame('hello world', $reader->getSectionData('test_sec'));
        $this->assertSame(1, $reader->getSectionElementCount('test_sec'));

        $data = $reader->getSectionData('another');
        $vals = unpack('V2', $data);
        self::assertIsArray($vals);
        $this->assertSame(42, $vals[1]);
        $this->assertSame(99, $vals[2]);
        $this->assertSame(2, $reader->getSectionElementCount('another'));
    }

    public function testSinkAndOutputRoundTrip(): void
    {
        // Create a sink and emit some data
        $sink = new BinaryContextTreeSink(batch_size: 10);

        // Emit node 1 with an object location
        $obj_loc = new ZendObjectMemoryLocation(
            address: 0x1000,
            size: 64,
            refcount: 1,
            type_info: 7,
            class_name: 'App\\MyClass',
        );
        $sink->emitNode(
            node_id: 1,
            parent_node_id: null,
            link_name: 'root_entry',
            type: 'ObjectContext',
            locations: [$obj_loc],
            attributes: ['#count' => '5'],
        );

        // Emit node 2 with a string location, child of node 1
        $str_loc = new ZendStringMemoryLocation(
            address: 0x2000,
            size: 48,
            refcount: 2,
            type_info: 6,
            value: 'hello',
        );
        $sink->emitNode(
            node_id: 2,
            parent_node_id: 1,
            link_name: 'child_link',
            type: 'StringContext',
            locations: [$str_loc],
            attributes: [],
        );

        // Emit a non-tree reference from node 2 back to node 1
        $sink->emitReference(
            reference_node_id: 1,
            parent_node_id: 2,
            link_name: 'backref',
        );

        $this->assertSame(2, $sink->getNodeCount());
        $this->assertSame(3, $sink->getEdgeCount()); // 2 tree + 1 non-tree
        $this->assertSame(2, $sink->getLocationCount());
        $this->assertSame(1, $sink->getAttrCount());

        // Finalize to .rmem
        $binary_output = new BinaryMemoryOutput($this->rmem_path);
        $summary = [
            ['zend_mm_heap_usage' => '1024', 'php_version' => '8.2.0'],
        ];
        $binary_output->finalizeStreaming($sink, $summary);

        $this->assertFileExists($this->rmem_path);

        // Read it back
        $reader = Reader::open($this->rmem_path);
        $this->assertTrue($reader->hasSection(Format::SECTION_STRING_DICT));
        $this->assertTrue($reader->hasSection(Format::SECTION_NODES));
        $this->assertTrue($reader->hasSection(Format::SECTION_EDGES));
        $this->assertTrue($reader->hasSection(Format::SECTION_LOCATIONS));
        $this->assertTrue($reader->hasSection(Format::SECTION_ATTRIBUTES));
        $this->assertTrue($reader->hasSection(Format::SECTION_SUMMARY));

        $this->assertSame(2, $reader->getSectionElementCount(Format::SECTION_NODES));
        $this->assertSame(3, $reader->getSectionElementCount(Format::SECTION_EDGES));
        $this->assertSame(2, $reader->getSectionElementCount(Format::SECTION_LOCATIONS));
        $this->assertSame(1, $reader->getSectionElementCount(Format::SECTION_ATTRIBUTES));

        // Verify string dict round-trip
        $dict = $reader->getStringDict();
        $this->assertNotNull($dict->lookup(0)); // at least one string interned
    }

    public function testNodeClassesSectionIsPopulatedForObjectLocations(): void
    {
        // Regression for T2.3: BinaryContextTreeSink::$perNodeClasses was
        // an FFI `int32_t[]`. NULL_STRING_ID = 0xFFFFFFFF round-tripped
        // through int32_t reads back as `-1`, never `4294967295`, so
        // `(int)$arr[i] === Format::NULL_STRING_ID` was always false and
        // the per-node accumulator never recorded a class for any
        // object location. Result: the on-disk `node_classes` section
        // came out all-NULL and the FFI-CSR substrate's binary loader
        // returned null for every `getNodeClass()` — the SQL path was
        // unaffected because it reads class_name straight from
        // LocationRow. See
        // docs/internals/memory-report-t2-3-investigation.md for the
        // full trace.
        $sink = new BinaryContextTreeSink(batch_size: 10);

        $sink->emitNode(
            node_id: 1,
            parent_node_id: null,
            link_name: 'root_entry',
            type: 'ObjectContext',
            locations: [
                new ZendObjectMemoryLocation(
                    address: 0x1000,
                    size: 64,
                    refcount: 1,
                    type_info: 7,
                    class_name: 'App\\MyClass',
                ),
            ],
            attributes: [],
        );

        $binary_output = new BinaryMemoryOutput($this->rmem_path);
        $binary_output->finalizeStreaming(
            $sink,
            [['zend_mm_heap_usage' => '64']],
        );

        $reader = Reader::open($this->rmem_path);
        $this->assertTrue(
            $reader->hasSection('node_classes'),
            'finalize wrote a node_classes section',
        );

        $classesData = $reader->getSectionData('node_classes');
        $slots = (int)(strlen($classesData) / 4);
        $this->assertGreaterThan(0, $slots);

        // At least one slot must be a real string-dict id, not the
        // NULL_STRING_ID sentinel that signals "no class known". Pre-
        // T2.3, every slot in this scenario was NULL_STRING_ID.
        $non_null_slots = 0;
        for ($i = 0; $i < $slots; $i++) {
            /** @var array{1: int} $u */
            $u = unpack('V', $classesData, $i * 4);
            if ((int)$u[1] !== Format::NULL_STRING_ID) {
                $non_null_slots++;
            }
        }
        $this->assertGreaterThan(
            0,
            $non_null_slots,
            'node_classes section must record at least one class id'
            . ' for the emitted ZendObjectMemoryLocation',
        );
    }

    public function testSubstrateLoadFromBinary(): void
    {
        // Build a .rmem with a small graph
        $sink = new BinaryContextTreeSink(batch_size: 10);

        $loc1 = new ZendObjectMemoryLocation(0x1000, 100, 1, 7, 'App\\Root');
        $sink->emitNode(1, null, 'root', 'RootContext', [$loc1], []);

        $loc2 = new ZendObjectMemoryLocation(0x2000, 200, 1, 7, 'App\\Child');
        $sink->emitNode(2, 1, 'child_a', 'ChildContext', [$loc2], []);

        $loc3 = new MemoryLocation(0x3000, 50);
        $sink->emitNode(3, 1, 'child_b', 'LeafContext', [$loc3], []);

        $binary_output = new BinaryMemoryOutput($this->rmem_path);
        $binary_output->finalizeStreaming($sink, [['zend_mm_heap_usage' => '350']]);

        // Load substrate from binary
        $reader = Reader::open($this->rmem_path);
        $substrate = GraphSubstrate::loadFromBinary($reader);

        // Verify node sizes
        $this->assertSame(100, $substrate->getNodeSize(1));
        $this->assertSame(200, $substrate->getNodeSize(2));
        $this->assertSame(50, $substrate->getNodeSize(3));

        // Verify classes
        $this->assertSame('App\\Root', $substrate->getNodeClass(1));
        $this->assertSame('App\\Child', $substrate->getNodeClass(2));
        $this->assertNull($substrate->getNodeClass(3)); // MemoryLocation has no class

        // Verify types
        $this->assertSame('RootContext', $substrate->getNodeType(1));
        $this->assertSame('ChildContext', $substrate->getNodeType(2));
        $this->assertSame('LeafContext', $substrate->getNodeType(3));

        // Verify tree edges
        $children = $substrate->getChildren(1);
        sort($children);
        $this->assertSame([2, 3], $children);

        // Verify roots
        $roots = $substrate->getRoots();
        $this->assertSame([1], $roots);

        // Verify subtree sizes
        $this->assertTrue($substrate->hasSubtreeSizes());
        $this->assertSame(350, $substrate->getSubtreeSize(1)); // 100 + 200 + 50
        $this->assertSame(200, $substrate->getSubtreeSize(2));
        $this->assertSame(50, $substrate->getSubtreeSize(3));
    }

    public function testGenerateFromBinary(): void
    {
        // Build a .rmem file with enough data for report generation
        $sink = new BinaryContextTreeSink(batch_size: 10);

        $loc1 = new ZendObjectMemoryLocation(0x1000, 100, 1, 7, 'App\\Root');
        $sink->emitNode(1, null, 'root', 'RootContext', [$loc1], []);

        $loc2 = new ZendObjectMemoryLocation(0x2000, 200, 1, 7, 'App\\Child');
        $sink->emitNode(2, 1, 'child_a', 'ChildContext', [$loc2], []);

        $binary_output = new BinaryMemoryOutput($this->rmem_path);
        $binary_output->finalizeStreaming($sink, [
            [
                'zend_mm_heap_usage' => '300',
                'php_version' => '8.2.0',
            ],
        ]);

        // Generate report from binary
        $generator = new ReportGenerator();
        $result = $generator->generateFromBinary($this->rmem_path, false);

        // Should have findings (at least overview)
        $this->assertNotEmpty($result->findings);

        // Meta should be populated
        $this->assertSame(2, $result->meta['node_count']);
        $this->assertSame(2, $result->meta['edge_count']);
        $this->assertSame('8.2.0', $result->meta['php_version']);
    }

    public function testNodeNonDedup(): void
    {
        $sink = new BinaryContextTreeSink(batch_size: 10);

        // Emit same node_id twice. ContextAnalyzer guarantees this
        // doesn't happen in practice, but verify the sink doesn't
        // crash — it simply writes two rows. The substrate loader
        // handles duplicates at read time.
        $loc = new MemoryLocation(0x1000, 100);
        $sink->emitNode(1, null, 'entry1', 'TypeA', [$loc], []);
        $sink->emitNode(1, null, 'entry2', 'TypeA', [], []);

        $this->assertSame(2, $sink->getNodeCount()); // no dedup at write time
        $this->assertSame(2, $sink->getEdgeCount()); // 2 edges (one per emit)
    }

    public function testEdgeStrengthEncoding(): void
    {
        $sink = new BinaryContextTreeSink(batch_size: 10);

        $loc = new MemoryLocation(0x1000, 100);
        $sink->emitNode(1, null, 'strong_link', 'Type', [$loc], [], EdgeStrength::Strong);

        $loc2 = new MemoryLocation(0x2000, 50);
        $sink->emitNode(2, 1, 'weak_link', 'Type', [$loc2], [], EdgeStrength::Weak);

        $binary_output = new BinaryMemoryOutput($this->rmem_path);
        $binary_output->finalizeStreaming($sink, [['zend_mm_heap_usage' => '150']]);

        // Load and verify strong vs weak edges through substrate
        $reader = Reader::open($this->rmem_path);
        $substrate = GraphSubstrate::loadFromBinary($reader);

        // Strong children should include node 2
        $strong_children = $substrate->getStrongChildren(1);
        $this->assertEmpty($strong_children); // weak edges are NOT strong children

        // All children should include node 2
        $all_children = $substrate->getChildren(1);
        $this->assertContains(2, $all_children);
    }

    /**
     * Regression for "BinaryMemoryOutput does not support the non-streaming
     * output() path": MemoryCommand needs to compute region sums + overhead
     * directly from the binary sink (no SQLite intermediate exists in the
     * binary path), so the helper must scan the location temp file and
     * surface both per-region size sums and the total bin_overhead.
     */
    public function testComputeRegionSumsAndOverheadFromBinarySink(): void
    {
        $chunk_locations = new MemoryLocations();
        $chunk_locations->add(new ZendMmChunkMemoryLocation(0x1000, 0x10000));
        $huge_locations = new MemoryLocations();
        $huge_locations->add(new ZendMmChunkMemoryLocation(0x200000, 0x10000));
        $vm_stack_locations = new MemoryLocations();
        $compiler_arena_locations = new MemoryLocations();

        $region_boundaries = new RegionBoundaries(
            $chunk_locations,
            $huge_locations,
            $vm_stack_locations,
            $compiler_arena_locations,
        );

        $sink = new BinaryContextTreeSink($region_boundaries, batch_size: 10);

        // Two locations inside the chunk → zend_mm_heap, sizes 100 + 50
        $sink->emitNode(
            node_id: 1,
            parent_node_id: null,
            link_name: 'in_chunk_a',
            type: 'TypeA',
            locations: [new MemoryLocation(0x1100, 100)],
            attributes: [],
        );
        $sink->emitNode(
            node_id: 2,
            parent_node_id: 1,
            link_name: 'in_chunk_b',
            type: 'TypeB',
            locations: [new MemoryLocation(0x2000, 50)],
            attributes: [],
        );
        // One location inside the huge block, size 4096
        $sink->emitNode(
            node_id: 3,
            parent_node_id: null,
            link_name: 'in_huge',
            type: 'TypeC',
            locations: [new MemoryLocation(0x200500, 4096)],
            attributes: [],
        );
        // One location outside any tracked region — RegionBoundaries
        // returns 'outside' for it, so it should be surfaced under
        // sums['outside'] rather than silently dropped.
        $sink->emitNode(
            node_id: 4,
            parent_node_id: null,
            link_name: 'outside',
            type: 'TypeD',
            locations: [new MemoryLocation(0xDEAD0000, 32)],
            attributes: [],
        );

        $result = $sink->computeRegionSumsAndOverhead();

        $this->assertSame(150, $result['sums']['zend_mm_heap'] ?? null);
        $this->assertSame(4096, $result['sums']['zend_mm_huge'] ?? null);
        // RegionBoundaries returns 'outside' for unclassified addresses;
        // the helper interns whatever region_boundaries hands back so the
        // 'outside' bucket is surfaced verbatim — do not silently drop it.
        $this->assertSame(32, $result['sums']['outside'] ?? null);
        // No ZendMmChunk attached to the test chunk → bin overhead stays 0.
        $this->assertSame(0, $result['overhead']);
    }

    public function testGenerateFromBinaryIncludesDedupCandidate(): void
    {
        $sink = new BinaryContextTreeSink(batch_size: 64);

        for ($i = 0; $i < 60; $i++) {
            $owner_node_id = 1000 + $i * 10;
            $properties_node_id = $owner_node_id + 1;
            $array_header_node_id = $owner_node_id + 2;
            $array_elements_node_id = $owner_node_id + 3;
            $array_element_node_id = $owner_node_id + 4;
            $string_node_id = 100000 + $i;

            $sink->emitNode(
                $owner_node_id,
                null,
                "owner_{$i}",
                'OwnerContext',
                [new ZendObjectMemoryLocation(0x100000 + $i, 64, 1, 7, 'App\\Owner')],
                [],
            );
            $sink->emitNode(
                $properties_node_id,
                $owner_node_id,
                'object_properties',
                'ObjectPropertiesContext',
                [],
                [],
            );
            $sink->emitNode(
                $array_header_node_id,
                $properties_node_id,
                'names',
                'ArrayContext',
                [new ZendArrayMemoryLocation(0x200000 + $i, 56, 1, 7)],
                [],
            );
            $sink->emitNode(
                $array_elements_node_id,
                $array_header_node_id,
                'array_elements',
                'ArrayElementsContext',
                [],
                [],
            );
            $sink->emitNode(
                $array_element_node_id,
                $array_elements_node_id,
                '0',
                'ArrayElementContext',
                [],
                [],
            );
            $sink->emitNode(
                $string_node_id,
                null,
                "string_{$i}",
                'StringContext',
                [new ZendStringMemoryLocation(
                    0x300000 + $i,
                    256,
                    1,
                    6,
                    'same-shared-string',
                )],
                [],
            );
            $sink->emitReference(
                reference_node_id: $string_node_id,
                parent_node_id: $array_element_node_id,
                link_name: 'value',
            );
        }

        $binary_output = new BinaryMemoryOutput($this->rmem_path);
        $binary_output->finalizeStreaming($sink, [['zend_mm_heap_usage' => '300000']]);

        $generator = new ReportGenerator();
        $result = $generator->generateFromBinary($this->rmem_path, false);

        $dedup_finding = null;
        foreach ($result->findings as $finding) {
            if (
                $finding->kind === 'dedup_candidate'
                && str_contains($finding->summary, 'App\\Owner::$names[value]')
            ) {
                $dedup_finding = $finding;
                break;
            }
        }

        $this->assertNotNull($dedup_finding);
        $this->assertSame(60, $dedup_finding->facts['count']);
        $examples = $dedup_finding->facts['examples'] ?? null;
        self::assertIsArray($examples);
        $this->assertSame(
            'same-shared-string',
            $examples['sample_value'] ?? null,
        );
    }

    /**
     * Regression: when the writer's CSR slot space did not match the
     * reader's, the FFI substrate ended up with a back edge from the
     * sentinel slot to the root, and computeSubtreeSizesFfi grew its
     * stack until OOM. ContextAnalyzer happens to emit dense
     * `0..nodeCount-1` node_ids, which masked the bug; any caller
     * that emits non-zero-based node_ids (e.g. test fixtures starting
     * at 1) tripped it.
     *
     * The flat-tree shape is the smallest reproducer: a single root
     * with N siblings produces both the off-by-one rowptr and the
     * sentinel↔root cycle that turned the DFS exponential.
     */
    public function testFfiCsrLoadFromBinarySurvivesNonZeroNodeIds(): void
    {
        $sink = new BinaryContextTreeSink(batch_size: 10);

        $sink->emitNode(
            node_id: 1,
            parent_node_id: null,
            link_name: 'root',
            type: 'RootContext',
            locations: [new ZendObjectMemoryLocation(0x1000, 64, 1, 7, 'Root')],
            attributes: [],
        );
        $n = 50;
        for ($i = 2; $i <= $n; $i++) {
            $sink->emitNode(
                node_id: $i,
                parent_node_id: 1,
                link_name: "c{$i}",
                type: 'ChildContext',
                locations: [new ZendObjectMemoryLocation(0x1000 + $i * 64, 100, 1, 7, 'C')],
                attributes: [],
            );
        }

        $binary_output = new BinaryMemoryOutput($this->rmem_path);
        $binary_output->finalizeStreaming($sink, [
            ['zend_mm_heap_usage' => '1024', 'php_version' => '8.4'],
        ]);

        $reader = Reader::open($this->rmem_path);
        $substrate = FfiCsrGraphSubstrate::loadFromBinary($reader, useCache: false);

        $this->assertSame([1], $substrate->getRoots());

        $children = $substrate->getChildren(1);
        sort($children);
        $this->assertSame(range(2, $n), $children);

        // Subtree size of root = its 64 bytes + 49 children × 100 bytes.
        $this->assertSame(64 + ($n - 1) * 100, $substrate->getSubtreeSize(1));
        $this->assertSame(100, $substrate->getSubtreeSize(2));
    }

    /**
     * Regression for the "ChatGPT/サイズ大きすぎ" cluster of bugs:
     *   - dangling `php_stream_memory_data->data` pointers, dereferenced by
     *     EmitResourceJob::collectMemoryStreamData as a `zend_string`,
     *     produce a `ZendStringMemoryLocation` whose `address` lands in
     *     `'outside'` and whose `len + 24` is a multi-exabyte garbage value;
     *   - on the FFI CSR path that overflows `int $nodeSizesSum` to float
     *     and trips `Cannot assign float to property`;
     *   - on the PHP-array path it silently propagates into ChokePoint /
     *     Top Strings while Type Breakdown — which already filters by
     *     region — stays clean.
     *
     * Every size-attribution surface must drop those rows. Build a minimal
     * .rmem that contains exactly one such bogus 'outside' string alongside
     * a normal in-heap object, then assert all surfaces converge.
     */
    public function testOutsideRegionStringIsExcludedFromAllSizeAttributionSurfaces(): void
    {
        $chunk_locations = new MemoryLocations();
        // One zend_mm chunk covering 0x1000..0x10000.
        $chunk_locations->add(new ZendMmChunkMemoryLocation(0x1000, 0x10000));
        $huge_locations = new MemoryLocations();
        $vm_stack_locations = new MemoryLocations();
        $compiler_arena_locations = new MemoryLocations();

        $region_boundaries = new RegionBoundaries(
            $chunk_locations,
            $huge_locations,
            $vm_stack_locations,
            $compiler_arena_locations,
        );

        $sink = new BinaryContextTreeSink($region_boundaries, batch_size: 10);

        // Node 1: a normal in-heap object — region='zend_mm_heap'.
        $sink->emitNode(
            node_id: 1,
            parent_node_id: null,
            link_name: 'root_obj',
            type: 'ObjectContext',
            locations: [
                new ZendObjectMemoryLocation(
                    address: 0x1100,
                    size: 64,
                    refcount: 1,
                    type_info: 7,
                    class_name: 'App\\GoodObject',
                ),
            ],
            attributes: [],
        );

        // Node 2: a "good" string still in the heap — region='zend_mm_heap'.
        $sink->emitNode(
            node_id: 2,
            parent_node_id: 1,
            link_name: 'good_string',
            type: 'StringContext',
            locations: [
                new ZendStringMemoryLocation(
                    address: 0x2000,
                    size: 48,
                    refcount: 1,
                    type_info: 6,
                    value: 'hello',
                ),
            ],
            attributes: [],
        );

        // Node 3: the pathological string — address outside any tracked
        // region (RegionBoundaries returns 'outside') with a size near
        // PHP_INT_MAX, mimicking a stale stream_memory_data->data deref.
        $bogus_size = (int)(PHP_INT_MAX / 2);
        $sink->emitNode(
            node_id: 3,
            parent_node_id: 1,
            link_name: 'bogus_string',
            type: 'StringContext',
            locations: [
                new ZendStringMemoryLocation(
                    address: 0xDEAD0000,
                    size: $bogus_size,
                    refcount: 1,
                    type_info: 6,
                    value: 'garbage',
                ),
            ],
            attributes: [],
        );

        $binary_output = new BinaryMemoryOutput($this->rmem_path);
        $binary_output->finalizeStreaming(
            $sink,
            [['zend_mm_heap_usage' => '1024', 'php_version' => '8.4.0']],
        );

        $reader = Reader::open($this->rmem_path);

        // 1. FFI CSR substrate must not raise "Cannot assign float to property".
        $ffi_substrate = FfiCsrGraphSubstrate::loadFromBinary(
            $reader,
            useCache: false,
            rebuildCache: false,
            skipScc: true,
        );
        // 64 (object) + 48 (good string). The bogus string is dropped.
        $this->assertSame(112, $ffi_substrate->getNodeSizesSum());
        $this->assertSame(64, $ffi_substrate->getNodeSize(1));
        $this->assertSame(48, $ffi_substrate->getNodeSize(2));
        $this->assertSame(0, $ffi_substrate->getNodeSize(3));

        // 2. PHP-array substrate must agree (no silent float, same totals).
        $php_substrate = GraphSubstrate::loadFromBinary(
            $reader,
            useCache: false,
            rebuildCache: false,
            skipScc: true,
        );
        $this->assertSame(112, $php_substrate->getNodeSizesSum());
        $this->assertSame(64, $php_substrate->getNodeSize(1));
        $this->assertSame(48, $php_substrate->getNodeSize(2));
        $this->assertSame(0, $php_substrate->getNodeSize(3));

        // 3. Top Strings must not surface the bogus row.
        $top_strings = BinaryReportDataProvider::getTopStrings($reader, 10);
        $node_ids = array_map(static fn (array $r): int => $r['node_id'], $top_strings);
        $this->assertNotContains(3, $node_ids, 'bogus outside-region string leaked into Top Strings');
        foreach ($top_strings as $row) {
            $this->assertLessThan($bogus_size, $row['size'], 'oversized bogus row leaked into Top Strings');
        }

        // 4. Type Breakdown (computeLocationTypesSummary) and the
        //    substrate's grand total must agree on the same filtered view.
        $location_types = BinaryReportDataProvider::computeLocationTypesSummary($reader);
        $type_breakdown_total = 0;
        foreach ($location_types as $entry) {
            $type_breakdown_total += $entry['memory_usage'];
        }
        $this->assertSame(
            $ffi_substrate->getNodeSizesSum(),
            $type_breakdown_total,
            'Type Breakdown total disagrees with FFI substrate getNodeSizesSum()',
        );
        $this->assertSame(
            $php_substrate->getNodeSizesSum(),
            $type_breakdown_total,
            'Type Breakdown total disagrees with PHP substrate getNodeSizesSum()',
        );
    }
}
