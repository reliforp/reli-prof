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
    /** @var array<int, array{0: int, 1: string}>|null */
    private ?array $parentMapCache = null;
    /** @var array<int, string>|null */
    private ?array $nodeTypeCache = null;

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
        if ($this->substrate !== null) {
            return $this->analyzeWithGraph();
        }
        return $this->analyzeWithSql();
    }

    /**
     * In-memory analysis using GraphSubstrate.
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess
     */
    private function analyzeWithGraph(): array
    {
        assert($this->substrate !== null);
        $link_names = $this->loadLinkNames();
        $labeler = new NodeLabeler($this->db, $this->run_id);
        $use_retained = $this->substrate->hasSubtreeSizes();

        $arrays = [];
        foreach ($this->substrate->iterateNodeSizes() as $node => $shallow) {
            foreach ($this->substrate->getChildren($node) as $child) {
                if (($link_names[$child] ?? '') === 'array_elements') {
                    $retained = $use_retained
                        ? $this->substrate->getSubtreeSize($node)
                        : $shallow;
                    $elem_count = count(
                        $this->substrate->getChildren($child)
                    );
                    $arrays[] = [
                        'node_id' => $node,
                        'shallow' => $shallow,
                        'retained' => $retained ?: $shallow,
                        'element_count' => $elem_count,
                    ];
                    break;
                }
            }
        }

        usort($arrays, fn($a, $b) => $b['retained'] <=> $a['retained']);

        $findings = [];
        foreach (array_slice($arrays, 0, 10) as $arr) {
            $retained = $arr['retained'];
            $shallow = $arr['shallow'];
            $node_id = $arr['node_id'];

            if ($retained < 10240 && $shallow < 10240) {
                continue;
            }

            $elements = $arr['element_count'];
            $path = $this->buildFullPath($node_id, $labeler);

            if ($use_retained && $retained > $shallow) {
                $summary = sprintf(
                    '%s retained (table: %s), %s elements — %s',
                    SizeFormatter::format($retained),
                    SizeFormatter::format($shallow),
                    number_format($elements),
                    $path ?: '(root)',
                );
            } else {
                $summary = sprintf(
                    '%s array, %s elements — %s',
                    SizeFormatter::format($shallow),
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
                    'table_size' => $shallow,
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
            );
        }

        return $findings;
    }

    /**
     * SQL-based analysis (fallback when no substrate).
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument
     * @psalm-suppress InvalidOperand
     */
    private function analyzeWithSql(): array
    {
        $labeler = new NodeLabeler($this->db, $this->run_id);

        $rows = $this->db->query("
            SELECT
                va.node_id,
                va.total_size,
                va.element_count,
                e1.link_name as link1,
                e1.parent_node_id as parent1_id,
                cnl_p1.class_name as parent1_class,
                e2.link_name as link2,
                e2.parent_node_id as parent2_id,
                e3.link_name as link3
            FROM v_arrays va
            LEFT JOIN context_edges e1
                ON e1.child_node_id = va.node_id
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
            WHERE va.run_id = {$this->run_id}
            ORDER BY va.total_size DESC
            LIMIT 10
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $findings = [];
        foreach ($rows as $row) {
            $total = (int)$row['total_size'];
            if ($total < 10240) {
                continue;
            }

            $elements = (int)($row['element_count'] ?? 0);
            $path = $this->buildOwnerPath($row, $labeler);

            $findings[] = new Finding(
                kind: 'large_array',
                severity: $total > 1024 * 1024
                    ? FindingSeverity::Medium
                    : FindingSeverity::Low,
                confidence: FindingConfidence::High,
                summary: sprintf(
                    '%s array, %s elements — %s',
                    SizeFormatter::format($total),
                    number_format($elements),
                    $path ?: '(root)',
                ),
                facts: [
                    'node_id' => (int)$row['node_id'],
                    'table_size' => $total,
                    'retained_size' => $total,
                    'element_count' => $elements,
                    'owner_path' => $path,
                ],
                hypothesis: 'Large array allocation;'
                    . ' may be a cache, buffer, or accumulator',
                next_checks: [
                    'Check if array size is bounded',
                    'Consider SplFixedArray for known-size collections',
                ],
                impact_bytes: $total,
                evidence_node_ids: [(int)$row['node_id']],
            );
        }

        // Sparse array detection
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

            $findings[] = new Finding(
                kind: 'sparse_array',
                severity: FindingSeverity::Medium,
                confidence: FindingConfidence::Medium,
                summary: sprintf(
                    '%s table, %s/%s slots used (%.1f%%)',
                    SizeFormatter::format($s_table),
                    number_format($s_count),
                    number_format($s_capacity),
                    $utilization,
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
     * @return array<int, string>
     * @psalm-suppress MixedArrayAccess, MixedAssignment
     */
    private function loadLinkNames(): array
    {
        $rows = $this->db->query(
            "SELECT child_node_id, link_name FROM context_edges"
            . " WHERE is_tree = 1 AND run_id = {$this->run_id}"
        )->fetchAll(\PDO::FETCH_NUM);

        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r[0]] = (string)$r[1];
        }
        unset($rows);
        return $map;
    }

    /**
     * Walk from node to root via tree parent edges.
     * @psalm-suppress MixedArrayAccess, MixedAssignment
     */
    private function buildFullPath(
        int $node_id,
        NodeLabeler $labeler,
    ): string {
        assert($this->substrate !== null);

        if ($this->parentMapCache === null) {
            $this->parentMapCache = [];
            $rows = $this->db->query(
                "SELECT child_node_id, parent_node_id, link_name"
                . " FROM context_edges WHERE is_tree = 1"
                . " AND run_id = {$this->run_id}"
            )->fetchAll(\PDO::FETCH_NUM);
            foreach ($rows as $r) {
                $this->parentMapCache[(int)$r[0]]
                    = [(int)($r[1] ?? -1), (string)$r[2]];
            }
            unset($rows);

            $this->nodeTypeCache = [];
            $rows = $this->db->query(
                "SELECT node_id, type FROM context_nodes"
                . " WHERE run_id = {$this->run_id}"
            )->fetchAll(\PDO::FETCH_NUM);
            foreach ($rows as $r) {
                $this->nodeTypeCache[(int)$r[0]] = (string)$r[1];
            }
            unset($rows);
        }

        $parts = [];
        $types = [];
        $cur = $node_id;
        for ($i = 0; $i < 20; $i++) {
            if (!isset($this->parentMapCache[$cur])) {
                break;
            }
            [$parent, $link] = $this->parentMapCache[$cur];
            $resolved = $labeler->resolvePathLabel($link, $cur);
            array_unshift($parts, $resolved);
            array_unshift($types, $this->nodeTypeCache[$cur] ?? '');
            $cur = $parent;
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

        $filtered = array_values(array_filter(
            $raw_parts,
            fn(string $p) => !in_array($p, self::STRUCTURAL_LINKS, true)
        ));

        return implode('->', $filtered);
    }
}
