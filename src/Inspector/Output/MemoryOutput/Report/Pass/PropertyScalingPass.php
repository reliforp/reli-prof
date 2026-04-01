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

final class PropertyScalingPass implements PassInterface
{
    /**
     * @param array<string, array{count: int, memory_usage: int}> $class_objects_summary
     */
    public function __construct(
        private \PDO $db,
        private int $run_id,
        private array $class_objects_summary,
        private ?GraphSubstrate $substrate = null,
    ) {
    }

    /**
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument
     * @psalm-suppress MixedOperand, InvalidOperand, MixedArrayOffset
     */
    #[\Override]
    public function analyze(): array
    {
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

        $use_retained = $this->substrate !== null
            && $this->substrate->subtree_sizes !== [];

        $rows = $this->db->query("
            SELECT
                e_prop.link_name,
                e_prop.child_node_id,
                count(*) as total_refs,
                count(DISTINCT e_prop.child_node_id) as distinct_targets,
                min(e_prop.child_node_id) as sample_child_id,
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

        // Collect child_node_ids per property for retained size lookup
        $prop_children = [];
        if ($use_retained) {
            $child_rows = $this->db->query("
                SELECT
                    e_prop.link_name,
                    e_prop.child_node_id
                FROM context_node_locations cnl_obj
                JOIN context_edges e_to_props
                    ON e_to_props.parent_node_id = cnl_obj.node_id
                    AND e_to_props.link_name = 'object_properties'
                    AND e_to_props.run_id = {$this->run_id}
                JOIN context_edges e_prop
                    ON e_prop.parent_node_id = e_to_props.child_node_id
                    AND e_prop.is_tree = 1
                    AND e_prop.run_id = {$this->run_id}
                WHERE cnl_obj.class_name = "
                . $this->db->quote($dominant_class) . "
                    AND cnl_obj.run_id = {$this->run_id}
            ")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($child_rows as $cr) {
                $prop_children[$cr['link_name']][] = (int)$cr['child_node_id'];
            }
            unset($child_rows);
        }

        $per_instance = [];
        $shared = [];
        $scalar_count = 0;

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

            // Compute retained size if available
            $prop_name = $row['link_name'];
            $retained_total = 0;
            if ($use_retained && isset($prop_children[$prop_name])) {
                foreach ($prop_children[$prop_name] as $cid) {
                    $retained_total += $this->substrate->subtree_sizes[$cid] ?? 0;
                }
            }

            $size = $use_retained && $retained_total > 0
                ? $retained_total
                : $tree_size;

            if ($scaling !== 'SHARED') {
                // Skip size=0 PER-INSTANCE (scalar zval, already in object size)
                if ($size === 0) {
                    $scalar_count++;
                    continue;
                }
                $per_instance[] = [
                    'property' => $prop_name,
                    'distinct_targets' => $distinct,
                    'total_refs' => $total_refs,
                    'size' => $size,
                    'retained' => $use_retained,
                    'scaling' => $scaling,
                ];
            } else {
                $sample_id = (int)($row['sample_child_id'] ?? 0);
                $shared[] = [
                    'property' => $prop_name,
                    'distinct_targets' => $distinct,
                    'total_refs' => $total_refs,
                    'size' => $size,
                    'scaling' => $scaling,
                    'sample_child_id' => $sample_id,
                ];
            }
        }

        // Classify sharing reason for shared properties
        $this->classifySharedReasons($shared);

        $short = $dominant_class;
        $size_label = $use_retained ? 'retained' : 'shallow';

        $lines = [];
        if ($per_instance !== []) {
            $lines[] = "PER-INSTANCE ({$size_label}, scales with count):";
            usort(
                $per_instance,
                fn($a, $b) => $b['size'] <=> $a['size']
            );
            foreach (array_slice($per_instance, 0, 8) as $p) {
                $avg = $p['distinct_targets'] > 0
                    ? (int)($p['size'] / $p['distinct_targets'])
                    : 0;
                $lines[] = sprintf(
                    '  %s::$%s: %s copies x %dB = %.2f KB',
                    $short,
                    $p['property'],
                    number_format($p['distinct_targets']),
                    $avg,
                    $p['size'] / 1024,
                );
            }
        }
        if ($scalar_count > 0) {
            $lines[] = sprintf(
                '(%d scalar properties per-instance,'
                . ' included in object size)',
                $scalar_count
            );
        }
        if ($shared !== []) {
            $shared_names = array_map(
                function ($s) use ($short): string {
                    $reason = $s['share_reason'] ?? '';
                    $suffix = $reason !== '' ? " ({$reason})" : '';
                    return $short . '::$' . $s['property'] . $suffix;
                },
                array_slice($shared, 0, 10)
            );
            $lines[] = 'SHARED: '
                . implode(', ', $shared_names);
        }

        $per_instance_total = array_sum(
            array_column($per_instance, 'size')
        );

        return [
            new Finding(
                kind: 'property_scaling',
                severity: FindingSeverity::Medium,
                confidence: FindingConfidence::Medium,
                summary: sprintf(
                    '%s (%s instances): %d per-instance props'
                    . ' (%.2f KB/instance %s), %d shared',
                    $short,
                    number_format($dominant_count),
                    count($per_instance),
                    $dominant_count > 0
                        ? $per_instance_total / $dominant_count / 1024
                        : 0,
                    $size_label,
                    count($shared),
                ),
                facts: [
                    'class_name' => $dominant_class,
                    'instance_count' => $dominant_count,
                    'per_instance_properties' => $per_instance,
                    'shared_properties' => $shared,
                    'scalar_properties_count' => $scalar_count,
                    'per_instance_total_bytes' => $per_instance_total,
                    'size_mode' => $size_label,
                ],
                hypothesis: 'Per-instance properties scale linearly;'
                    . ' shared properties have constant cost.'
                    . "\n" . implode("\n", $lines),
                next_checks: [
                    'Per-instance props with small values'
                    . ' may benefit from lazy init',
                    'Check if per-instance arrays can be replaced'
                    . ' with defaults',
                ],
                impact_bytes: $per_instance_total,
            ),
        ];
    }

