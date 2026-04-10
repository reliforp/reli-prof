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
    private ?\PDOStatement $parentStmt = null;
    private ?\PDOStatement $nodeTypeStmt = null;

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
        $array_element_nodes = $this->loadArrayElementNodes();
        $labeler = new NodeLabeler($this->db, $this->run_id);
        $use_retained = $this->substrate->hasSubtreeSizes();

        $arrays = [];
        foreach ($this->substrate->iterateNodeSizes() as $node => $shallow) {
            foreach ($this->substrate->getChildren($node) as $child) {
                if (isset($array_element_nodes[$child])) {
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

        // Find the top 10 largest arrays first (no joins). The previous
        // implementation joined 3 hops of context_edges before applying
        // ORDER BY/LIMIT, computing the join for every row in v_arrays.
        // On large captures that single query dominated the report runtime.
        $stmt = $this->db->query("
            SELECT
                va.node_id,
                va.total_size,
                va.element_count
            FROM v_arrays va
            WHERE va.run_id = {$this->run_id}
            ORDER BY va.total_size DESC
            LIMIT 10
        ");

        $findings = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $total = (int)$row['total_size'];
            if ($total < 10240) {
                continue;
            }

            $elements = (int)($row['element_count'] ?? 0);
            $path = $this->buildFullPath((int)$row['node_id'], $labeler);

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
        ");

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
     * Set of every child node whose tree-edge link_name is 'array_elements'.
     *
     * Reads the substrate's in-memory tree-edge link index instead of
     * issuing a SELECT against context_edges — on huge dumps the SQL
     * fetch loop here used to dominate the pass.
     *
     * @return array<int, true> child_node_id => true
     */
    private function loadArrayElementNodes(): array
    {
        assert($this->substrate !== null);
        $set = [];
        foreach ($this->substrate->iterateTreeChildrenByLinkName('array_elements') as $child_id) {
            $set[$child_id] = true;
        }
        return $set;
    }

    /**
     * Walk from node to root via tree parent edges. Reads the
     * substrate's in-memory tree-link / parent / type indexes when
     * available, otherwise falls back to prepared statements.
     *
     * @psalm-suppress MixedArrayAccess, MixedAssignment
     */
    private function buildFullPath(int $node_id, NodeLabeler $labeler): string
    {
        if ($this->substrate !== null && $this->substrate->hasTreeLinkIndex()) {
            return $this->buildFullPathFromSubstrate($node_id, $labeler);
        }
        return $this->buildFullPathFromSql($node_id, $labeler);
    }

    private function buildFullPathFromSubstrate(int $node_id, NodeLabeler $labeler): string
    {
        assert($this->substrate !== null);
        $parts = [];
        $types = [];
        $cur = $node_id;
        for ($i = 0; $i < 20; $i++) {
            $link = $this->substrate->getTreeLinkName($cur);
            if ($link === null) {
                break;
            }
            $parent = $this->substrate->getTreeParentNodeId($cur);
            if ($parent === null) {
                array_unshift($parts, $link);
                array_unshift($types, '');
                break;
            }
            array_unshift($parts, $labeler->resolvePathLabel($link, $cur));
            array_unshift($types, $this->substrate->getNodeType($cur) ?? '');
            $cur = $parent;
        }
        return PathFormatter::toPhpSyntax($parts, $types);
    }

    /**
     * @psalm-suppress MixedArrayAccess, MixedAssignment
     */
    private function buildFullPathFromSql(int $node_id, NodeLabeler $labeler): string
    {
        if ($this->parentStmt === null) {
            $this->parentStmt = $this->db->prepare(
                "SELECT parent_node_id, link_name FROM context_edges"
                . " WHERE child_node_id = ? AND is_tree = 1"
                . " AND run_id = {$this->run_id} LIMIT 1"
            );
            $this->nodeTypeStmt = $this->db->prepare(
                "SELECT type FROM context_nodes"
                . " WHERE node_id = ? AND run_id = {$this->run_id} LIMIT 1"
            );
        }

        $parts = [];
        $types = [];
        $cur = $node_id;
        for ($i = 0; $i < 20; $i++) {
            $this->parentStmt->execute([$cur]);
            $row = $this->parentStmt->fetch(\PDO::FETCH_NUM);
            if (!$row) {
                break;
            }
            if ($row[0] === null) {
                array_unshift($parts, (string)$row[1]);
                array_unshift($types, '');
                break;
            }
            $parent = (int)$row[0];
            $link = (string)$row[1];
            $resolved = $labeler->resolvePathLabel($link, $cur);
            array_unshift($parts, $resolved);

            assert($this->nodeTypeStmt !== null);
            $this->nodeTypeStmt->execute([$cur]);
            $nt = $this->nodeTypeStmt->fetchColumn();
            array_unshift($types, $nt !== false ? (string)$nt : '');
            $cur = $parent;
        }

        return PathFormatter::toPhpSyntax($parts, $types);
    }
}
