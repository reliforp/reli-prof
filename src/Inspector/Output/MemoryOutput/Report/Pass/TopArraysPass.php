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

namespace Reli\Inspector\Output\MemoryOutput\Report\Pass;

use Reli\Inspector\Output\MemoryOutput\Report\Finding;
use Reli\Inspector\Output\MemoryOutput\Report\FindingConfidence;
use Reli\Inspector\Output\MemoryOutput\Report\FindingSeverity;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\NodeLabeler;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\PathFormatter;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\SizeFormatter;

final class TopArraysPass implements PassInterface
{
    public function __construct(
        private \PDO $db,
        private int $run_id,
        private ?GraphSubstrate $substrate = null,
    ) {
    }

    /**
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedOperand
     * @psalm-suppress InvalidOperand, PossiblyInvalidArgument, InvalidArgument
     */
    #[\Override]
    public function analyze(): array
    {
        // First pick the 10 largest arrays, THEN resolve ancestors.
        // Doing ancestor LEFT JOINs over the full v_arrays view caused
        // the query to hang on large heaps because SQLite had to build
        // and sort the entire joined result set before applying LIMIT.
        $rows = $this->db->query("
            WITH top AS (
                SELECT va.node_id, va.total_size, va.element_count
                FROM v_arrays va
                WHERE va.run_id = {$this->run_id}
                ORDER BY va.total_size DESC
                LIMIT 10
            )
            SELECT
                top.node_id,
                top.total_size,
                top.element_count,
                e1.link_name as link1,
                e1.parent_node_id as parent1_id,
                cnl_p1.class_name as parent1_class,
                e2.link_name as link2,
                e2.parent_node_id as parent2_id,
                e3.link_name as link3
            FROM top
            LEFT JOIN context_edges e1
                ON e1.child_node_id = top.node_id
                AND e1.is_tree = 1
                AND e1.run_id = {$this->run_id}
            LEFT JOIN context_node_locations cnl_p1
                ON cnl_p1.node_id = e1.parent_node_id
                AND cnl_p1.run_id = {$this->run_id}
            LEFT JOIN context_edges e2
                ON e2.child_node_id = e1.parent_node_id
                AND e2.is_tree = 1
                AND e2.run_id = {$this->run_id}
            LEFT JOIN context_edges e3
                ON e3.child_node_id = e2.parent_node_id
                AND e3.is_tree = 1
                AND e3.run_id = {$this->run_id}
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $labeler = new NodeLabeler($this->db, $this->run_id);
        $use_retained = $this->substrate !== null
            && $this->substrate->subtree_sizes !== [];

        // If substrate available, sort by retained size instead
        $entries = [];
        foreach ($rows as $row) {
            $table_size = (int)$row['total_size'];
            $node_id = (int)$row['node_id'];
            $retained = $use_retained
                ? ($this->substrate->subtree_sizes[$node_id] ?? $table_size)
                : $table_size;
            $entries[] = [
                'row' => $row,
                'table_size' => $table_size,
                'retained' => $retained,
                'node_id' => $node_id,
            ];
        }
        usort($entries, fn($a, $b) => $b['retained'] <=> $a['retained']);

        $findings = [];
        foreach (array_slice($entries, 0, 10) as $entry) {
            $row = $entry['row'];
            $table_size = $entry['table_size'];
            $retained = $entry['retained'];
            $node_id = $entry['node_id'];

            if ($retained < 10240 && $table_size < 10240) {
                continue;
            }

            $elements = (int)($row['element_count'] ?? 0);
            $path = $this->substrate !== null
                ? $this->buildFullPath($node_id, $labeler)
                : $this->buildOwnerPath($row, $labeler);

            if ($use_retained && $retained > $table_size) {
                $summary = sprintf(
                    '%s retained (table: %s), %s elements — %s',
                    SizeFormatter::format($retained),
                    SizeFormatter::format($table_size),
                    number_format($elements),
                    $path ?: '(root)',
                );
            } else {
                $summary = sprintf(
                    '%s array, %s elements — %s',
                    SizeFormatter::format($table_size),
                    number_format($elements),
                    $path ?: '(root)',
                );
            }

            $findings[] = new Finding(
                kind: 'large_array',
                severity: $retained > 1024 * 1024
                    ? FindingSeverity::Medium
                    : FindingSeverity::Low,
                confidence: FindingConfidence::High,
                summary: $summary,
                facts: [
                    'node_id' => $node_id,
                    'table_size' => $table_size,
                    'retained_size' => $retained,
                    'element_count' => $elements,
                    'owner_path' => $path,
                ],
                hypothesis: 'Large array allocation;'
                    . ' may be a cache, buffer, or accumulator',
                next_checks: [
                    'Check if array size is bounded',
                    'Consider SplFixedArray for known-size collections',
                ],
                impact_bytes: $retained,
                evidence_node_ids: [$node_id],
                replay_query: "SELECT * FROM v_arrays"
                    . " WHERE run_id = {$this->run_id}"
                    . " ORDER BY total_size DESC LIMIT 10",
            );
        }

        // Sparse array detection: table_size >> element_count
        // table_size (bytes) = nTableSize * 32 (sizeof Bucket)
        $sparse_rows = $this->db->query("
            SELECT
                va.node_id,
                va.table_size,
                va.element_count,
                va.table_size / 32 as capacity
            FROM v_arrays va
            WHERE va.run_id = {$this->run_id}
                AND va.table_size >= 32768
                AND va.element_count > 0
                AND va.element_count * 4 < va.table_size / 32
            ORDER BY va.table_size DESC
            LIMIT 5
        ")->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($sparse_rows as $sr) {
            $s_node = (int)$sr['node_id'];
            $s_table = (int)$sr['table_size'];
            $s_count = (int)$sr['element_count'];
            $s_capacity = (int)$sr['capacity'];
            $utilization = $s_capacity > 0
                ? $s_count / $s_capacity * 100.0
                : 0;
            $s_path = $this->substrate !== null
                ? $this->buildFullPath($s_node, $labeler)
                : '(use --full-analysis for path)';

            $findings[] = new Finding(
                kind: 'sparse_array',
                severity: FindingSeverity::Medium,
                confidence: FindingConfidence::Medium,
                summary: sprintf(
                    '%s table, %s/%s slots used (%.1f%%) — %s',
                    SizeFormatter::format($s_table),
                    number_format($s_count),
                    number_format($s_capacity),
                    $utilization,
                    $s_path,
                ),
                facts: [
                    'node_id' => $s_node,
                    'table_size' => $s_table,
                    'element_count' => $s_count,
                    'capacity' => $s_capacity,
                    'utilization_pct' => round($utilization, 1),
                ],
                hypothesis: 'Array with many empty slots — likely after'
                    . ' mass unset() without reallocation',
                next_checks: [
                    'Use array_values() to repack the array',
                    'Or rebuild with only needed elements',
                ],
                impact_bytes: $s_table,
                evidence_node_ids: [$s_node],
            );
        }

        return $findings;
    }

    /**
     * Walk from node to root via tree parent edges.
     *
     * Uses a recursive CTE limited to 20 hops instead of loading the
     * entire edge set into PHP memory, which caused hangs on large heaps.
     *
     * @psalm-suppress MixedArrayAccess, MixedAssignment
     */
    private function buildFullPath(int $node_id, NodeLabeler $labeler): string
    {
        assert($this->substrate !== null);

        $stmt = $this->db->query("
            WITH RECURSIVE ancestors(depth, node_id, parent_id, link_name) AS (
                SELECT 0, child_node_id, parent_node_id, link_name
                FROM context_edges
                WHERE child_node_id = {$node_id}
                    AND is_tree = 1
                    AND run_id = {$this->run_id}
                UNION ALL
                SELECT a.depth + 1, e.child_node_id, e.parent_node_id, e.link_name
                FROM ancestors a
                JOIN context_edges e
                    ON e.child_node_id = a.parent_id
                    AND e.is_tree = 1
                    AND e.run_id = {$this->run_id}
                WHERE a.depth < 20
            )
            SELECT a.node_id, a.link_name, cn.type
            FROM ancestors a
            LEFT JOIN context_nodes cn
                ON cn.node_id = a.node_id
                AND cn.run_id = {$this->run_id}
            ORDER BY a.depth DESC
        ");
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $parts = [];
        $types = [];
        foreach ($rows as $r) {
            $parts[] = $labeler->resolvePathLabel((string)$r['link_name'], (int)$r['node_id']);
            $types[] = (string)($r['type'] ?? '');
        }

        return PathFormatter::toPhpSyntax($parts, $types);
    }

    private const STRUCTURAL_LINKS = [
        'object_properties',
        'array_elements',
        'local_variables',
        'symbol_table',
        'dynamic_properties',
        'value',
        'call_frames',
    ];

    /**
     * @param array<string, mixed> $row
     * @psalm-suppress MixedArgument, MixedAssignment, RiskyTruthyFalsyComparison
     * @psalm-suppress InvalidArgument, MixedArgumentTypeCoercion
     */
    private function buildOwnerPath(
        array $row,
        NodeLabeler $labeler,
    ): string {
        $raw_parts = [];

        if (($row['link3'] ?? null) !== null) {
            $raw_parts[] = (string)$row['link3'];
        }

        if (($row['link2'] ?? null) !== null) {
            $parent2_id = (int)($row['parent2_id'] ?? 0);
            $raw_parts[] = $labeler->resolvePathLabel(
                (string)$row['link2'],
                $parent2_id
            );
        }

        $parent_class = $row['parent1_class'] ?? null;
        if ($parent_class) {
            $raw_parts[] = $parent_class;
        }

        if (($row['link1'] ?? null) !== null) {
            $raw_parts[] = (string)$row['link1'];
        }

        // Filter structural intermediaries and join with ->
        $filtered = array_values(array_filter(
            $raw_parts,
            fn(string $p) => !in_array($p, self::STRUCTURAL_LINKS, true)
        ));

        return implode('->', $filtered);
    }
}
