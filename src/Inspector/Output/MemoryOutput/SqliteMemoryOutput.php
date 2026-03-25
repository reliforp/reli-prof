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

final class SqliteMemoryOutput implements MemoryOutputInterface
{
    public function __construct(
        private string $output_path,
    ) {
    }

    public function output(MemoryAnalysisResult $result): void
    {
        $db = new \PDO('sqlite:' . $this->output_path);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA synchronous=NORMAL');

        $this->createTables($db);

        $db->beginTransaction();
        try {
            $this->insertSummary($db, $result->summary);
            $this->insertLocationTypesSummary($db, $result->location_types_summary);
            $this->insertClassObjectsSummary($db, $result->class_objects_summary);

            $sink = new PdoContextTreeSink($db);
            $analyzer = new ContextAnalyzer();
            $analyzer->analyze($result->context, $sink);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
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
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                node_id INTEGER NOT NULL,
                address INTEGER,
                size INTEGER,
                location_type TEXT NOT NULL,
                class_name TEXT,
                string_value TEXT,
                refcount INTEGER,
                type_info INTEGER,
                FOREIGN KEY (node_id) REFERENCES context_nodes(node_id)
            )
        ');

        $db->exec('
            CREATE TABLE IF NOT EXISTS context_node_attributes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                node_id INTEGER NOT NULL,
                key TEXT NOT NULL,
                value TEXT,
                FOREIGN KEY (node_id) REFERENCES context_nodes(node_id)
            )
        ');

        $db->exec('CREATE INDEX IF NOT EXISTS idx_context_nodes_parent ON context_nodes(parent_node_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_context_nodes_type ON context_nodes(type)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_context_node_locations_node ON context_node_locations(node_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_context_node_locations_class ON context_node_locations(class_name)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_context_node_locations_type ON context_node_locations(location_type)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_context_node_locations_size ON context_node_locations(size DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_context_node_attributes_node ON context_node_attributes(node_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_location_types_memory ON location_types_summary(memory_usage DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_class_objects_memory ON class_objects_summary(memory_usage DESC)');

        $this->createViews($db);
    }

    private function createViews(\PDO $db): void
    {
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

    /**
     * @param array<string, array{count: int, memory_usage: int}> $location_types_summary
     */
    private function insertLocationTypesSummary(\PDO $db, array $location_types_summary): void
    {
        $stmt = $db->prepare(
            'INSERT INTO location_types_summary (type, count, memory_usage) VALUES (:type, :count, :memory_usage)'
        );
        foreach ($location_types_summary as $type => $usage) {
            $stmt->execute([
                ':type' => $type,
                ':count' => $usage['count'],
                ':memory_usage' => $usage['memory_usage'],
            ]);
        }
    }

    /**
     * @param array<string, array{count: int, memory_usage: int}> $class_objects_summary
     */
    private function insertClassObjectsSummary(\PDO $db, array $class_objects_summary): void
    {
        $stmt = $db->prepare(
            'INSERT INTO class_objects_summary (class_name, count, memory_usage) VALUES (:class_name, :count, :memory_usage)'
        );
        foreach ($class_objects_summary as $class_name => $usage) {
            $stmt->execute([
                ':class_name' => $class_name,
                ':count' => $usage['count'],
                ':memory_usage' => $usage['memory_usage'],
            ]);
        }
    }
}
