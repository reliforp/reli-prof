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
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\NodeLabeler;

final class TopArraysPass implements PassInterface
{
    public function __construct(
        private \PDO $db,
        private int $run_id,
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
        // 3-hop ancestor: gp_link -> parent_link -> owner -> array
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

        $labeler = new NodeLabeler($this->db, $this->run_id);
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
                    '%.2f MB array, %s elements — %s',
                    $total / 1024 / 1024,
                    number_format($elements),
                    $path ?: '(root)',
                ),
                facts: [
                    'node_id' => (int)$row['node_id'],
                    'total_size' => $total,
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
                replay_query: "SELECT * FROM v_arrays"
                    . " WHERE run_id = {$this->run_id}"
                    . " ORDER BY total_size DESC LIMIT 10",
            );
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $row
     * @psalm-suppress MixedArgument, MixedAssignment, RiskyTruthyFalsyComparison, InvalidArgument
     */
    private function buildOwnerPath(
        array $row,
        NodeLabeler $labeler,
    ): string {
        $parts = [];

        if (($row['link3'] ?? null) !== null) {
            $parts[] = (string)$row['link3'];
        }

        if (($row['link2'] ?? null) !== null) {
            $parent2_id = (int)($row['parent2_id'] ?? 0);
            $parts[] = $labeler->resolvePathLabel(
                (string)$row['link2'],
                $parent2_id
            );
        }

        $parent_class = $row['parent1_class'] ?? null;
        if ($parent_class) {
            $short = preg_replace('/^.*\\\\/', '', $parent_class)
                ?? $parent_class;
            $parts[] = $short;
        }

        if (($row['link1'] ?? null) !== null) {
            $parts[] = (string)$row['link1'];
        }

        return implode(' -> ', $parts);
    }
}
