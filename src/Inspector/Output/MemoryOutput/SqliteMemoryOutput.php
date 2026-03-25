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

        // Create indexes and materialized tables after bulk insert
        $this->createIndexes($db);
        $this->materializeNodePaths($db);
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
                parent_node_id INTEGER,
                link_name TEXT NOT NULL,
                type TEXT,
                reference_node_id INTEGER,
                PRIMARY KEY (node_id)
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
        // Essential for v_node_paths recursive CTE and v_arrays join
        $db->exec('CREATE INDEX IF NOT EXISTS idx_context_nodes_parent ON context_nodes(parent_node_id)');
        // Essential for JOIN context_node_locations ON node_id
        $db->exec('CREATE INDEX IF NOT EXISTS idx_context_node_locations_node ON context_node_locations(node_id)');
        // Frequently used in user queries
        $db->exec('CREATE INDEX IF NOT EXISTS idx_context_node_locations_class ON context_node_locations(class_name)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_context_node_attributes_node ON context_node_attributes(node_id)');
    }

    private function materializeNodePaths(\PDO $db): void
    {
        // Materialize shallow paths (depth <= 4) as a table.
        // Deeper paths can still be computed via recursive CTE when needed,
        // but most useful queries (root-level memory breakdown, top-level
        // structure analysis) only need shallow paths.
        $db->exec('
            CREATE TABLE IF NOT EXISTS node_paths (
                node_id INTEGER NOT NULL PRIMARY KEY,
                path TEXT NOT NULL,
                depth INTEGER NOT NULL
            )
        ');
        $db->exec("
            INSERT INTO node_paths (node_id, path, depth)
            WITH RECURSIVE cte(node_id, path, depth) AS (
                SELECT node_id, link_name, 0
                FROM context_nodes
                WHERE parent_node_id IS NULL
              UNION ALL
                SELECT cn.node_id, cte.path || ' -> ' || cn.link_name, cte.depth + 1
                FROM context_nodes cn
                JOIN cte ON cn.parent_node_id = cte.node_id
                WHERE cte.depth < 4
            )
            SELECT node_id, path, depth FROM cte
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_node_paths_depth ON node_paths(depth)');
    }

    private function createViews(\PDO $db): void
    {
        // Full-depth path view via recursive CTE (slower but complete).
        // For depth <= 4, prefer the materialized node_paths table.
        $db->exec("
            CREATE VIEW IF NOT EXISTS v_node_paths AS
            WITH RECURSIVE node_paths(node_id, path, depth) AS (
                SELECT node_id, link_name, 0
                FROM context_nodes
                WHERE parent_node_id IS NULL
              UNION ALL
                SELECT cn.node_id, np.path || ' -> ' || cn.link_name, np.depth + 1
                FROM context_nodes cn
                JOIN node_paths np ON cn.parent_node_id = np.node_id
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
            LEFT JOIN context_nodes elements_cn
                ON elements_cn.parent_node_id = header_cn.node_id
                AND elements_cn.link_name = 'array_elements'
            LEFT JOIN context_node_locations table_loc
                ON table_loc.node_id = elements_cn.node_id
                AND table_loc.location_type = 'ZendArrayTableMemoryLocation'
            LEFT JOIN context_node_attributes cnt
                ON cnt.node_id = elements_cn.node_id
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
