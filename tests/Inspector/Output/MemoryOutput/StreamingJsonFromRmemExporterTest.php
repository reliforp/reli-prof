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
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;

class StreamingJsonFromRmemExporterTest extends BaseTestCase
{
    private string $rmem_path;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $base = tempnam(sys_get_temp_dir(), 'reli_rmem_json_');
        self::assertNotFalse($base);
        $this->rmem_path = $base . '.rmem';
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->rmem_path);
        parent::tearDown();
    }

    public function testExportProducesValidJsonStructure(): void
    {
        $this->buildRmemFixture();

        $reader = BinaryReader::open($this->rmem_path);
        $exporter = new StreamingJsonFromRmemExporter($reader);

        $fp = fopen('php://memory', 'w+');
        self::assertNotFalse($fp);
        $exporter->export(
            [['memory_get_usage' => 1024]],
            ['ZendObjectMemoryLocation' => ['count' => 2, 'memory_usage' => 300]],
            null,
            $fp,
        );
        rewind($fp);
        $json = stream_get_contents($fp);
        fclose($fp);

        self::assertIsString($json);
        $decoded = json_decode($json, true);
        self::assertNotNull($decoded, 'Output must be valid JSON: ' . json_last_error_msg());
        self::assertArrayHasKey('summary', $decoded);
        self::assertArrayHasKey('location_types_summary', $decoded);
        self::assertArrayHasKey('class_objects_summary', $decoded);
        self::assertArrayHasKey('context', $decoded);
    }

    private function buildRmemFixture(): void
    {
        $bin_sink = new BinaryContextTreeSink(batch_size: 10);
        $bin_sink->emitNode(
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
        $bin_sink->emitNode(
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
        $bin_sink->emitNode(
            node_id: 3,
            parent_node_id: 2,
            link_name: 'str_prop',
            type: 'StringContext',
            locations: [new ZendStringMemoryLocation(
                address: 0x3000,
                size: 48,
                refcount: 1,
                type_info: 6,
                value: 'hello world',
            )],
            attributes: [],
        );
        $bin_sink->emitReference(reference_node_id: 3, parent_node_id: 1, link_name: 'shared');

        $bin_output = new BinaryMemoryOutput($this->rmem_path);
        $bin_output->finalizeStreaming($bin_sink, [['k' => 'v']]);
    }
}
