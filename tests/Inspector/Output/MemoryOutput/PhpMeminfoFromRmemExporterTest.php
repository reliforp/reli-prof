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
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\BinaryContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;

class PhpMeminfoFromRmemExporterTest extends BaseTestCase
{
    private string $rmem_path;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $base = tempnam(sys_get_temp_dir(), 'reli_rmem_meminfo_');
        self::assertNotFalse($base);
        $this->rmem_path = $base . '.rmem';
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->rmem_path);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function exportFixture(array $summary): array
    {
        $reader = BinaryReader::open($this->rmem_path);
        $exporter = new PhpMeminfoFromRmemExporter($reader);

        $fp = fopen('php://memory', 'w+');
        self::assertNotFalse($fp);
        $exporter->export($summary, $fp);
        rewind($fp);
        $json = stream_get_contents($fp);
        fclose($fp);

        self::assertIsString($json);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded, 'Output must be valid JSON: ' . json_last_error_msg());
        return $decoded;
    }

    public function testExportProducesMeminfoCompatibleDump(): void
    {
        $this->buildRmemFixture();
        $decoded = $this->exportFixture([[
            'memory_get_usage' => 1000,
            'memory_get_real_usage' => 2000,
            'memory_get_peak_usage' => 1500,
            'memory_get_peak_usage_real' => 2500,
            'php_version' => 'v84',
        ]]);

        self::assertSame(
            [
                'memory_usage' => 1000,
                'memory_usage_real' => 2000,
                'peak_memory_usage' => 1500,
                'peak_memory_usage_real' => 2500,
            ],
            $decoded['header'],
        );

        $items = $decoded['items'];
        self::assertIsArray($items);

        // every item has the mandatory php-meminfo fields
        foreach ($items as $key => $item) {
            self::assertIsString($item['type'], "item $key");
            self::assertIsString($item['size'], "item $key: size is a quoted string");
            self::assertIsBool($item['is_root'], "item $key");
            if ($item['is_root']) {
                self::assertArrayHasKey('symbol_name', $item, "item $key");
                self::assertArrayHasKey('frame', $item, "item $key");
            }
            if ($item['type'] === 'object') {
                self::assertArrayHasKey('class', $item, "item $key");
                self::assertArrayHasKey('object_handle', $item, "item $key");
            }
            // no dangling child references: the analyzer's graph
            // builder throws on unknown vertex ids
            foreach ((array)($item['children'] ?? []) as $label => $child_id) {
                self::assertArrayHasKey(
                    $child_id,
                    $items,
                    "child '$label' of $key points to a missing item",
                );
            }
        }

        // frame variable object: rooted in main(), handle from the store
        $obj = $items['0x1000'];
        self::assertSame('object', $obj['type']);
        self::assertSame('MyClassA', $obj['class']);
        self::assertSame('7', $obj['object_handle']);
        self::assertTrue($obj['is_root']);
        self::assertSame('myObj', $obj['symbol_name']);
        self::assertSame('main()', $obj['frame']);

        // object children map property names directly to value items,
        // collapsing reli's object_properties intermediate node
        self::assertSame('0x2000', $obj['children']['name']);
        self::assertSame('0x3000', $obj['children']['items']);
        self::assertArrayHasKey('count', $obj['children']);
        self::assertArrayHasKey('flag', $obj['children']);

        // scalars: value_type attribute wins over ambiguous raw values
        self::assertSame('int', $items[$obj['children']['count']]['type']);
        self::assertSame('bool', $items[$obj['children']['flag']]['type']);
        self::assertSame('16', $items[$obj['children']['count']]['size']);

        // string: zend_reference collapsed away, rooted via the alias
        $str = $items['0x2000'];
        self::assertSame('string', $str['type']);
        self::assertSame('40', $str['size']);
        self::assertTrue($str['is_root']);
        self::assertSame('aliasRef', $str['symbol_name']);
        self::assertSame('main()', $str['frame']);
        self::assertSame('bob', $str['#reli_value']);

        // array children: element wrappers collapsed, keys preserved
        $arr = $items['0x3000'];
        self::assertSame('array', $arr['type']);
        self::assertSame('0x2000', $arr['children']['k1']);
        self::assertSame('null', $items[$arr['children']['0']]['type']);

        // static property roots use Class::prop and the php-meminfo
        // frame marker, preferring the original class-name spelling
        // over the lowercased class-table key
        self::assertSame('MyClassA::registry', $arr['symbol_name']);
        self::assertSame('<CLASS_STATIC_MEMBER>', $arr['frame']);

        // global variables are roots in the <GLOBAL> frame
        $gvar = $items['0x5100'];
        self::assertTrue($gvar['is_root']);
        self::assertSame('gvar', $gvar['symbol_name']);
        self::assertSame('<GLOBAL>', $gvar['frame']);

        // store-only object (unreachable from any root, e.g. cycle
        // garbage): present, not a root, empty children object
        $orphan = $items['0x6000'];
        self::assertSame('Orphan', $orphan['class']);
        self::assertSame('9', $orphan['object_handle']);
        self::assertFalse($orphan['is_root']);
        self::assertSame([], $orphan['children']);

        // reli structural nodes must not leak into the item list
        foreach ($items as $item) {
            self::assertContains(
                $item['type'],
                ['object', 'array', 'string', 'int', 'bool', 'float', 'null', 'resource'],
            );
        }
    }

    public function testPhp7TargetsUseLegacyTypeNames(): void
    {
        $this->buildRmemFixture();
        $decoded = $this->exportFixture([[
            'memory_get_usage' => 1000,
            'memory_get_real_usage' => 2000,
            'php_version' => 'v74',
        ]]);

        $types = array_column($decoded['items'], 'type');
        self::assertContains('integer', $types);
        self::assertContains('boolean', $types);
        self::assertNotContains('int', $types);
        self::assertNotContains('bool', $types);

        // peak values absent from the summary fall back to usage
        self::assertSame(1000, $decoded['header']['peak_memory_usage']);
        self::assertSame(2000, $decoded['header']['peak_memory_usage_real']);
    }

    public function testScalarTypeInferenceForOldRmemsWithoutValueType(): void
    {
        $bin_sink = new BinaryContextTreeSink(batch_size: 10);
        $this->emitFrameWithVariables($bin_sink, next_node_id: 1, variables: [
            // [node_id, attributes]
            [3, ['value' => '3.5']],
            [4, ['value' => '42']],
            [5, ['value' => 'null']],
            [6, ['value' => '']],
        ]);
        $this->finalize($bin_sink);

        $decoded = $this->exportFixture([['php_version' => 'v84']]);
        $by_symbol = [];
        foreach ($decoded['items'] as $item) {
            $by_symbol[$item['symbol_name']] = $item['type'];
        }
        self::assertSame('float', $by_symbol['var_3']);
        self::assertSame('int', $by_symbol['var_4']);
        self::assertSame('null', $by_symbol['var_5']);
        self::assertSame('bool', $by_symbol['var_6']);
    }

    /**
     * @param list<array{int, array<string, string>}> $variables
     */
    private function emitFrameWithVariables(
        BinaryContextTreeSink $bin_sink,
        int $next_node_id,
        array $variables,
    ): void {
        $frames_node = $next_node_id;
        $bin_sink->emitNode(
            node_id: $frames_node,
            parent_node_id: null,
            link_name: 'call_frames',
            type: 'CallFramesContext',
            locations: [],
            attributes: [],
        );
        $frame_node = $next_node_id + 1;
        $bin_sink->emitNode(
            node_id: $frame_node,
            parent_node_id: $frames_node,
            link_name: '0',
            type: 'CallFrameContext',
            locations: [],
            attributes: ['function_name' => 'main', 'lineno' => '3'],
        );
        $table_node = $next_node_id + 2;
        $bin_sink->emitNode(
            node_id: $table_node,
            parent_node_id: $frame_node,
            link_name: 'local_variables',
            type: 'CallFrameVariableTableContext',
            locations: [],
            attributes: [],
        );
        foreach ($variables as [$node_id, $attributes]) {
            $bin_sink->emitNode(
                node_id: $node_id,
                parent_node_id: $table_node,
                link_name: 'var_' . $node_id,
                type: 'ScalarValueContext',
                locations: [],
                attributes: $attributes,
            );
        }
    }

    private function finalize(BinaryContextTreeSink $bin_sink): void
    {
        $bin_output = new BinaryMemoryOutput($this->rmem_path);
        $bin_output->finalizeStreaming($bin_sink, [['k' => 'v']]);
    }

    /**
     * Emulates the graph shape the collector produces (verified against
     * live captures): frames hold variables through a variable-table
     * node, objects hold properties through an object_properties node,
     * arrays hold elements through array_elements + per-element
     * wrappers, references indirect through a 'referenced' link, the
     * objects store links every object by its handle, and class static
     * properties hang off the class table.
     */
    private function buildRmemFixture(): void
    {
        $bin_sink = new BinaryContextTreeSink(batch_size: 10);

        // --- call_frames branch ---
        $bin_sink->emitNode(100, null, 'call_frames', 'CallFramesContext', [], []);
        $bin_sink->emitNode(101, 100, '0', 'CallFrameContext', [], [
            'function_name' => 'main',
            'lineno' => '10',
            'filename' => '/app/x.php',
        ]);
        $bin_sink->emitNode(102, 101, 'local_variables', 'CallFrameVariableTableContext', [], []);

        // $myObj = new MyClassA
        $bin_sink->emitNode(103, 102, 'myObj', 'ObjectContext', [
            new ZendObjectMemoryLocation(
                address: 0x1000,
                size: 88,
                refcount: 1,
                type_info: 7,
                class_name: 'MyClassA',
            ),
        ], []);
        $bin_sink->emitNode(104, 103, 'object_handlers', 'ObjectHandlersContext', [], []);
        $bin_sink->emitNode(105, 103, 'object_properties', 'ObjectPropertiesContext', [], []);
        $bin_sink->emitNode(106, 105, 'name', 'StringContext', [
            new ZendStringMemoryLocation(
                address: 0x2000,
                size: 40,
                refcount: 2,
                type_info: 6,
                value: 'bob',
            ),
        ], []);
        $bin_sink->emitNode(107, 105, 'count', 'ScalarValueContext', [], [
            'value' => '42',
            'value_type' => 'int',
        ]);
        $bin_sink->emitNode(108, 105, 'flag', 'ScalarValueContext', [], [
            'value' => '1',
            'value_type' => 'bool',
        ]);
        $bin_sink->emitNode(109, 105, 'items', 'ArrayHeaderContext', [
            new ZendArrayMemoryLocation(0x3000, 56, 1, 7),
        ], []);
        $bin_sink->emitNode(110, 109, 'array_elements', 'ArrayElementsContext', [], []);
        $bin_sink->emitNode(111, 110, 'k1', 'ArrayElementContext', [], []);
        $bin_sink->emitReference(106, 111, 'value');
        $bin_sink->emitNode(112, 110, '0', 'ArrayElementContext', [], []);
        $bin_sink->emitNode(113, 112, 'value', 'ScalarValueContext', [], [
            'value' => 'null',
            'value_type' => 'null',
        ]);

        // $aliasRef = &$name-holding string (reference wrapper)
        $bin_sink->emitNode(114, 102, 'aliasRef', 'PhpReferenceContext', [], []);
        $bin_sink->emitReference(106, 114, 'referenced');

        // --- global_variables branch ---
        $bin_sink->emitNode(130, null, 'global_variables', 'ArrayHeaderContext', [
            new ZendArrayMemoryLocation(0x5000, 56, 1, 7),
        ], []);
        $bin_sink->emitNode(131, 130, 'array_elements', 'ArrayElementsContext', [], []);
        $bin_sink->emitNode(132, 131, 'gvar', 'ArrayElementContext', [], []);
        $bin_sink->emitNode(133, 132, 'value', 'StringContext', [
            new ZendStringMemoryLocation(
                address: 0x5100,
                size: 48,
                refcount: 1,
                type_info: 6,
                value: 'global value',
            ),
        ], []);

        // --- class_table branch (static property points at the same
        // array the object property holds) ---
        $bin_sink->emitNode(140, null, 'class_table', 'DefinedClassesContext', [], []);
        $bin_sink->emitNode(141, 140, 'myclassa', 'ClassDefinitionContext', [], []);
        $bin_sink->emitNode(142, 141, 'class_entry', 'ClassEntryContext', [], [
            'class_name' => 'MyClassA',
        ]);
        $bin_sink->emitNode(143, 141, 'static_properties', 'ClassStaticPropertiesContext', [], []);
        $bin_sink->emitReference(109, 143, 'registry');

        // --- objects_store branch: handles + a store-only orphan ---
        $bin_sink->emitNode(150, null, 'objects_store', 'ObjectsStoreContext', [], []);
        $bin_sink->emitReference(103, 150, '7');
        $bin_sink->emitNode(151, 150, '9', 'ObjectContext', [
            new ZendObjectMemoryLocation(
                address: 0x6000,
                size: 72,
                refcount: 1,
                type_info: 7,
                class_name: 'Orphan',
            ),
        ], []);
        $bin_sink->emitNode(152, 151, 'object_properties', 'ObjectPropertiesContext', [], []);

        $this->finalize($bin_sink);
    }
}
