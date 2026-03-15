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

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\RefcountedMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\Process\MemoryLocation;
use SQLite3;

final class SqliteMemoryOutput implements MemoryOutputInterface
{
    public function __construct(
        private string $output_path,
    ) {
    }

    public function output(MemoryAnalysisResult $result): void
    {
        $db = new SQLite3($this->output_path);
        $db->enableExceptions(true);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA synchronous=NORMAL');

        $this->createTables($db);

        $db->exec('BEGIN TRANSACTION');
        try {
            $this->insertSummary($db, $result->summary);
            $this->insertLocationTypesSummary($db, $result->location_types_summary);
            $this->insertClassObjectsSummary($db, $result->class_objects_summary);
            $this->insertContext($db, $result->context);
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        } finally {
            $db->close();
        }
    }

    private function createTables(SQLite3 $db): void
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

    private function createViews(SQLite3 $db): void
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
    }

    /**
     * @param array<int, array<string, mixed>> $summary
     */
    private function insertSummary(SQLite3 $db, array $summary): void
    {
        $stmt = $db->prepare('INSERT INTO summary (key, value) VALUES (:key, :value)');
        foreach ($summary as $entry) {
            foreach ($entry as $key => $value) {
                $stmt->bindValue(':key', $key, SQLITE3_TEXT);
                $stmt->bindValue(':value', is_scalar($value) ? (string)$value : json_encode($value), SQLITE3_TEXT);
                $stmt->execute();
                $stmt->reset();
            }
        }
    }

    /**
     * @param array<string, array{count: int, memory_usage: int}> $location_types_summary
     */
    private function insertLocationTypesSummary(SQLite3 $db, array $location_types_summary): void
    {
        $stmt = $db->prepare(
            'INSERT INTO location_types_summary (type, count, memory_usage) VALUES (:type, :count, :memory_usage)'
        );
        foreach ($location_types_summary as $type => $usage) {
            $stmt->bindValue(':type', $type, SQLITE3_TEXT);
            $stmt->bindValue(':count', $usage['count'], SQLITE3_INTEGER);
            $stmt->bindValue(':memory_usage', $usage['memory_usage'], SQLITE3_INTEGER);
            $stmt->execute();
            $stmt->reset();
        }
    }

    /**
     * @param array<string, array{count: int, memory_usage: int}> $class_objects_summary
     */
    private function insertClassObjectsSummary(SQLite3 $db, array $class_objects_summary): void
    {
        $stmt = $db->prepare(
            'INSERT INTO class_objects_summary (class_name, count, memory_usage) VALUES (:class_name, :count, :memory_usage)'
        );
        foreach ($class_objects_summary as $class_name => $usage) {
            $stmt->bindValue(':class_name', $class_name, SQLITE3_TEXT);
            $stmt->bindValue(':count', $usage['count'], SQLITE3_INTEGER);
            $stmt->bindValue(':memory_usage', $usage['memory_usage'], SQLITE3_INTEGER);
            $stmt->execute();
            $stmt->reset();
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function insertContext(SQLite3 $db, array $context): void
    {
        $node_stmt = $db->prepare(
            'INSERT OR IGNORE INTO context_nodes (node_id, parent_node_id, link_name, type, reference_node_id)'
            . ' VALUES (:node_id, :parent_node_id, :link_name, :type, :reference_node_id)'
        );
        $location_stmt = $db->prepare(
            'INSERT INTO context_node_locations'
            . ' (node_id, address, size, location_type, class_name, string_value, refcount, type_info)'
            . ' VALUES (:node_id, :address, :size, :location_type, :class_name, :string_value, :refcount, :type_info)'
        );
        $attr_stmt = $db->prepare(
            'INSERT INTO context_node_attributes (node_id, key, value)'
            . ' VALUES (:node_id, :key, :value)'
        );

        $this->insertContextNodes($node_stmt, $location_stmt, $attr_stmt, $context, null);
    }

    private function insertContextNodes(
        \SQLite3Stmt $node_stmt,
        \SQLite3Stmt $location_stmt,
        \SQLite3Stmt $attr_stmt,
        array $data,
        ?int $parent_node_id,
    ): void {
        foreach ($data as $link_name => $node) {
            if (!is_array($node)) {
                continue;
            }

            if (isset($node['#reference_node_id'])) {
                // This is a reference to another node
                $ref_node_id = $node['#reference_node_id'];
                $node_stmt->bindValue(':node_id', $ref_node_id, SQLITE3_INTEGER);
                $node_stmt->bindValue(
                    ':parent_node_id',
                    $parent_node_id,
                    $parent_node_id !== null ? SQLITE3_INTEGER : SQLITE3_NULL
                );
                $node_stmt->bindValue(':link_name', (string)$link_name, SQLITE3_TEXT);
                $node_stmt->bindValue(':type', null, SQLITE3_NULL);
                $node_stmt->bindValue(':reference_node_id', $ref_node_id, SQLITE3_INTEGER);
                $node_stmt->execute();
                $node_stmt->reset();
                continue;
            }

            if (!isset($node['#node_id'])) {
                continue;
            }

            $node_id = $node['#node_id'];

            $node_stmt->bindValue(':node_id', $node_id, SQLITE3_INTEGER);
            $node_stmt->bindValue(
                ':parent_node_id',
                $parent_node_id,
                $parent_node_id !== null ? SQLITE3_INTEGER : SQLITE3_NULL
            );
            $node_stmt->bindValue(':link_name', (string)$link_name, SQLITE3_TEXT);
            $node_stmt->bindValue(':type', $node['#type'] ?? null, SQLITE3_TEXT);
            $node_stmt->bindValue(':reference_node_id', null, SQLITE3_NULL);
            $node_stmt->execute();
            $node_stmt->reset();

            // Insert locations
            if (isset($node['#locations']) && is_iterable($node['#locations'])) {
                foreach ($node['#locations'] as $location) {
                    if ($location instanceof MemoryLocation) {
                        $location_stmt->bindValue(':node_id', $node_id, SQLITE3_INTEGER);
                        $location_stmt->bindValue(':address', $location->address, SQLITE3_INTEGER);
                        $location_stmt->bindValue(':size', $location->size, SQLITE3_INTEGER);

                        $short_class = (new \ReflectionClass($location))->getShortName();
                        $location_stmt->bindValue(':location_type', $short_class, SQLITE3_TEXT);

                        if ($location instanceof ZendObjectMemoryLocation) {
                            $location_stmt->bindValue(':class_name', $location->class_name, SQLITE3_TEXT);
                        } else {
                            $location_stmt->bindValue(':class_name', null, SQLITE3_NULL);
                        }

                        if ($location instanceof ZendStringMemoryLocation) {
                            $location_stmt->bindValue(':string_value', $location->value, SQLITE3_TEXT);
                        } else {
                            $location_stmt->bindValue(':string_value', null, SQLITE3_NULL);
                        }

                        if ($location instanceof RefcountedMemoryLocation) {
                            $location_stmt->bindValue(':refcount', $location->refcount, SQLITE3_INTEGER);
                            $location_stmt->bindValue(':type_info', $location->type_info, SQLITE3_INTEGER);
                        } else {
                            $location_stmt->bindValue(':refcount', null, SQLITE3_NULL);
                            $location_stmt->bindValue(':type_info', null, SQLITE3_NULL);
                        }

                        $location_stmt->execute();
                        $location_stmt->reset();
                    }
                }
            }

            // Insert child contexts (non-# prefixed keys that are arrays)
            $children = [];
            foreach ($node as $key => $value) {
                if (is_string($key) && !str_starts_with($key, '#') && is_array($value)) {
                    // Check if this is a context node or an attribute
                    if (isset($value['#node_id']) || isset($value['#reference_node_id'])) {
                        $children[$key] = $value;
                    } else {
                        // Could be nested children or a scalar attribute encoded as array
                        $has_node_children = false;
                        foreach ($value as $sub_key => $sub_value) {
                            if (is_array($sub_value) && (isset($sub_value['#node_id']) || isset($sub_value['#reference_node_id']))) {
                                $has_node_children = true;
                                break;
                            }
                        }
                        if ($has_node_children) {
                            $children[$key] = $value;
                        } else {
                            $attr_stmt->bindValue(':node_id', $node_id, SQLITE3_INTEGER);
                            $attr_stmt->bindValue(':key', $key, SQLITE3_TEXT);
                            $attr_stmt->bindValue(':value', json_encode($value), SQLITE3_TEXT);
                            $attr_stmt->execute();
                            $attr_stmt->reset();
                        }
                    }
                } elseif (is_int($key) && is_array($value)) {
                    if (isset($value['#node_id']) || isset($value['#reference_node_id'])) {
                        $children[$key] = $value;
                    }
                }
            }

            if ($children !== []) {
                $this->insertContextNodes($node_stmt, $location_stmt, $attr_stmt, $children, $node_id);
            }
        }
    }
}
