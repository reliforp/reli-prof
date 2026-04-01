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

final class TopArraysPass implements PassInterface
{
    public function __construct(
        private \PDO $db,
        private int $run_id,
    ) {
    }

    /**
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedOperand, InvalidOperand
     * @psalm-suppress PossiblyInvalidArgument, InvalidArgument
     */
    #[\Override]
    public function analyze(): array
    {
        $rows = $this->db->query("
            SELECT
                va.node_id,
                va.total_size,
                va.element_count,
                e_parent.link_name as parent_link,
                cnl_parent.class_name as parent_class,
                e_grandparent.link_name as grandparent_link
            FROM v_arrays va
            LEFT JOIN context_edges e_parent ON e_parent.child_node_id = va.node_id
                AND e_parent.is_tree = 1 AND e_parent.run_id = {$this->run_id}
            LEFT JOIN context_node_locations cnl_parent ON cnl_parent.node_id = e_parent.parent_node_id
                AND cnl_parent.run_id = {$this->run_id}
            LEFT JOIN context_edges e_grandparent ON e_grandparent.child_node_id = e_parent.parent_node_id
                AND e_grandparent.is_tree = 1 AND e_grandparent.run_id = {$this->run_id}
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
            $path_parts = array_filter([
                $row['parent_class'] ? preg_replace('/^.*\\\\/', '', $row['parent_class']) : null,
                $row['grandparent_link'],
                $row['parent_link'],
            ]);
            $path = $path_parts ? implode(' -> ', $path_parts) : '(root)';

            $findings[] = new Finding(
                kind: 'large_array',
                severity: $total > 1024 * 1024 ? FindingSeverity::Medium : FindingSeverity::Low,
                confidence: FindingConfidence::High,
                summary: sprintf(
                    '%.2f MB array, %s elements — %s',
                    $total / 1024 / 1024,
                    number_format($elements),
                    $path,
                ),
                facts: [
                    'node_id' => (int)$row['node_id'],
                    'total_size' => $total,
                    'element_count' => $elements,
                    'owner_path' => $path,
                ],
                hypothesis: 'Large array allocation; may be a cache, buffer, or accumulator',
                next_checks: [
                    'Check if array size is bounded',
                    'Consider SplFixedArray for known-size collections',
                ],
                impact_bytes: $total,
                evidence_node_ids: [(int)$row['node_id']],
                replay_query: "SELECT * FROM v_arrays WHERE run_id = {$this->run_id} ORDER BY total_size DESC LIMIT 10",
            );
        }

        return $findings;
    }
}
