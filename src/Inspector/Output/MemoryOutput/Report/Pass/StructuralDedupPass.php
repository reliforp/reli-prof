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
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\SizeFormatter;

final class StructuralDedupPass implements PassInterface
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
        // Step 1: Find classes with >= 50 instances (cheap aggregate).
        // Only these can possibly produce findings, so we skip the
        // expensive property-signature query for rare classes.
        $frequent = $this->db->query("
            SELECT class_name, COUNT(*) as cnt, SUM(size) as total_size
            FROM context_node_locations
            WHERE location_type = 'ZendObjectMemoryLocation'
                AND class_name IS NOT NULL
                AND run_id = {$this->run_id}
            GROUP BY class_name
            HAVING cnt >= 50
            ORDER BY total_size DESC
            LIMIT 50
        ")->fetchAll(\PDO::FETCH_ASSOC);

        if ($frequent === []) {
            return [];
        }

        // Step 2: Build shape signatures only for frequent classes.
        $class_list = implode(', ', array_map(
            fn($r) => $this->db->quote((string)$r['class_name']),
            $frequent,
        ));
        $rows = $this->db->query("
            SELECT
                cnl.node_id,
                cnl.class_name,
                cnl.size,
                group_concat(e_prop.link_name, '|') as property_names
            FROM context_node_locations cnl
            JOIN context_edges e_to_obj ON e_to_obj.parent_node_id = cnl.node_id
                AND e_to_obj.link_name = 'object_properties' AND e_to_obj.is_tree = 1
                AND e_to_obj.run_id = {$this->run_id}
            LEFT JOIN context_edges e_prop ON e_prop.parent_node_id = e_to_obj.child_node_id
                AND e_prop.is_tree = 1 AND e_prop.run_id = {$this->run_id}
            WHERE cnl.location_type = 'ZendObjectMemoryLocation'
                AND cnl.class_name IN ({$class_list})
                AND cnl.run_id = {$this->run_id}
            GROUP BY cnl.node_id
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $shape_groups = [];
        foreach ($rows as $s) {
            $props = $s['property_names'] ? explode('|', $s['property_names']) : [];
            sort($props);
            $prop_sig = implode(',', $props);
            $hash = $s['class_name'] . '|' . $s['size'] . '|' . $prop_sig;

            if (!isset($shape_groups[$hash])) {
                $shape_groups[$hash] = [
                    'class' => $s['class_name'],
                    'size' => (int)$s['size'],
                    'props' => $prop_sig,
                    'count' => 0,
                    'total_size' => 0,
                    'example_id' => (int)$s['node_id'],
                ];
            }
            $shape_groups[$hash]['count']++;
            $shape_groups[$hash]['total_size'] += (int)$s['size'];
        }
        unset($rows);

        $findings = [];

        // Structural duplicates (>= 50 identical shapes)
        $dedup_candidates = array_filter($shape_groups, fn($g) => $g['count'] >= 50);
        usort($dedup_candidates, fn($a, $b) => $b['total_size'] <=> $a['total_size']);

        foreach (array_slice($dedup_candidates, 0, 10) as $g) {
            $short = $g['class'];
            $is_empty = $g['props'] === '';
            $waste = ($g['count'] - 1) * $g['size'];

            if ($is_empty) {
                $findings[] = new Finding(
                    kind: 'empty_object',
                    severity: $g['total_size'] > 102400 ? FindingSeverity::Medium : FindingSeverity::Low,
                    confidence: FindingConfidence::High,
                    summary: sprintf(
                        '%s: %s instances x %s, no properties stored (%s)',
                        $short,
                        number_format($g['count']),
                        SizeFormatter::format($g['size']),
                        SizeFormatter::format($g['total_size']),
                    ),
                    facts: [
                        'class_name' => $g['class'],
                        'count' => $g['count'],
                        'each_size' => $g['size'],
                        'total_size' => $g['total_size'],
                    ],
                    hypothesis: 'Objects with no stored properties — pure overhead, may be replaceable',
                    impact_bytes: $waste,
                    evidence_node_ids: [$g['example_id']],
                );
            } else {
                $findings[] = new Finding(
                    kind: 'structural_duplicate',
                    severity: $waste > 102400 ? FindingSeverity::Medium : FindingSeverity::Low,
                    confidence: FindingConfidence::Medium,
                    summary: sprintf(
                        '%s: %s identical shapes x %s = %s (theoretical saving: %s)',
                        $short,
                        number_format($g['count']),
                        SizeFormatter::format($g['size']),
                        SizeFormatter::format($g['total_size']),
                        SizeFormatter::format($waste),
                    ),
                    facts: [
                        'class_name' => $g['class'],
                        'count' => $g['count'],
                        'each_size' => $g['size'],
                        'total_size' => $g['total_size'],
                        'theoretical_saving' => $waste,
                        'properties' => $g['props'],
                    ],
                    hypothesis: 'Identical object shapes — candidates for flyweight/sharing optimization',
                    impact_bytes: $waste,
                    evidence_node_ids: [$g['example_id']],
                );
            }
        }

        return $findings;
    }
}
