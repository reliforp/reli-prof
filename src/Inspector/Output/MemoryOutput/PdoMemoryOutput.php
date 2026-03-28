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

use Reli\Inspector\Output\MemoryOutput\PdoDriver\PdoDriverInterface;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ContextAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ParallelContextAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\PdoContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;

final class PdoMemoryOutput implements MemoryOutputInterface
{
    public function __construct(
        private PdoDriverInterface $driver,
        private ?RegionBoundaries $region_boundaries = null,
        private bool $parallel = false,
    ) {
    }

    #[\Override]
    public function output(MemoryAnalysisResult $result): void
    {
        $db = $this->driver->createConnection();
        $this->driver->tuneForBulkInsert($db);

        $this->createTables($db);

        $db->beginTransaction();
        try {
            $run_id = $this->insertRun($db);
            $this->insertSummary($db, $run_id, $result->summary);

            $sink = new PdoContextTreeSink($db, $this->driver, $run_id, $this->region_boundaries);

            if ($this->parallel && ParallelContextAnalyzer::isAvailable()) {
                $parallel = new ParallelContextAnalyzer();
                $parallel->analyze($result->context, $sink, $this->region_boundaries);
            } else {
                $analyzer = new ContextAnalyzer();
                $analyzer->analyze($result->context, $sink);
                $sink->flush();
            }

            $this->insertLocationTypesSummaryFromDb($db, $run_id);
            $this->insertClassObjectsSummaryFromDb($db, $run_id);

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $this->driver->afterBulkInsert($db);
        $this->createIndexes($db);
        $this->createViews($db);
    }

    private function createTables(\PDO $db): void
    {
        $autoId = $this->driver->autoIncrementPrimaryKey();
        $qi = fn (string $id): string => $this->driver->quoteIdentifier($id);
        $pkText = $this->driver->primaryKeyTextType();

        $db->exec("
            CREATE TABLE IF NOT EXISTS runs (
                run_id {$autoId},
                created_at TEXT NOT NULL
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS summary (
                run_id INTEGER NOT NULL,
                {$qi('key')} {$pkText} NOT NULL,
                {$qi('value')} TEXT,
                PRIMARY KEY (run_id, {$qi('key')})
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS location_types_summary (
                run_id INTEGER NOT NULL,
                {$qi('type')} {$pkText} NOT NULL,
                {$qi('count')} INTEGER NOT NULL,
                memory_usage INTEGER NOT NULL,
                PRIMARY KEY (run_id, {$qi('type')})
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS class_objects_summary (
                run_id INTEGER NOT NULL,
                class_name {$pkText} NOT NULL,
                {$qi('count')} INTEGER NOT NULL,
                memory_usage INTEGER NOT NULL,
                PRIMARY KEY (run_id, class_name)
            )
        ");

        $db->exec('
            CREATE TABLE IF NOT EXISTS context_nodes (
                run_id INTEGER NOT NULL,
                node_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                PRIMARY KEY (run_id, node_id)
            )
        ');

        $db->exec('
            CREATE TABLE IF NOT EXISTS context_edges (
                run_id INTEGER NOT NULL,
                parent_node_id INTEGER,
                child_node_id INTEGER NOT NULL,
                link_name TEXT NOT NULL,
                is_tree INTEGER NOT NULL
            )
        ');

        $db->exec("
            CREATE TABLE IF NOT EXISTS context_node_locations (
                id {$autoId},
                run_id INTEGER NOT NULL,
                node_id INTEGER NOT NULL,
                address BIGINT,
                size BIGINT,
                location_type TEXT NOT NULL,
                class_name TEXT,
                string_value TEXT,
                refcount BIGINT,
                type_info BIGINT,
                region TEXT
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS context_node_attributes (
                id {$autoId},
                run_id INTEGER NOT NULL,
                node_id INTEGER NOT NULL,
                {$qi('key')} TEXT NOT NULL,
                {$qi('value')} TEXT
            )
        ");
    }

    private function createIndexes(\PDO $db): void
    {
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_context_edges_run_parent_tree'
            . ' ON context_edges(run_id, parent_node_id, is_tree)'
        );
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_context_edges_run_child'
            . ' ON context_edges(run_id, child_node_id)'
        );
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_context_node_locations_run_node'
            . ' ON context_node_locations(run_id, node_id)'
        );
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_context_node_locations_run_class'
            . ' ON context_node_locations(run_id, class_name)'
        );
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_context_node_attributes_run_node'
            . ' ON context_node_attributes(run_id, node_id)'
        );
    }

    private function createViews(\PDO $db): void
    {
        $concat = fn (string $a, string $b): string => $this->driver->concatExpr($a, $b);
        $createView = fn (string $name): string => $this->driver->createViewSql($name);
        $qi = fn (string $id): string => $this->driver->quoteIdentifier($id);

        $pathExpr = $concat('np.path', $concat("' -> '", 'e.link_name'));

        $db->exec("
            {$createView('v_node_paths')}
            WITH RECURSIVE node_paths(run_id, node_id, path, depth) AS (
                SELECT run_id, child_node_id, link_name, 0
                FROM context_edges
                WHERE parent_node_id IS NULL AND is_tree = 1
              UNION ALL
                SELECT np.run_id, e.child_node_id, {$pathExpr}, np.depth + 1
                FROM context_edges e
                JOIN node_paths np ON e.parent_node_id = np.node_id AND e.run_id = np.run_id
                WHERE e.is_tree = 1
            )
            SELECT run_id, node_id, path, depth FROM node_paths
        ");

        $db->exec("
            {$createView('v_arrays')}
            SELECT
                header_cn.run_id,
                header_cn.node_id,
                header_loc.address,
                header_loc.size AS header_size,
                COALESCE(table_loc.size, 0) AS table_size,
                header_loc.size + COALESCE(table_loc.size, 0) AS total_size,
                {$this->driver->castAsInteger("cnt.{$qi('value')}")} AS element_count,
                header_loc.refcount
            FROM context_nodes header_cn
            JOIN context_node_locations header_loc
                ON header_loc.run_id = header_cn.run_id
                AND header_loc.node_id = header_cn.node_id
                AND header_loc.location_type = 'ZendArrayMemoryLocation'
            LEFT JOIN context_edges elements_edge
                ON elements_edge.run_id = header_cn.run_id
                AND elements_edge.parent_node_id = header_cn.node_id
                AND elements_edge.link_name = 'array_elements'
                AND elements_edge.is_tree = 1
            LEFT JOIN context_node_locations table_loc
                ON table_loc.run_id = elements_edge.run_id
                AND table_loc.node_id = elements_edge.child_node_id
                AND table_loc.location_type = 'ZendArrayTableMemoryLocation'
            LEFT JOIN context_node_attributes cnt
                ON cnt.run_id = elements_edge.run_id
                AND cnt.node_id = elements_edge.child_node_id
                AND cnt.{$qi('key')} = '#count'
        ");
    }

    private function insertRun(\PDO $db): int
    {
        $db->exec("INSERT INTO runs (created_at) VALUES ('" . gmdate('Y-m-d\TH:i:s\Z') . "')");
        return (int)$db->lastInsertId();
    }

    /**
     * @param array<int, array<string, mixed>> $summary
     */
    private function insertSummary(\PDO $db, int $run_id, array $summary): void
    {
        $qi = fn (string $id): string => $this->driver->quoteIdentifier($id);
        $stmt = $db->prepare(
            "INSERT INTO summary (run_id, {$qi('key')}, {$qi('value')}) VALUES (:run_id, :key, :value)"
        );
        foreach ($summary as $entry) {
            /** @psalm-suppress MixedAssignment -- $entry is array<string, mixed> */
            foreach ($entry as $key => $value) {
                $string_value = is_scalar($value) ? (string)$value : json_encode($value);
                assert(is_string($string_value));
                $stmt->execute([
                    ':run_id' => $run_id,
                    ':key' => $key,
                    ':value' => $string_value,
                ]);
            }
        }
    }

    private function insertLocationTypesSummaryFromDb(\PDO $db, int $run_id): void
    {
        $qi = fn (string $id): string => $this->driver->quoteIdentifier($id);
        $stmt = $db->prepare("
            INSERT INTO location_types_summary (run_id, {$qi('type')}, {$qi('count')}, memory_usage)
            SELECT ?, location_type, COUNT(*), SUM(size)
            FROM context_node_locations
            WHERE run_id = ?
              AND (region IN ('zend_mm_heap', 'zend_mm_huge', 'vm_stack', 'compiler_arena')
                   OR region IS NULL)
            GROUP BY location_type
            ORDER BY SUM(size) DESC
        ");
        $stmt->execute([$run_id, $run_id]);
    }

    private function insertClassObjectsSummaryFromDb(\PDO $db, int $run_id): void
    {
        $qi = fn (string $id): string => $this->driver->quoteIdentifier($id);
        $stmt = $db->prepare("
            INSERT INTO class_objects_summary (run_id, class_name, {$qi('count')}, memory_usage)
            SELECT ?, class_name, COUNT(*), SUM(size)
            FROM context_node_locations
            WHERE run_id = ?
              AND class_name IS NOT NULL
              AND (region IN ('zend_mm_heap', 'zend_mm_huge', 'vm_stack', 'compiler_arena')
                   OR region IS NULL)
            GROUP BY class_name
            ORDER BY SUM(size) DESC
        ");
        $stmt->execute([$run_id, $run_id]);
    }
}
