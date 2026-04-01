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

final class NonTreeEdgePass implements PassInterface
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
        // Classify shared references by link_name
        $rows = $this->db->query("
            SELECT
                e.link_name,
                count(*) as ref_count,
                count(DISTINCT e.child_node_id) as target_count,
                round(count(*) * 1.0 / max(1, count(DISTINCT e.child_node_id)), 1) as avg_refs
            FROM context_edges e
            WHERE e.run_id = {$this->run_id}
                AND e.is_tree = 0
                AND e.link_name NOT IN (
                    'object_properties', 'array_elements',
                    'dynamic_properties', 'object_handlers',
                    'name', 'key', 'value', 'class_entry'
                )
            GROUP BY e.link_name
            HAVING count(*) > 10
            ORDER BY count(*) DESC
            LIMIT 20
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $findings = [];
        foreach ($rows as $row) {
            // Skip numeric indices (array element sharing, not meaningful)
            if (ctype_digit($row['link_name'])) {
                continue;
            }
            $ref_count = (int)$row['ref_count'];
            $target_count = (int)$row['target_count'];
            $avg_refs = (float)$row['avg_refs'];

            if ($target_count === 1 && $ref_count > 50) {
                // Singleton pattern: many refs to one target
                $findings[] = new Finding(
                    kind: 'shared_singleton',
                    severity: FindingSeverity::Info,
                    confidence: FindingConfidence::High,
                    summary: sprintf(
                        '%s: %s refs -> 1 target [singleton, normal]',
                        $row['link_name'],
                        number_format($ref_count),
                    ),
                    facts: [
                        'link_name' => $row['link_name'],
                        'ref_count' => $ref_count,
                        'target_count' => $target_count,
                    ],
                );
            } elseif ($target_count > 1 && $avg_refs > 2.0) {
                // Fan-in: multiple refs to multiple targets
                $findings[] = new Finding(
                    kind: 'shared_fanin',
                    severity: FindingSeverity::Medium,
                    confidence: FindingConfidence::Medium,
                    summary: sprintf(
                        '%s: %s refs -> %s targets (%.1f each)',
                        $row['link_name'],
                        number_format($ref_count),
                        number_format($target_count),
                        $avg_refs,
                    ),
                    facts: [
                        'link_name' => $row['link_name'],
                        'ref_count' => $ref_count,
                        'target_count' => $target_count,
                        'avg_refs_per_target' => $avg_refs,
                    ],
                    hypothesis: 'Multiple references to shared objects — may indicate cycle back-references',
                    next_checks: [
                        'Check if these are intentional shared references or cycle artifacts',
                    ],
                );
            }
        }

        // Dedup candidates: same link_name, same size, many copies
        $dedup_rows = $this->db->query("
            SELECT
                e.link_name,
                cnl.size,
                count(*) as cnt,
                count(*) * cnl.size as total_waste
            FROM context_edges e
            JOIN context_node_locations cnl ON cnl.node_id = e.child_node_id
                AND cnl.run_id = {$this->run_id}
            WHERE e.run_id = {$this->run_id}
                AND e.is_tree = 0
                AND e.link_name NOT IN (
                    'object_handlers', 'object_properties',
                    'array_elements', 'dynamic_properties',
                    'class_entry'
                )
            GROUP BY e.link_name, cnl.size
            HAVING count(*) > 50 AND count(*) * cnl.size > 10240
            ORDER BY count(*) * cnl.size DESC
            LIMIT 10
        ")->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($dedup_rows as $row) {
            $cnt = (int)$row['cnt'];
            $size = (int)$row['size'];
            $total = (int)$row['total_waste'];

            $findings[] = new Finding(
                kind: 'dedup_candidate',
                severity: $total > 102400 ? FindingSeverity::Low : FindingSeverity::Info,
                confidence: FindingConfidence::Low,
                summary: sprintf(
                    '%s: %s copies x %dB ALL SAME SIZE = %.2f KB wasted',
                    $row['link_name'],
                    number_format($cnt),
                    $size,
                    $total / 1024,
                ),
                facts: [
                    'link_name' => $row['link_name'],
                    'count' => $cnt,
                    'each_size' => $size,
                    'total_waste' => $total,
                ],
                hypothesis: 'Multiple copies of same-size objects via shared references; may be shareable',
                impact_bytes: $total,
            );
        }

        return $findings;
    }
}
