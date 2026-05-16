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

        // Node 0: a normal in-heap object — region='zend_mm_heap'.
        // Use 0-based node_ids to match what ContextAnalyzer actually emits;
        // 1-based ids tickle an unrelated pre-existing FFI CSR slot-space
        // off-by-one on 0.12.x that isn't what this test is here to pin.
        $sink->emitNode(
            node_id: 0,
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

        // Node 1: a "good" string still in the heap — region='zend_mm_heap'.
        $sink->emitNode(
            node_id: 1,
            parent_node_id: 0,
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

        // Node 2: the pathological string — address outside any tracked
        // region (RegionBoundaries returns 'outside') with a size large
        // enough that summing it with the two good rows actually overflows
        // PHP_INT_MAX. The pre-fix FFI CSR loader did `$nodeSizesSum += $size`
        // on every row regardless of region, so this row would have promoted
        // the running sum to float and the `int $nodeSizesSum` property
        // would have rejected the assignment with "Cannot assign float to
        // property" — the canonical reproduction of the original report.
        $bogus_size = PHP_INT_MAX - 100;
        $sink->emitNode(
            node_id: 2,
            parent_node_id: 0,
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
        $this->assertSame(64, $ffi_substrate->getNodeSize(0));
        $this->assertSame(48, $ffi_substrate->getNodeSize(1));
        $this->assertSame(0, $ffi_substrate->getNodeSize(2));

        // 2. PHP-array substrate must agree (no silent float, same totals).
        $php_substrate = GraphSubstrate::loadFromBinary(
            $reader,
            useCache: false,
            rebuildCache: false,
            skipScc: true,
        );
        $this->assertSame(112, $php_substrate->getNodeSizesSum());
        $this->assertSame(64, $php_substrate->getNodeSize(0));
        $this->assertSame(48, $php_substrate->getNodeSize(1));
        $this->assertSame(0, $php_substrate->getNodeSize(2));

        // 3. Top Strings must not surface the bogus row.
        $top_strings = BinaryReportDataProvider::getTopStrings($reader, 10);
        $node_ids = array_map(static fn (array $r): int => $r['node_id'], $top_strings);
        $this->assertNotContains(2, $node_ids, 'bogus outside-region string leaked into Top Strings');
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

        // 5. SQL path must drop the same row. Reproduce the same fixture
        //    in an in-memory SQLite DB and load it through the SQL
        //    substrates — both the size sum and the per-node sizes must
        //    match the binary path. Without RegionFilter::sqlPredicate()
        //    in the chunked SQL loader, the substrates would disagree
        //    with the binary path and re-introduce the SQL ↔ rmem
        //    divergence that broke testBinaryAnalyzeReportMatchesSqlite-
        //    ReportForKeyFindings on every target PHP version.
        $sql = new \PDO('sqlite::memory:');
        $sql->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $sql->exec('
            CREATE TABLE context_nodes (
                run_id INTEGER NOT NULL, node_id INTEGER NOT NULL,
                type TEXT NOT NULL, canonical_node_id INTEGER,
                PRIMARY KEY (run_id, node_id)
            )
        ');
        $sql->exec('
            CREATE TABLE context_edges (
                id INTEGER PRIMARY KEY,
                run_id INTEGER NOT NULL, parent_node_id INTEGER,
                child_node_id INTEGER NOT NULL, link_name TEXT NOT NULL,
                is_tree INTEGER NOT NULL, strength TEXT NOT NULL DEFAULT \'strong\'
            )
        ');
        $sql->exec('
            CREATE TABLE context_node_locations (
                id INTEGER PRIMARY KEY,
                run_id INTEGER NOT NULL, node_id INTEGER NOT NULL,
                address BIGINT, size BIGINT,
                location_type TEXT NOT NULL, class_name TEXT,
                string_value TEXT, refcount BIGINT, type_info BIGINT,
                region TEXT
            )
        ');
        $sql->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
            (1, 0, 'ObjectContext'),
            (1, 1, 'StringContext'),
            (1, 2, 'StringContext')");
        $sql->exec("INSERT INTO context_edges (run_id, parent_node_id, child_node_id, link_name, is_tree) VALUES
            (1, NULL, 0, 'root_obj', 1),
            (1, 0, 1, 'good_string', 1),
            (1, 0, 2, 'bogus_string', 1)");
        $sql->prepare(
            "INSERT INTO context_node_locations
                (run_id, node_id, address, size, location_type, class_name, region)
            VALUES
                (1, 0, 4352,  64, 'ZendObjectMemoryLocation', 'App\\\\GoodObject', 'zend_mm_heap'),
                (1, 1, 8192,  48, 'ZendStringMemoryLocation', NULL, 'zend_mm_heap'),
                (1, 2, 3735879680, ?, 'ZendStringMemoryLocation', NULL, 'outside')"
        )->execute([$bogus_size]);

        $sql_php_substrate = GraphSubstrate::loadFromDb($sql, 1);
        $this->assertSame(112, $sql_php_substrate->getNodeSizesSum());
        $this->assertSame(64, $sql_php_substrate->getNodeSize(0));
        $this->assertSame(48, $sql_php_substrate->getNodeSize(1));
        $this->assertSame(0, $sql_php_substrate->getNodeSize(2));
    }

    /**
     * Pins the FLAG_NODE_SIZES_REGION_FILTERED contract end-to-end:
     *
     *   - When the sink runs with a RegionBoundaries attached, the
     *     writer must set the flag in the rmem header and the on-disk
     *     `node_sizes` slots must already exclude 'outside'-region
     *     bytes — a flag-aware reader can then skip the locations
     *     rescan that {@see RegionFilter} would otherwise force.
     *   - When the sink runs without a RegionBoundaries (older writer
     *     path / tests / explicit fallback), the flag must stay off
     *     and the reader must fall back to the locations-scan path
     *     so 'outside' rows are still filtered at read time.
     *
     * Both cases must agree on the same final size totals — the flag
     * is purely a perf hint, never a correctness divergence.
     */
    public function testNodeSizesRegionFilteredFlagIsSetWhenSinkHasRegionBoundaries(): void
    {
        $bogus_size = PHP_INT_MAX - 100;

        // --- Case 1: sink WITH RegionBoundaries → flag set, on-disk
        //     node_sizes already filtered.
        $chunk = new MemoryLocations();
        $chunk->add(new ZendMmChunkMemoryLocation(0x1000, 0x10000));
        $boundaries = new RegionBoundaries(
            $chunk,
            new MemoryLocations(),
            new MemoryLocations(),
            new MemoryLocations(),
        );
        $filteredSink = new BinaryContextTreeSink($boundaries, batch_size: 10);
        $this->emitOutsideFixture($filteredSink, $bogus_size);
        $this->assertTrue(
            $filteredSink->isPerNodeRegionFiltered(),
            'sink with RegionBoundaries should report perNode accumulators as filtered',
        );

        $filteredPath = $this->rmem_path;
        $filteredOutput = new BinaryMemoryOutput($filteredPath);
        $filteredOutput->finalizeStreaming(
            $filteredSink,
            [['zend_mm_heap_usage' => '1024', 'php_version' => '8.4.0']],
        );

        $filteredReader = Reader::open($filteredPath);
        $this->assertTrue(
            $filteredReader->hasHeaderFlag(Format::FLAG_NODE_SIZES_REGION_FILTERED),
            'BinaryMemoryOutput must set FLAG_NODE_SIZES_REGION_FILTERED when sink filtered',
        );
        // The on-disk node_sizes slot for the bogus 'outside' node must
        // be 0 — proving the writer-side filter actually elided the
        // accumulation rather than the reader silently subtracting it
        // out at load time.
        $this->assertNotNull($filteredReader->getSectionData('node_sizes'));
        $rawSizes = $filteredReader->getSectionData('node_sizes');
        $this->assertSame(64, unpack('P', $rawSizes, 0)[1], 'slot 0 (good object) should be 64 on disk');
        $this->assertSame(48, unpack('P', $rawSizes, 8)[1], 'slot 1 (good string) should be 48 on disk');
        $this->assertSame(0, unpack('P', $rawSizes, 16)[1], 'slot 2 (bogus outside string) should be 0 on disk');

        $filteredFfi = FfiCsrGraphSubstrate::loadFromBinary(
            $filteredReader,
            useCache: false,
            rebuildCache: false,
            skipScc: true,
        );
        $this->assertSame(112, $filteredFfi->getNodeSizesSum());
        $this->assertSame(0, $filteredFfi->getNodeSize(2));

        // --- Case 2: sink WITHOUT RegionBoundaries → flag stays off,
        //     reader falls back to the locations-scan path. Real-world
        //     legacy writers leave region NULL on every row (no
        //     classifier ran), and RegionFilter treats NULL as a
        //     pre-tagging fixture and lets it through; the surface
        //     totals must match Case 1's filtered fixture.
        $unfilteredSink = new BinaryContextTreeSink(batch_size: 10);
        // Mirror Case 1's *good* rows only — without RegionBoundaries the
        // sink cannot classify, so the "outside" row would be tagged
        // region=NULL (RegionFilter would let it through as legacy) and
        // poison the assertion. The bogus-row case is what writer-side
        // filtering exists to handle; here we just pin that legacy
        // (flag-off) fixtures still load with the right totals.
        $unfilteredSink->emitNode(
            node_id: 0,
            parent_node_id: null,
            link_name: 'root_obj',
            type: 'ObjectContext',
            locations: [new ZendObjectMemoryLocation(0x1100, 64, 1, 7, 'App\\GoodObject')],
            attributes: [],
        );
        $unfilteredSink->emitNode(
            node_id: 1,
            parent_node_id: 0,
            link_name: 'good_string',
            type: 'StringContext',
            locations: [new ZendStringMemoryLocation(0x2000, 48, 1, 6, 'hello')],
            attributes: [],
        );
        $this->assertFalse(
            $unfilteredSink->isPerNodeRegionFiltered(),
            'sink without RegionBoundaries should report perNode accumulators as unfiltered',
        );

        $unfilteredPath = $filteredPath . '.unfiltered.rmem';
        try {
            $unfilteredOutput = new BinaryMemoryOutput($unfilteredPath);
            $unfilteredOutput->finalizeStreaming(
                $unfilteredSink,
                [['zend_mm_heap_usage' => '1024', 'php_version' => '8.4.0']],
            );

            $unfilteredReader = Reader::open($unfilteredPath);
            $this->assertFalse(
                $unfilteredReader->hasHeaderFlag(Format::FLAG_NODE_SIZES_REGION_FILTERED),
                'flag must stay off when sink had no RegionBoundaries',
            );

            $unfilteredFfi = FfiCsrGraphSubstrate::loadFromBinary(
                $unfilteredReader,
                useCache: false,
                rebuildCache: false,
                skipScc: true,
            );
            $this->assertSame(112, $unfilteredFfi->getNodeSizesSum());
            $this->assertSame(64, $unfilteredFfi->getNodeSize(0));
            $this->assertSame(48, $unfilteredFfi->getNodeSize(1));
            $this->assertSame(
                $filteredFfi->getNodeSizesSum(),
                $unfilteredFfi->getNodeSizesSum(),
                'flag is a perf hint only — both paths must agree on the final total',
            );
        } finally {
            if (file_exists($unfilteredPath)) {
                @unlink($unfilteredPath);
            }
        }
    }

    /**
     * Shared fixture used by both the original
     * testOutsideRegionStringIsExcludedFromAllSizeAttributionSurfaces
     * and the flag-contract test above. Emits three nodes:
     *   - node_id 0: in-heap object, size 64
     *   - node_id 1: in-heap string, size 48
     *   - node_id 2: 'outside' string with garbage `size`
     */
    private function emitOutsideFixture(BinaryContextTreeSink $sink, int $bogus_size): void
    {
        $sink->emitNode(
            node_id: 0,
            parent_node_id: null,
            link_name: 'root_obj',
            type: 'ObjectContext',
            locations: [
                new ZendObjectMemoryLocation(0x1100, 64, 1, 7, 'App\\GoodObject'),
            ],
            attributes: [],
        );
        $sink->emitNode(
            node_id: 1,
            parent_node_id: 0,
            link_name: 'good_string',
            type: 'StringContext',
            locations: [
                new ZendStringMemoryLocation(0x2000, 48, 1, 6, 'hello'),
            ],
            attributes: [],
        );
        $sink->emitNode(
            node_id: 2,
            parent_node_id: 0,
            link_name: 'bogus_string',
            type: 'StringContext',
            locations: [
                new ZendStringMemoryLocation(0xDEAD0000, $bogus_size, 1, 6, 'garbage'),
            ],
            attributes: [],
        );
    }

    /**
     * Pins the on-disk `node_sizes` / `node_classes` fast-path against
     * a fixture whose node_ids are non-zero-based and sparse. The
     * earlier shape of the fast-path loop walked slot indices `0..nc-1`
     * directly and read `sizesData[i * 8]`, which only happened to
     * agree with the section's `node_id`-keyed layout when the emitter
     * happened to use dense 0-based ids. Any sparse or 1-based emitter
     * (ContextAnalyzer's test fixtures, third-party producers, future
     * substrate-unification changes) silently lost the highest slot and
     * read a zero from the unused leading slot — manifesting as a
     * mysterious O(nodeCount)-byte shift in `getNodeSize()`.
     *
     * Build a sparse-id fixture with the flag set so the fast-path
     * actually runs, then assert each node's size lands on the right
     * CSR slot.
     */
    public function testNodeSizesFastPathHandlesSparseNonZeroNodeIds(): void
    {
        $chunk = new MemoryLocations();
        $chunk->add(new ZendMmChunkMemoryLocation(0x1000, 0x10000));
        $boundaries = new RegionBoundaries(
            $chunk,
            new MemoryLocations(),
            new MemoryLocations(),
            new MemoryLocations(),
        );
        $sink = new BinaryContextTreeSink($boundaries, batch_size: 10);

        // node_ids 5, 7, 11 — non-zero-based, sparse. Slots 0..4, 6, 8..10
        // on disk should be left at zero by the writer.
        $sink->emitNode(
            node_id: 5,
            parent_node_id: null,
            link_name: 'root',
            type: 'ObjectContext',
            locations: [new ZendObjectMemoryLocation(0x1100, 100, 1, 7, 'App\\A')],
            attributes: [],
        );
        $sink->emitNode(
            node_id: 7,
            parent_node_id: 5,
            link_name: 'mid',
            type: 'ObjectContext',
            locations: [new ZendObjectMemoryLocation(0x1200, 200, 1, 7, 'App\\B')],
            attributes: [],
        );
        $sink->emitNode(
            node_id: 11,
            parent_node_id: 7,
            link_name: 'leaf',
            type: 'StringContext',
            locations: [new ZendStringMemoryLocation(0x1300, 300, 1, 6, 'leafstr')],
            attributes: [],
        );

        $output = new BinaryMemoryOutput($this->rmem_path);
        $output->finalizeStreaming($sink, [['zend_mm_heap_usage' => '600', 'php_version' => '8.4.0']]);

        $reader = Reader::open($this->rmem_path);
        $this->assertTrue(
            $reader->hasHeaderFlag(Format::FLAG_NODE_SIZES_REGION_FILTERED),
            'sink with RegionBoundaries must flip the fast-path flag',
        );
        // The on-disk node_sizes section must have the right shape:
        // slots 0..4 zero, slot 5 = 100, slot 6 zero, slot 7 = 200,
        // slots 8..10 zero, slot 11 = 300. Spot-check that the writer
        // really left a slot per node_id (not per CSR index) so the
        // reader's csrIdx → node_id indirection has something to read.
        $rawSizes = $reader->getSectionData('node_sizes');
        $this->assertSame(12, $reader->getSectionElementCount('node_sizes'));
        $this->assertSame(100, unpack('P', $rawSizes, 5 * 8)[1]);
        $this->assertSame(200, unpack('P', $rawSizes, 7 * 8)[1]);
        $this->assertSame(300, unpack('P', $rawSizes, 11 * 8)[1]);

        $substrate = FfiCsrGraphSubstrate::loadFromBinary(
            $reader,
            useCache: false,
            rebuildCache: false,
            skipScc: true,
        );
        // Each node_id must end up with its own size, not the leading
        // dense-slot value the broken loop used to pick up.
        $this->assertSame(600, $substrate->getNodeSizesSum());
        $this->assertSame(100, $substrate->getNodeSize(5));
        $this->assertSame(200, $substrate->getNodeSize(7));
        $this->assertSame(300, $substrate->getNodeSize(11));
    }

    /**
     * Pins the flag-off fallback against a fixture that actually
     * contains `region='outside'` rows AND an unfiltered `node_sizes`
     * slot — the shape of every `.rmem` produced after #795 (region
     * tags emitted) but before #799 (writer-side filter + flag).
     *
     * The previous revision of this test wrote the fixture with a
     * RegionBoundaries-attached sink, so the writer-side filter
     * already zeroed `node_sizes[outside_node_id]` before we ever
     * touched the file — clearing the header flag alone wasn't enough
     * to reach the "flag off + on-disk node_sizes contains the bogus
     * sum" shape we actually want to defend against. If a future
     * reader decides to trust on-disk `node_sizes` even when the flag
     * is off, the previous fixture would let that through silently.
     *
     * Reconstruct the real mid-stack shape: write the fixture with
     * the filter on, then hand-patch the file to (a) clear the flag
     * AND (b) overwrite `node_sizes[outside_node]` with the bogus
     * size. The locations section still carries the `region='outside'`
     * row from the original write, so the reader's locations-scan
     * fallback (gated by `RegionFilter`) has to drop it to land on
     * `getNodeSizesSum() == 112`.
     */
    public function testLegacyRmemWithoutFlagButWithOutsideRowsStillFiltersAtRead(): void
    {
        $chunk = new MemoryLocations();
        $chunk->add(new ZendMmChunkMemoryLocation(0x1000, 0x10000));
        $boundaries = new RegionBoundaries(
            $chunk,
            new MemoryLocations(),
            new MemoryLocations(),
            new MemoryLocations(),
        );
        $sink = new BinaryContextTreeSink($boundaries, batch_size: 10);
        $bogus_size = PHP_INT_MAX - 100;
        $this->emitOutsideFixture($sink, $bogus_size);

        $tempPath = $this->rmem_path . '.legacy.rmem';
        try {
            $output = new BinaryMemoryOutput($tempPath);
            $output->finalizeStreaming(
                $sink,
                [['zend_mm_heap_usage' => '512', 'php_version' => '8.4.0']],
            );

            // Pull the `node_sizes` section's file offset before we
            // start hand-patching — Reader closes the mapped file on
            // destruct, so the read side must finish first.
            $probe = Reader::open($tempPath);
            self::assertTrue($probe->hasSection('node_sizes'));
            $nodeSizesOffset = $probe->getSectionOffset('node_sizes');
            self::assertSame(3, $probe->getSectionElementCount('node_sizes'));
            unset($probe);

            $fh = fopen($tempPath, 'r+b');
            self::assertNotFalse($fh);

            // (1) Clear FLAG_NODE_SIZES_REGION_FILTERED in the header.
            //     The flags field is uint32 LE at byte offset 8.
            fseek($fh, 8);
            $flagsBytes = fread($fh, 4);
            self::assertSame(4, strlen($flagsBytes));
            $flags = unpack('V', $flagsBytes)[1];
            $flags &= ~Format::FLAG_NODE_SIZES_REGION_FILTERED;
            fseek($fh, 8);
            fwrite($fh, pack('V', $flags));

            // (2) Overwrite `node_sizes[2]` with the bogus size so the
            //     fixture really contains the pre-#799 raw sum. Slot 2
            //     is the outside node (`emitOutsideFixture` emits
            //     node_ids 0, 1, 2); each int64 slot is 8 bytes.
            fseek($fh, $nodeSizesOffset + 2 * 8);
            fwrite($fh, pack('P', $bogus_size));
            fclose($fh);

            $reader = Reader::open($tempPath);
            $this->assertFalse(
                $reader->hasHeaderFlag(Format::FLAG_NODE_SIZES_REGION_FILTERED),
                'sanity: legacy fixture must report flag off',
            );
            $this->assertSame(
                $bogus_size,
                unpack('P', $reader->getSectionData('node_sizes'), 2 * 8)[1],
                'sanity: legacy fixture must keep the bogus size in node_sizes[2]',
            );

            // The reader must hit the locations-scan fallback, see the
            // `region='outside'` row, and drop it via RegionFilter.
            // If anyone ever shortcuts that fallback when the flag is
            // off (e.g. trusts on-disk node_sizes anyway), this would
            // immediately blow up to a multi-exabyte sum or trip the
            // FFI CSR `Cannot assign float to property` again.
            $substrate = FfiCsrGraphSubstrate::loadFromBinary(
                $reader,
                useCache: false,
                rebuildCache: false,
                skipScc: true,
            );
            $this->assertSame(112, $substrate->getNodeSizesSum());
            $this->assertSame(64, $substrate->getNodeSize(0));
            $this->assertSame(48, $substrate->getNodeSize(1));
            $this->assertSame(0, $substrate->getNodeSize(2));
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Same dense-id bug, now for `canonical_map`. The writer keys the
     * section by `node_id` (`BinaryContextTreeSink::buildCanonicalMap()`
     * does `$map[$node_id] = $canon`), so the reader's fast-path must
     * use the same csrIdx → node_id indirection the `node_sizes` /
     * `node_classes` fast-paths now do.
     *
     * Build a fixture with non-zero-based node_ids that share an
     * address (so a `canonical_map` section is actually emitted), then
     * assert each non-canonical id resolves to the right canon via
     * `findCanonical()`. The pre-fix loop walked slot indices `0..nc-1`
     * directly, so it would have looked up slots 0/1/2 (all -1, the
     * fill value) and missed the real canonical mapping at slots 10
     * and 11 entirely.
     */
    public function testCanonicalMapFastPathHandlesSparseNonZeroNodeIds(): void
    {
        $chunk = new MemoryLocations();
        $chunk->add(new ZendMmChunkMemoryLocation(0x1000, 0x10000));
        $boundaries = new RegionBoundaries(
            $chunk,
            new MemoryLocations(),
            new MemoryLocations(),
            new MemoryLocations(),
        );
        $sink = new BinaryContextTreeSink($boundaries, batch_size: 10);

        // node_ids 9, 10, 11 — non-zero-based, dense within the range.
        // 10 and 11 share an address (0x1200), so the canonical map
        // must collapse them onto min(10, 11) = 10.
        $sink->emitNode(
            node_id: 9,
            parent_node_id: null,
            link_name: 'root',
            type: 'ObjectContext',
            locations: [new ZendObjectMemoryLocation(0x1100, 100, 1, 7, 'App\\Root')],
            attributes: [],
        );
        $sink->emitNode(
            node_id: 10,
            parent_node_id: 9,
            link_name: 'alias_a',
            type: 'ObjectContext',
            locations: [new ZendObjectMemoryLocation(0x1200, 50, 1, 7, 'App\\Shared')],
            attributes: [],
        );
        $sink->emitNode(
            node_id: 11,
            parent_node_id: 9,
            link_name: 'alias_b',
            type: 'ObjectContext',
            locations: [new ZendObjectMemoryLocation(0x1200, 50, 1, 7, 'App\\Shared')],
            attributes: [],
        );

        $output = new BinaryMemoryOutput($this->rmem_path);
        $output->finalizeStreaming($sink, [['zend_mm_heap_usage' => '200', 'php_version' => '8.4.0']]);

        $reader = Reader::open($this->rmem_path);
        $this->assertTrue(
            $reader->hasSection('canonical_map'),
            'shared-address fixture must produce a canonical_map section',
        );
        $canonData = $reader->getSectionData('canonical_map');
        // Width is maxNodeId + 1 = 12 slots. Spot-check that the
        // section is keyed by node_id: slots 10 and 11 both map to 10
        // (the writer marks every node at a shared address, including
        // the canonical itself, with the chosen min node_id). Slots
        // 9 and the empty leading slots stay at -1.
        $this->assertSame(12, $reader->getSectionElementCount('canonical_map'));
        $this->assertSame(10, unpack('l', $canonData, 11 * 4)[1]);
        $this->assertSame(10, unpack('l', $canonData, 10 * 4)[1]);
        $this->assertSame(-1, unpack('l', $canonData, 9 * 4)[1]);
        $this->assertSame(-1, unpack('l', $canonData, 0 * 4)[1]);

        $substrate = FfiCsrGraphSubstrate::loadFromBinary(
            $reader,
            useCache: false,
            rebuildCache: false,
            skipScc: true,
        );
        // Canonical resolution must agree with the on-disk shape:
        // 9 stays self-canonical, 10 stays self-canonical (it IS the
        // min at the shared address), 11 collapses onto 10.
        $this->assertSame(9, $substrate->getCanonical(9));
        $this->assertSame(10, $substrate->getCanonical(10));
        $this->assertSame(10, $substrate->getCanonical(11));
    }
}