    /**
     * Classify sharing reason for each shared property.
     * @param list<array<string, mixed>> $shared
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument
     */
    private function classifySharedReasons(array &$shared): void
    {
        if ($shared === []) {
            return;
        }

        $child_ids = [];
        foreach ($shared as $s) {
            $child_ids[] = (int)$s['sample_child_id'];
        }
        $placeholders = implode(',', $child_ids);

        // Get node type and location_type for shared targets
        $info = [];
        $rows = $this->db->query(
            "SELECT cn.node_id, cn.type, cnl.location_type"
            . " FROM context_nodes cn"
            . " LEFT JOIN context_node_locations cnl"
            . " ON cnl.node_id = cn.node_id"
            . " AND cnl.run_id = cn.run_id"
            . " WHERE cn.run_id = {$this->run_id}"
            . " AND cn.node_id IN ({$placeholders})"
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $info[(int)$r['node_id']] = [
                'type' => (string)($r['type'] ?? ''),
                'loc_type' => (string)($r['location_type'] ?? ''),
            ];
        }

        foreach ($shared as &$s) {
            $cid = (int)$s['sample_child_id'];
            $node_info = $info[$cid] ?? null;

            if ($node_info === null) {
                $s['share_reason'] = 'shared';
                continue;
            }

            $type = $node_info['type'];
            $loc = $node_info['loc_type'];

            if ($type === 'PhpReferenceContext') {
                $s['share_reason'] = 'PHP reference';
            } elseif (str_contains($loc, 'Object')) {
                $s['share_reason'] = 'same instance';
            } elseif (str_contains($loc, 'Array')) {
                $s['share_reason'] = 'array, CoW';
            } elseif (str_contains($loc, 'String')) {
                $s['share_reason'] = 'interned string';
            } else {
                $s['share_reason'] = 'shared';
            }
        }
    }
}
