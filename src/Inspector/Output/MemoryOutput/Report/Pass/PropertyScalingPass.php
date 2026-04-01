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

final class PropertyScalingPass implements PassInterface
{
    /**
     * @param array<string, array{count: int, memory_usage: int}> $class_objects_summary
     */
    public function __construct(
        private \PDO $db,
        private int $run_id,
        private array $class_objects_summary,
    ) {
    }

    /**
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument
     * @psalm-suppress MixedOperand, InvalidOperand
     */
    #[\Override]
    public function analyze(): array
    {
        // Find dominant class (> 50% of object memory)
        $total_object_memory = 0;
        foreach ($this->class_objects_summary as $entry) {
            $total_object_memory += $entry['memory_usage'];
        }
        if ($total_object_memory === 0) {
            return [];
        }

        $dominant_class = null;
        $dominant_count = 0;
        foreach ($this->class_objects_summary as $name => $entry) {
            $pct = $entry['memory_usage'] / $total_object_memory * 100.0;
            if ($pct > 50.0 && $entry['count'] > 100) {
                $dominant_class = $name;
                $dominant_count = $entry['count'];
                break;
            }
        }

        if ($dominant_class === null) {
            return [];
        }

        $rows = $this->db->query("
            SELECT
                e_prop.link_name,
                count(*) as total_refs,
                count(DISTINCT e_prop.child_node_id) as distinct_targets,
                sum(CASE WHEN e_prop.is_tree = 1
                    THEN COALESCE(cnl_val.size, 0) ELSE 0
                END) as tree_size
            FROM context_node_locations cnl_obj
            JOIN context_edges e_to_props
                ON e_to_props.parent_node_id = cnl_obj.node_id
                AND e_to_props.link_name = 'object_properties'
                AND e_to_props.run_id = {$this->run_id}
            JOIN context_edges e_prop
                ON e_prop.parent_node_id = e_to_props.child_node_id
                AND e_prop.run_id = {$this->run_id}
            LEFT JOIN context_node_locations cnl_val
                ON cnl_val.node_id = e_prop.child_node_id
                AND cnl_val.run_id = {$this->run_id}
            WHERE cnl_obj.class_name = "
            . $this->db->quote($dominant_class) . "
                AND cnl_obj.run_id = {$this->run_id}
            GROUP BY e_prop.link_name
            ORDER BY tree_size DESC
        ")->fetchAll(\PDO::FETCH_ASSOC);

        if ($rows === []) {
            return [];
        }

        $per_instance = [];
        $shared = [];

        foreach ($rows as $row) {
            $distinct = (int)$row['distinct_targets'];
            $total_refs = (int)$row['total_refs'];
            $tree_size = (int)$row['tree_size'];

            if ($distinct === 1) {
                $scaling = 'SHARED';
            } elseif ($distinct >= $total_refs * 0.9) {
                $scaling = 'PER-INSTANCE';
            } else {
                $scaling = 'PARTIALLY SHARED';
            }

            $entry = [
                'property' => $row['link_name'],
                'distinct_targets' => $distinct,
                'total_refs' => $total_refs,
                'tree_size' => $tree_size,
                'scaling' => $scaling,
            ];

            if ($scaling === 'SHARED') {
                $shared[] = $entry;
            } else {
                $per_instance[] = $entry;
            }
        }

        $short = preg_replace('/^.*\\\\/', '', $dominant_class)
            ?? $dominant_class;

        $lines = [];
        if ($per_instance !== []) {
            $lines[] = 'PER-INSTANCE (scales with count):';
            foreach (array_slice($per_instance, 0, 5) as $p) {
                $lines[] = sprintf(
                    '  $%s: %s copies x %dB = %.2f KB',
                    $p['property'],
                    number_format($p['distinct_targets']),
                    $p['distinct_targets'] > 0
                        ? (int)($p['tree_size'] / $p['distinct_targets'])
                        : 0,
                    $p['tree_size'] / 1024,
                );
            }
        }
        if ($shared !== []) {
            $shared_names = array_map(
                fn($s) => '$' . $s['property'],
                array_slice($shared, 0, 10)
            );
            $lines[] = 'SHARED (constant cost): '
                . implode(', ', $shared_names);
        }

        $per_instance_total = array_sum(
            array_column($per_instance, 'tree_size')
        );

        return [
            new Finding(
                kind: 'property_scaling',
                severity: FindingSeverity::Medium,
                confidence: FindingConfidence::Medium,
                summary: sprintf(
                    '%s (%s instances): %d per-instance props (%.2f KB/instance), %d shared',
                    $short,
                    number_format($dominant_count),
                    count($per_instance),
                    $dominant_count > 0
                        ? $per_instance_total / $dominant_count / 1024
                        : 0,
                    count($shared),
                ),
                facts: [
                    'class_name' => $dominant_class,
                    'instance_count' => $dominant_count,
                    'per_instance_properties' => $per_instance,
                    'shared_properties' => $shared,
                    'per_instance_total_bytes' => $per_instance_total,
                ],
                hypothesis: 'Per-instance properties scale linearly;'
                    . ' shared properties use CoW.'
                    . "\n" . implode("\n", $lines),
                next_checks: [
                    'Per-instance properties with small values may benefit from lazy init',
                    'Check if per-instance arrays can be replaced with defaults',
                ],
                impact_bytes: $per_instance_total,
            ),
        ];
    }
}
