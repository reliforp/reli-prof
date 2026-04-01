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

final class DynamicPropertiesPass implements PassInterface
{
    public function __construct(
        private \PDO $db,
        private int $run_id,
    ) {
    }

    /**
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedOperand, PossiblyInvalidArgument, InvalidOperand
     */
    #[\Override]
    public function analyze(): array
    {
        $rows = $this->db->query("
            SELECT
                cnl.class_name,
                count(*) as cnt,
                sum(cnl.size) as total_size
            FROM context_edges e
            JOIN context_node_locations cnl ON cnl.node_id = e.parent_node_id
                AND cnl.run_id = {$this->run_id}
                AND cnl.location_type = 'ZendObjectMemoryLocation'
            WHERE e.run_id = {$this->run_id}
                AND e.link_name = 'dynamic_properties'
                AND cnl.class_name IS NOT NULL
            GROUP BY cnl.class_name
            HAVING count(*) > 10
            ORDER BY sum(cnl.size) DESC
            LIMIT 10
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $findings = [];
        foreach ($rows as $row) {
            $short = preg_replace('/^.*\\\\/', '', $row['class_name']) ?? $row['class_name'];
            $total = (int)$row['total_size'];
            $cnt = (int)$row['cnt'];

            $findings[] = new Finding(
                kind: 'dynamic_properties_overhead',
                severity: $total > 1024 * 1024 ? FindingSeverity::Medium : FindingSeverity::Low,
                confidence: FindingConfidence::High,
                summary: sprintf(
                    '%s %s with dynamic properties = %.2f MB overhead',
                    number_format($cnt),
                    $short,
                    $total / 1024 / 1024,
                ),
                facts: [
                    'class_name' => $row['class_name'],
                    'count' => $cnt,
                    'total_size' => $total,
                ],
                hypothesis: 'Dynamic property tables add overhead; declaring properties statically saves memory',
                next_checks: [
                    'Declare these properties explicitly in the class definition',
                ],
                impact_bytes: $total,
                replay_query: "SELECT cnl.class_name, count(*) as cnt, sum(cnl.size) FROM context_edges e"
                    . " JOIN context_node_locations cnl ON cnl.node_id = e.parent_node_id"
                    . " WHERE e.link_name = 'dynamic_properties' AND cnl.class_name IS NOT NULL"
                    . " GROUP BY cnl.class_name HAVING count(*) > 10 ORDER BY sum(cnl.size) DESC",
            );
        }

        return $findings;
    }
}
