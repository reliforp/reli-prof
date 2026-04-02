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

/**
 * Streams JSON output from a SQLite database by DFS traversal.
 * Memory usage is O(tree depth) rather than O(total nodes).
 */
final class StreamingJsonFromDbExporter
{
    private \PDOStatement $tree_children_stmt;
    private \PDOStatement $locations_stmt;
    private \PDOStatement $attributes_stmt;

    private int $json_flags;

    public function __construct(
        private \PDO $db,
        private int $run_id,
        bool $pretty_print = false,
    ) {
        $this->tree_children_stmt = $db->prepare(
            'SELECT child_node_id, link_name, is_tree'
            . ' FROM context_edges'
            . ' WHERE run_id = ? AND parent_node_id IS NULL'
            . ' ORDER BY rowid'
        );
        $this->locations_stmt = $db->prepare(
            'SELECT address, size, location_type, class_name, string_value, refcount, type_info, region'
            . ' FROM context_node_locations'
            . ' WHERE run_id = ? AND node_id = ?'
        );
        $this->attributes_stmt = $db->prepare(
            'SELECT "key", "value" FROM context_node_attributes WHERE run_id = ? AND node_id = ?'
        );

        $this->json_flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        if ($pretty_print) {
            $this->json_flags |= JSON_PRETTY_PRINT;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $summary
     * @param array<string, array{count: int, memory_usage: int}>|null $location_types_summary
     * @param array<string, array{count: int, memory_usage: int}>|null $class_objects_summary
     * @param resource $output
     */
    public function export(
        array $summary,
        ?array $location_types_summary,
        ?array $class_objects_summary,
        $output,
    ): void {
        fwrite($output, '{');
        fwrite($output, '"summary":' . $this->jsonEncode($summary));
        fwrite($output, ',"location_types_summary":' . $this->jsonEncode($location_types_summary ?? []));
        fwrite($output, ',"class_objects_summary":' . $this->jsonEncode($class_objects_summary ?? []));
        fwrite($output, ',"context":');
        $this->exportRootContext($output);
        fwrite($output, '}');
    }

    /** @param resource $output */
    private function exportRootContext($output): void
    {
        fwrite($output, '{');
        $this->tree_children_stmt->execute([$this->run_id]);
        $first = true;
        while (
            /** @var array{child_node_id: int, link_name: string, is_tree: int} */
            $row = $this->tree_children_stmt->fetch(\PDO::FETCH_ASSOC)
        ) {
            if (!$first) {
                fwrite($output, ',');
            }
            $first = false;
            /** @psalm-suppress MixedArrayAccess, MixedArgument */
            fwrite($output, $this->jsonEncode($row['link_name']) . ':');
            /** @psalm-suppress MixedArrayAccess */
            if ($row['is_tree']) {
                /** @psalm-suppress MixedArrayAccess, MixedArgument */
                $this->exportNode($row['child_node_id'], $output);
            } else {
                /** @psalm-suppress MixedArrayAccess */
                fwrite($output, $this->jsonEncode(['#reference_node_id' => $row['child_node_id']]));
            }
        }
        fwrite($output, '}');
    }

    /** @param resource $output */
    private function exportNode(int $node_id, $output): void
    {
        $type_stmt = $this->db->prepare(
            'SELECT type FROM context_nodes WHERE run_id = ? AND node_id = ?'
        );
        $type_stmt->execute([$this->run_id, $node_id]);
        /** @var string $type */
        $type = $type_stmt->fetchColumn();

        fwrite($output, '{');
        fwrite($output, '"#node_id":' . $node_id);
        fwrite($output, ',"#type":' . $this->jsonEncode($type));

        // Locations
        $this->locations_stmt->execute([$this->run_id, $node_id]);
        /** @var list<array<string, mixed>> $locations */
        $locations = $this->locations_stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($locations !== []) {
            fwrite($output, ',"#locations":' . $this->jsonEncode($locations));
        }

        // Attributes
        $this->attributes_stmt->execute([$this->run_id, $node_id]);
        while (
            /** @var array{key: string, value: string|null} */
            $attr = $this->attributes_stmt->fetch(\PDO::FETCH_ASSOC)
        ) {
            /** @psalm-suppress MixedArrayAccess, MixedAssignment */
            $decoded = json_decode((string)$attr['value']);
            /** @psalm-suppress MixedArrayAccess, MixedAssignment */
            $value = $decoded !== null ? $decoded : $attr['value'];
            /** @psalm-suppress MixedArrayAccess, MixedArgument */
            fwrite($output, ',' . $this->jsonEncode($attr['key']) . ':' . $this->jsonEncode($value));
        }

        // Children (tree edges + reference edges)
        $children_stmt = $this->db->prepare(
            'SELECT child_node_id, link_name, is_tree'
            . ' FROM context_edges'
            . ' WHERE run_id = ? AND parent_node_id = ?'
            . ' ORDER BY rowid'
        );
        $children_stmt->execute([$this->run_id, $node_id]);
        while (
            /** @var array{child_node_id: int, link_name: string, is_tree: int} */
            $child = $children_stmt->fetch(\PDO::FETCH_ASSOC)
        ) {
            /** @psalm-suppress MixedArrayAccess, MixedArgument */
            fwrite($output, ',' . $this->jsonEncode($child['link_name']) . ':');
            /** @psalm-suppress MixedArrayAccess */
            if ($child['is_tree']) {
                /** @psalm-suppress MixedArrayAccess, MixedArgument */
                $this->exportNode($child['child_node_id'], $output);
            } else {
                /** @psalm-suppress MixedArrayAccess */
                fwrite($output, $this->jsonEncode(['#reference_node_id' => $child['child_node_id']]));
            }
        }

        fwrite($output, '}');
    }

    private function jsonEncode(mixed $value): string
    {
        $result = json_encode($value, $this->json_flags);
        if ($result === false) {
            throw new \RuntimeException('JSON encoding failed: ' . json_last_error_msg());
        }
        return $result;
    }
}
