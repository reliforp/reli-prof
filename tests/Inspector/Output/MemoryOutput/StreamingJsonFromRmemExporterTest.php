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
use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\BinaryContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\PdoContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;

class StreamingJsonFromRmemExporterTest extends BaseTestCase
{
    private string $db_path;
    private string $rmem_path;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $base = tempnam(sys_get_temp_dir(), 'reli_rmem_json_');
        self::assertNotFalse($base);
        $this->db_path = $base . '.sqlite3';
        $this->rmem_path = $base . '.rmem';
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->db_path);
        @unlink($this->rmem_path);
        parent::tearDown();
    }

    public function testExportProducesValidJsonStructure(): void
    {
        $this->buildBothFixtures();

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

    public function testRmemAndDbExportersAgreeOnSameTree(): void
    {
        $this->buildBothFixtures();

        // DB exporter
        $db = new \PDO("sqlite:{$this->db_path}");
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db_exporter = new StreamingJsonFromDbExporter($db, run_id: 1);

        $fp_db = fopen('php://memory', 'w+');
        self::assertNotFalse($fp_db);
        $db_exporter->export([['k' => 'v']], null, null, $fp_db);
        rewind($fp_db);
        $db_json = stream_get_contents($fp_db);
        fclose($fp_db);

        // Rmem exporter
        $reader = BinaryReader::open($this->rmem_path);
        $rmem_exporter = new StreamingJsonFromRmemExporter($reader);

        $fp_rmem = fopen('php://memory', 'w+');
        self::assertNotFalse($fp_rmem);
        $rmem_exporter->export([['k' => 'v']], null, null, $fp_rmem);
        rewind($fp_rmem);
        $rmem_json = stream_get_contents($fp_rmem);
        fclose($fp_rmem);

        $db_decoded = json_decode((string)$db_json, true);
        $rmem_decoded = json_decode((string)$rmem_json, true);

        self::assertNotNull($db_decoded, 'DB JSON must decode');
        self::assertNotNull($rmem_decoded, 'Rmem JSON must decode');
        self::assertSame(
            $this->normaliseTree($db_decoded),
            $this->normaliseTree($rmem_decoded),
            'Rmem and DB exporters must agree on the same emitted tree',
        );
    }

    /**
     * Emit the same node/edge sequence to both PdoContextTreeSink (→ SQLite)
     * and BinaryContextTreeSink (→ rmem) so the two outputs can be compared.
     */
    private function buildBothFixtures(): void
    {
        $pdo_driver = new SqliteDriver($this->db_path);
        $pdo_db = $pdo_driver->createConnection();
        $pdo_driver->tuneForBulkInsert($pdo_db);

        $pdo_output = new PdoMemoryOutput($pdo_driver);
        // Bootstrap tables and runs row via createStreamingSink
        [$pdo_sink, $run_id, $pdo_db] = $pdo_output->createStreamingSink();

        $bin_sink = new BinaryContextTreeSink(batch_size: 10);

        $emit = function (
            int $node_id,
            ?int $parent_node_id,
            string $link_name,
            string $type,
            iterable $locations,
            array $attributes = [],
        ) use (
            $pdo_sink,
            $bin_sink
): void {
            $loc_arr = is_array($locations) ? $locations : iterator_to_array($locations);
            $pdo_sink->emitNode($node_id, $parent_node_id, $link_name, $type, $loc_arr, $attributes);
            $bin_sink->emitNode($node_id, $parent_node_id, $link_name, $type, $loc_arr, $attributes);
        };

        $emit(
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
        );
        $emit(
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
        );
        $emit(
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
        );
        $pdo_sink->emitReference(reference_node_id: 3, parent_node_id: 1, link_name: 'shared');
        $bin_sink->emitReference(reference_node_id: 3, parent_node_id: 1, link_name: 'shared');

        $pdo_output->finalizeStreaming($pdo_db, $run_id, $pdo_sink, [['k' => 'v']]);

        $bin_output = new BinaryMemoryOutput($this->rmem_path);
        $bin_output->finalizeStreaming($bin_sink, [['k' => 'v']]);
    }

    /**
     * Strip fields that legitimately differ between the two paths.
     *
     * The DB path stores `region` as NULL when the writer had no
     * RegionBoundaries; the rmem path likewise leaves region_id ==
     * NULL_STRING_ID. Both surface as null in JSON, so no normalisation is
     * needed for `region`. We do strip nothing for now and trust the
     * comparison; if drift is found, normalise here.
     *
     * @param mixed $tree
     * @return mixed
     */
    private function normaliseTree(mixed $tree): mixed
    {
        return $tree;
    }
}
