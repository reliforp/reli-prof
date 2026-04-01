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

final class PerPropertyMemoryPass implements PassInterface
{
    private const STRUCTURAL_NAMES = [
        'object_properties', 'array_elements', 'dynamic_properties',
        'value', 'key', '#count', 'class_entry',
    ];

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
        $structural_list = "'" . implode("','", self::STRUCTURAL_NAMES) . "'";

        $rows = $this->db->query("
            SELECT
                e.link_name,
                count(*) as cnt,
                sum(cnl.size) as total_size,
                round(avg(cnl.size), 0) as avg_size
            FROM context_edges e
            JOIN context_node_locations cnl ON cnl.node_id = e.child_node_id
                AND cnl.run_id = {$this->run_id}
            WHERE e.run_id = {$this->run_id}
                AND e.is_tree = 1
                AND e.link_name NOT IN ({$structural_list})
            GROUP BY e.link_name
            HAVING count(*) > 100 AND sum(cnl.size) > 102400
            ORDER BY sum(cnl.size) DESC
            LIMIT 15
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $findings = [];
        foreach ($rows as $row) {
            $cnt = (int)$row['cnt'];
            $total = (int)$row['total_size'];
            $avg = (int)$row['avg_size'];

            $findings[] = new Finding(
                kind: 'expensive_property',
                severity: $total > 1024 * 1024 ? FindingSeverity::Medium : FindingSeverity::Low,
                confidence: FindingConfidence::Medium,
                summary: sprintf(
                    '%s: %s occurrences x %dB = %.2f MB',
                    $row['link_name'],
                    number_format($cnt),
                    $avg,
                    $total / 1024 / 1024,
                ),
                facts: [
                    'property_name' => $row['link_name'],
                    'count' => $cnt,
                    'total_size' => $total,
                    'avg_size' => $avg,
                ],
                hypothesis: 'High-frequency property contributing significant memory',
                next_checks: [
                    'Check if values can be shared or lazily loaded',
                    'Consider using a more compact representation',
                ],
                impact_bytes: $total,
                replay_query: "SELECT e.link_name, count(*), sum(cnl.size), avg(cnl.size)"
                    . " FROM context_edges e JOIN context_node_locations cnl ON cnl.node_id = e.child_node_id"
                    . " WHERE e.is_tree = 1 GROUP BY e.link_name HAVING count(*) > 100"
                    . " ORDER BY sum(cnl.size) DESC LIMIT 15",
            );
        }

        return $findings;
    }
}
