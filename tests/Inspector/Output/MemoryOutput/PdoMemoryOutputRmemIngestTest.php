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
use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\BinaryContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\PdoContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;

/**
 * Parity test for PdoMemoryOutput::ingestFromRmem.
 *
 * For an identical emitted node/edge sequence, the rmem-ingest path
 * (collect into .rmem, then bulk-load into the target SQLite) must
 * produce a database whose every user-facing table is row-for-row
 * equivalent to the legacy direct-write path. The autoincrement
 * surrogate `id` columns on context_node_locations /
 * context_node_attributes / context_edges are excluded from the
 * comparison since their values are insertion-order artefacts.
 */
class PdoMemoryOutputRmemIngestTest extends BaseTestCase
{
    private string $direct_db_path;
    private string $ingested_db_path;
    private string $rmem_path;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $base = tempnam(sys_get_temp_dir(), 'reli_pdo_rmem_');
        self::assertNotFalse($base);
        $this->direct_db_path = $base . '_direct.sqlite3';
        $this->ingested_db_path = $base . '_ingest.sqlite3';
        $this->rmem_path = $base . '.rmem';
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->direct_db_path);
        @unlink($this->ingested_db_path);
        @unlink($this->rmem_path);
        parent::tearDown();
    }

    public function testIngestFromRmemMatchesDirectWriteForEachTable(): void
    {
        $summary = [['memory_get_usage' => 1024, 'php_version' => '8.4', 'zend_mm_heap_usage' => 512]];

        $this->buildDirectFixture($summary);
        $this->buildRmemFixture();
        $ingester = new PdoMemoryOutput(new SqliteDriver($this->ingested_db_path));
        $ingester->ingestFromRmem($this->rmem_path, $summary);

        $direct = new \PDO("sqlite:{$this->direct_db_path}");
        $direct->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $ingested = new \PDO("sqlite:{$this->ingested_db_path}");
        $ingested->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->assertTableEquals($direct, $ingested, 'context_nodes', ['run_id', 'node_id']);
        $this->assertTableEquals(
            $direct,
            $ingested,
            'context_edges',
            ['run_id', 'parent_node_id', 'child_node_id', 'link_name', 'is_tree', 'strength'],
        );
        $this->assertTableEquals(
            $direct,
            $ingested,
            'context_node_locations',
            [
                'run_id', 'node_id', 'address', 'size', 'location_type', 'class_name',
                'string_value', 'refcount', 'type_info', 'region',
            ],
        );
        $this->assertTableEquals(
            $direct,
            $ingested,
            'context_node_attributes',
            ['run_id', 'node_id', 'key', 'value'],
        );
        $this->assertTableEquals($direct, $ingested, 'summary', ['run_id', 'key', 'value']);
        $this->assertTableEquals(
            $direct,
            $ingested,
            'location_types_summary',
            ['run_id', 'type', 'count', 'memory_usage'],
        );
        $this->assertTableEquals(
            $direct,
            $ingested,
            'class_objects_summary',
            ['run_id', 'class_name', 'count', 'memory_usage'],
        );
    }

    /**
     * @param list<string> $columns columns to compare and order by
     */
    private function assertTableEquals(\PDO $a, \PDO $b, string $table, array $columns): void
    {
        $cols = implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', $columns));
        $sql = "SELECT {$cols} FROM {$table} ORDER BY {$cols}";
        $rows_a = $a->query($sql)?->fetchAll(\PDO::FETCH_ASSOC) ?? [];
        $rows_b = $b->query($sql)?->fetchAll(\PDO::FETCH_ASSOC) ?? [];
        self::assertSame(
            $rows_a,
            $rows_b,
            "Table {$table} differs between direct-write and rmem-ingest paths",
        );
    }

    /**
     * @param array<int, array<string, mixed>> $summary
     */
    private function buildDirectFixture(array $summary): void
    {
        $pdo_output = new PdoMemoryOutput(new SqliteDriver($this->direct_db_path));
        [$sink, $run_id, $db] = $pdo_output->createStreamingSink();
        $this->emitFixtureNodes($sink);
        $pdo_output->finalizeStreaming($db, $run_id, $sink, $summary);
    }

    private function buildRmemFixture(): void
    {
        $bin_sink = new BinaryContextTreeSink(batch_size: 4);
        $this->emitFixtureNodes($bin_sink);
        $bin_output = new BinaryMemoryOutput($this->rmem_path);
        $bin_output->finalizeStreaming(
            $bin_sink,
            [['memory_get_usage' => 1024, 'php_version' => '8.4', 'zend_mm_heap_usage' => 512]],
        );
    }

    /**
     * @param PdoContextTreeSink|BinaryContextTreeSink $sink
     */
    private function emitFixtureNodes(object $sink): void
    {
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
            attributes: ['source' => 'fixture'],
        );
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
        $sink->emitNode(
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
        // Two nodes sharing an address — exercises canonical_node_id
        // resolution on both paths.
        $sink->emitNode(
            node_id: 4,
            parent_node_id: 1,
            link_name: 'alias_a',
            type: 'ObjectContext',
            locations: [new ZendObjectMemoryLocation(
                address: 0x4000,
                size: 80,
                refcount: 1,
                type_info: 7,
                class_name: 'App\\Shared',
            )],
            attributes: [],
        );
        $sink->emitNode(
            node_id: 5,
            parent_node_id: 1,
            link_name: 'alias_b',
            type: 'ObjectContext',
            locations: [new ZendObjectMemoryLocation(
                address: 0x4000,
                size: 80,
                refcount: 1,
                type_info: 7,
                class_name: 'App\\Shared',
            )],
            attributes: [],
        );
        $sink->emitReference(reference_node_id: 3, parent_node_id: 5, link_name: 'shared_str');
    }
}
