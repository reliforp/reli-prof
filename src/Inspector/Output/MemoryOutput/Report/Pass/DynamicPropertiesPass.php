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
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedOperand
     * @psalm-suppress PossiblyInvalidArgument, InvalidOperand
     */
    #[\Override]
    public function analyze(): array
    {
        // Find objects that have a 'dynamic_properties' child edge.
        // The overhead is the dynamic properties table itself (child node).
        // Group by the object's class_name.
        $rows = $this->db->query("
            SELECT
                cnl_obj.class_name,
                count(*) as cnt,
                sum(COALESCE(cnl_dp.size, 0)) as dp_size
            FROM context_edges e
            JOIN context_node_locations cnl_obj
                ON cnl_obj.node_id = e.parent_node_id
                AND cnl_obj.run_id = {$this->run_id}
                AND cnl_obj.location_type = 'ZendObjectMemoryLocation'
            LEFT JOIN context_node_locations cnl_dp
                ON cnl_dp.node_id = e.child_node_id
                AND cnl_dp.run_id = {$this->run_id}
            WHERE e.run_id = {$this->run_id}
                AND e.link_name = 'dynamic_properties'
                AND e.is_tree = 1
                AND cnl_obj.class_name IS NOT NULL
            GROUP BY cnl_obj.class_name
            HAVING count(*) > 10
            ORDER BY sum(COALESCE(cnl_dp.size, 0)) DESC
            LIMIT 10
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $findings = [];
        foreach ($rows as $row) {
            $short = $row['class_name'];
            $dp_size = (int)$row['dp_size'];
            $cnt = (int)$row['cnt'];

            $findings[] = new Finding(
                kind: 'dynamic_properties_overhead',
                severity: $dp_size > 1024 * 1024
                    ? FindingSeverity::Medium
                    : FindingSeverity::Low,
                confidence: FindingConfidence::High,
                summary: sprintf(
                    '%s %s with dynamic properties = %.2f MB overhead',
                    number_format($cnt),
                    $short,
                    $dp_size / 1024 / 1024,
                ),
                facts: [
                    'class_name' => $row['class_name'],
                    'count' => $cnt,
                    'dynamic_properties_size' => $dp_size,
                ],
                hypothesis: 'Dynamic property tables add per-object overhead;'
                    . ' declaring properties statically saves memory',
                next_checks: [
                    'Declare these properties explicitly in the class',
                ],
                impact_bytes: $dp_size,
                replay_query: "SELECT cnl_obj.class_name, count(*),"
                    . " sum(cnl_dp.size) FROM context_edges e"
                    . " JOIN context_node_locations cnl_obj"
                    . " ON cnl_obj.node_id = e.parent_node_id"
                    . " LEFT JOIN context_node_locations cnl_dp"
                    . " ON cnl_dp.node_id = e.child_node_id"
                    . " WHERE e.link_name = 'dynamic_properties'"
                    . " AND e.is_tree = 1"
                    . " GROUP BY cnl_obj.class_name"
                    . " HAVING count(*) > 10"
                    . " ORDER BY sum(cnl_dp.size) DESC",
            );
        }

        return $findings;
    }
}
