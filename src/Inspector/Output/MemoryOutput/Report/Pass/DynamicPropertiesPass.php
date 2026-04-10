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
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\SizeFormatter;

final class DynamicPropertiesPass implements PassInterface
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
     * @psalm-suppress PossiblyInvalidArgument, InvalidOperand
     */
    #[\Override]
    public function analyze(): array
    {
        $rows = $this->substrate !== null && $this->substrate->hasTreeLinkIndex()
            ? $this->aggregateFromSubstrate()
            : $this->aggregateFromSql();

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
                    '%s %s with dynamic properties = %s overhead',
                    number_format($cnt),
                    $short,
                    SizeFormatter::format($dp_size),
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

    /**
     * In-memory aggregation: walk every tree edge whose link_name is
     * 'dynamic_properties' from the substrate index, look the parent's
     * class and the child's (DP table's) size up directly. No SQL.
     *
     * @return list<array{class_name:string, cnt:int, dp_size:int}>
     */
    private function aggregateFromSubstrate(): array
    {
        assert($this->substrate !== null);

        /** @var array<string, array{cnt:int, dp_size:int}> $stats */
        $stats = [];
        foreach ($this->substrate->iterateTreeChildrenByLinkName('dynamic_properties') as $child_id) {
            $parent_id = $this->substrate->getTreeParentNodeId($child_id);
            if ($parent_id === null) {
                continue;
            }
            $class_name = $this->substrate->getNodeClass($parent_id);
            if ($class_name === null) {
                continue;
            }
            $size = $this->substrate->getNodeSize($child_id);
            if (!isset($stats[$class_name])) {
                $stats[$class_name] = ['cnt' => 0, 'dp_size' => 0];
            }
            $stats[$class_name]['cnt']++;
            $stats[$class_name]['dp_size'] += $size;
        }

        // Apply HAVING / ORDER BY / LIMIT just like the SQL path.
        $rows = [];
        foreach ($stats as $class_name => $s) {
            if ($s['cnt'] > 10) {
                $rows[] = [
                    'class_name' => $class_name,
                    'cnt' => $s['cnt'],
                    'dp_size' => $s['dp_size'],
                ];
            }
        }
        usort($rows, static fn(array $a, array $b): int => $b['dp_size'] <=> $a['dp_size']);
        return array_slice($rows, 0, 10);
    }

    /**
     * SQL fallback for the no-substrate code path (Phase 2 only —
     * still used by tests and by reports run without --full-analysis).
     *
     * @return list<array{class_name:string, cnt:int, dp_size:int}>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument
     */
    private function aggregateFromSql(): array
    {
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

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'class_name' => (string)$row['class_name'],
                'cnt' => (int)$row['cnt'],
                'dp_size' => (int)$row['dp_size'],
            ];
        }
        return $out;
    }
}
