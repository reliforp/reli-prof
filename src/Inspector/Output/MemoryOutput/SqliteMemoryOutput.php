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

use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ContextAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\PdoContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;

final class SqliteMemoryOutput implements MemoryOutputInterface
{
    public function __construct(
        private string $output_path,
        private ?RegionBoundaries $region_boundaries = null,
    ) {
    }

    public function output(MemoryAnalysisResult $result): void
    {
        $db = new \PDO('sqlite:' . $this->output_path);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode=OFF');
        $db->exec('PRAGMA synchronous=OFF');
        $db->exec('PRAGMA cache_size=-65536'); // 64MB cache
        $db->exec('PRAGMA temp_store=MEMORY');
        $db->exec('PRAGMA locking_mode=EXCLUSIVE');
        $db->exec('PRAGMA mmap_size=268435456'); // 256MB mmap

        $this->createTables($db);

        $db->beginTransaction();
        try {
            $this->insertSummary($db, $result->summary);

            // Emit context tree first — this writes all locations to DB
            // and allows the ReferenceContext tree to be GC'd incrementally
            $sink = new PdoContextTreeSink($db, $this->region_boundaries);
            $analyzer = new ContextAnalyzer();
            $analyzer->analyze($result->context, $sink);
            $sink->flush();

            // Compute type/class summaries from DB via GROUP BY
            $this->insertLocationTypesSummaryFromDb($db);
            $this->insertClassObjectsSummaryFromDb($db);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // Create indexes and views after bulk insert
        $this->createIndexes($db);
        $this->createViews($db);
    }

    private function createTables(\PDO $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS summary (
                key TEXT NOT NULL,
                value TEXT,
                PRIMARY KEY (key)
            )
        ');

        $db->exec('
            CREATE TABLE IF NOT EXISTS location_types_summary (
                type TEXT NOT NULL,
                count INTEGER NOT NULL,
                memory_usage INTEGER NOT NULL,
                PRIMARY KEY (type)
            )
        ');

        $db->exec('
            CREATE TABLE IF NOT EXISTS class_objects_summary (
                class_name TEXT NOT NULL,
                count INTEGER NOT NULL,
                memory_usage INTEGER NOT NULL,
                PRIMARY KEY (class_name)
            )
        ');

        $db->exec('
            CREATE TABLE IF NOT EXISTS context_nodes (
                node_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                PRIMARY KEY (node_id)
            )
        ');

        $db->exec('
            CREATE TABLE IF NOT EXISTS context_edges (
                parent_node_id INTEGER,
                child_node_id INTEGER NOT NULL,
                link_name TEXT NOT NULL,
                is_tree INTEGER NOT NULL
            )
        ');

        $db->exec('
            CREATE TABLE IF NOT EXISTS context_node_locations (
                id INTEGER PRIMARY KEY,
                node_id INTEGER NOT NULL,
                address INTEGER,
                size INTEGER,
                location_type TEXT NOT NULL,
                class_name TEXT,
                string_value TEXT,
                refcount INTEGER,
                type_info INTEGER,
                region TEXT,
                FOREIGN KEY (node_id) REFERENCES context_nodes(node_id)
            )
        ');

        $db->exec('
            CREATE TABLE IF NOT EXISTS context_node_attributes (
                id INTEGER PRIMARY KEY,
                node_id INTEGER NOT NULL,
                key TEXT NOT NULL,
                value TEXT,
                FOREIGN KEY (node_id) REFERENCES context_nodes(node_id)
            )
        ');
    }

    private function createIndexes(\PDO $db): void
    {
        // Essential for v_node_paths recursive CTE (follow tree edges from parent)
        $db->exec('CREATE INDEX IF NOT EXISTS idx_context_edges_parent_tree ON context_edges(parent_node_id, is_tree)');
        // Essential for "find all references to node X"
        $db->exec('CREATE INDEX IF NOT EXISTS idx_context_edges_child ON context_edges(child_node_id)');
        // Essential for JOIN context_node_locations ON node_id
        $db->exec('CREATE INDEX IF NOT EXISTS idx_context_node_locations_node ON context_node_locations(node_id)');
        // Frequently used in user queries
        $db->exec('CREATE INDEX IF NOT EXISTS idx_context_node_locations_class ON context_node_locations(class_name)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_context_node_attributes_node ON context_node_attributes(node_id)');
    }

    private function createViews(\PDO $db): void
    {
        // Canonical path view via recursive CTE following tree edges only.
        // Each node has exactly one path. For materialized version, use
        // inspector:memory:optimize-db.
        $db->exec("
            CREATE VIEW IF NOT EXISTS v_node_paths AS
            WITH RECURSIVE node_paths(node_id, path, depth) AS (
                SELECT child_node_id, link_name, 0
                FROM context_edges
                WHERE parent_node_id IS NULL AND is_tree = 1
              UNION ALL
                SELECT e.child_node_id, np.path || ' -> ' || e.link_name, np.depth + 1
                FROM context_edges e
                JOIN node_paths np ON e.parent_node_id = np.node_id
                WHERE e.is_tree = 1
            )
            SELECT node_id, path, depth FROM node_paths
        ");

        $db->exec("
            CREATE VIEW IF NOT EXISTS v_arrays AS
            SELECT
                header_cn.node_id,
                header_loc.address,
                header_loc.size AS header_size,
                COALESCE(table_loc.size, 0) AS table_size,
                header_loc.size + COALESCE(table_loc.size, 0) AS total_size,
                CAST(cnt.value AS INTEGER) AS element_count,
                header_loc.refcount
            FROM context_nodes header_cn
            JOIN context_node_locations header_loc
                ON header_loc.node_id = header_cn.node_id
                AND header_loc.location_type = 'ZendArrayMemoryLocation'
            LEFT JOIN context_edges elements_edge
                ON elements_edge.parent_node_id = header_cn.node_id
                AND elements_edge.link_name = 'array_elements'
                AND elements_edge.is_tree = 1
            LEFT JOIN context_node_locations table_loc
                ON table_loc.node_id = elements_edge.child_node_id
                AND table_loc.location_type = 'ZendArrayTableMemoryLocation'
            LEFT JOIN context_node_attributes cnt
                ON cnt.node_id = elements_edge.child_node_id
                AND cnt.key = '#count'
        ");
    }

    /**
     * @param array<int, array<string, mixed>> $summary
     */
    private function insertSummary(\PDO $db, array $summary): void
    {
        $stmt = $db->prepare('INSERT INTO summary (key, value) VALUES (:key, :value)');
        foreach ($summary as $entry) {
            foreach ($entry as $key => $value) {
                $stmt->execute([
                    ':key' => $key,
                    ':value' => is_scalar($value) ? (string)$value : json_encode($value),
                ]);
            }
        }
    }

    private function insertLocationTypesSummaryFromDb(\PDO $db): void
    {
        $db->exec("
            INSERT INTO location_types_summary (type, count, memory_usage)
            SELECT location_type, COUNT(*), SUM(size)
            FROM context_node_locations
            WHERE region IN ('zend_mm_heap', 'zend_mm_huge', 'vm_stack', 'compiler_arena')
               OR region IS NULL
            GROUP BY location_type
            ORDER BY SUM(size) DESC
        ");
    }

    private function insertClassObjectsSummaryFromDb(\PDO $db): void
    {
        $db->exec("
            INSERT INTO class_objects_summary (class_name, count, memory_usage)
            SELECT class_name, COUNT(*), SUM(size)
            FROM context_node_locations
            WHERE class_name IS NOT NULL
              AND (region IN ('zend_mm_heap', 'zend_mm_huge', 'vm_stack', 'compiler_arena')
                   OR region IS NULL)
            GROUP BY class_name
            ORDER BY SUM(size) DESC
        ");
    }
}
