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
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;

/**
 * End-to-end test for multi-run-into-same-DB write, restoring the
 * 0.12.0 capability that round-2 SQLite-output validation
 * (`docs/internals/pr-773-sqlite-output-validation-round2.md`)
 * flagged as a regression introduced by #773.
 *
 * Drives `PdoMemoryOutput::ingestFromRmem` twice against the same
 * SQLite output path and asserts:
 *
 * - both runs land cleanly (`PRAGMA integrity_check = ok`)
 * - the `runs` table contains exactly two rows (`run_id = 1, 2`)
 * - per-run row counts in the four context tables are equal across
 *   `run_id=1` and `run_id=2` (both runs are byte-identical
 *   captures, so the counts must match exactly)
 * - the canonical run-1 rows from the first ingest are still
 *   reachable after the second ingest (the bug shape this guards
 *   against is the parallel-shard `TableMerger` overwriting the
 *   table rootpage with shard-only leaves and stranding the run-1
 *   data in orphan pages)
 *
 * Pre-fix the second `ingestFromRmem` call dies with
 * `sqlite_master root is not a leaf (type 0x05); not yet supported`
 * from `SqliteRaw\Reader::loadSqliteMaster`; even if that's bypassed
 * by routing only the reader fix without the dispatch change, the
 * format-direct merge would silently drop the run-1 rows, which the
 * per-run-id row count assertion catches.
 */
class PdoMemoryOutputMultiRunIngestTest extends BaseTestCase
{
    private string $db_path;
    private string $rmem_path;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $base = tempnam(sys_get_temp_dir(), 'reli_pdo_multirun_');
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

    public function testSecondIngestAppendsRunInsteadOfOverwriting(): void
    {
        $summary = [['memory_get_usage' => 1024, 'php_version' => '8.4', 'zend_mm_heap_usage' => 512]];

        $this->buildRmemFixture();

        $first = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        $first->ingestFromRmem($this->rmem_path, $summary);

        // Capture per-run-id row counts after the first ingest so the
        // multi-run assertion can compare run-2 against the same
        // baseline rather than against a hardcoded number.
        $db = new \PDO("sqlite:{$this->db_path}");
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $run1_counts = $this->perRunCounts($db, 1);
        unset($db);

        $second = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        $second->ingestFromRmem($this->rmem_path, $summary);

        $db = new \PDO("sqlite:{$this->db_path}");
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $integrity = $db->query('PRAGMA integrity_check')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['ok'], $integrity);

        $run_ids = $db->query('SELECT run_id FROM runs ORDER BY run_id')
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([1, 2], array_map(static fn ($v): int => (int)$v, $run_ids));

        // The fixture is identical for both ingests, so per-table
        // counts under run_id=1 and run_id=2 must agree exactly. If
        // the parallel paths slipped past the dispatch guard and
        // overwrote the table rootpage with shard-only leaves, the
        // run_id=1 column would be 0 here.
        $run2_counts = $this->perRunCounts($db, 2);
        self::assertSame(
            $run1_counts,
            $run2_counts,
            'run-2 row counts must match run-1 (fixture is identical)',
        );
        foreach ($run1_counts as $table => $count) {
            self::assertGreaterThan(
                0,
                $count,
                "{$table} must have at least one row per run for the test to be meaningful",
            );
            $still_there = (int)$db->query(
                "SELECT COUNT(*) FROM {$table} WHERE run_id = 1"
            )->fetchColumn();
            self::assertSame(
                $count,
                $still_there,
                "{$table}: run_id=1 rows from the first ingest must survive the second ingest",
            );
        }
    }

    /**
     * @return array<string, int> table => row count for the given run_id
     */
    private function perRunCounts(\PDO $db, int $run_id): array
    {
        $tables = ['context_nodes', 'context_edges', 'context_node_locations', 'context_node_attributes'];
        $counts = [];
        foreach ($tables as $table) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE run_id = :run_id");
            $stmt->execute(['run_id' => $run_id]);
            $counts[$table] = (int)$stmt->fetchColumn();
        }
        return $counts;
    }

    private function buildRmemFixture(): void
    {
        $sink = new BinaryContextTreeSink(batch_size: 4);
        // Same fixture shape as PdoMemoryOutputRmemIngestTest so the
        // schema all four context tables get exercised.
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
            attributes: ['key1' => 'a', 'key2' => 'b'],
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
        $output = new BinaryMemoryOutput($this->rmem_path);
        $output->finalizeStreaming(
            $sink,
            [['memory_get_usage' => 1024, 'php_version' => '8.4', 'zend_mm_heap_usage' => 512]],
        );
    }
}
