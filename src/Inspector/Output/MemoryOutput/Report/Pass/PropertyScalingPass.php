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
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\LinkNameResolver;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\SizeFormatter;

final class PropertyScalingPass implements PassInterface
{
    /**
     * @param array<string, array{count: int, memory_usage: int}> $class_objects_summary
     * @param int $heap_total_bytes Optional heap-total clamp for impact_bytes.
     *     0 = no clamp. Per-instance retained sizes can include shared
     *     subtrees, so summing them over all instances may exceed the heap
     *     total (e.g. graph-recursion: 53.73 GB on a 111 MB heap). When
     *     non-zero, the per-instance total is clamped down and the
     *     unclamped value preserved in `per_instance_total_bytes_unclamped`
     *     plus a hypothesis note. See N10 in
     *     memory-report-ux-improvements.md.
     */
    public function __construct(
        private \PDO $db,
        private int $run_id,
        private array $class_objects_summary,
        private ?GraphSubstrate $substrate = null,
        private ?LinkNameResolver $link_resolver = null,
        private int $heap_total_bytes = 0,
    ) {
    }

    /**
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument
     * @psalm-suppress MixedOperand, InvalidOperand, MixedArrayOffset, PossiblyInvalidArgument
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

        if ($this->substrate !== null) {
            $props = $this->analyzeWithGraph($dominant_class);
        } else {
            $props = $this->analyzeWithSql($dominant_class);
        }

        if ($props === []) {
            return [];
        }

        $use_retained = $this->substrate !== null
            && $this->substrate->hasSubtreeSizes();

        $per_instance = [];
        $shared = [];
        $scalar_count = 0;

        foreach ($props as $p) {
            if ($p['scaling'] !== 'SHARED') {
                if ($p['size'] === 0) {
                    $scalar_count++;
                    continue;
                }
                $per_instance[] = $p;
            } else {
                $shared[] = $p;
            }
        }

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
                    '  %s::$%s: %s copies x %s = %s',
                    $short,
                    $p['property'],
                    number_format($p['distinct_targets']),
                    SizeFormatter::format($avg),
                    SizeFormatter::format($p['size']),
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

        // Clamp to heap total when known. The per-instance retained sum
        // can over-count shared subtrees (every instance's retained size
        // includes the whole shared part), producing impacts that exceed
        // the heap total. Mirrors the DedupCandidatePass clamp — see N10
        // in memory-report-ux-improvements.md.
        $clamped_from = null;
        $impact_bytes = $per_instance_total;
        if ($this->heap_total_bytes > 0 && $impact_bytes > $this->heap_total_bytes) {
            $clamped_from = $impact_bytes;
            $impact_bytes = $this->heap_total_bytes;
        }

        $hypothesis = 'Per-instance properties scale linearly;'
            . ' shared properties have constant cost.'
            . "\n" . implode("\n", $lines);
        if ($clamped_from !== null) {
            $hypothesis .= sprintf(
                "\n(impact clamped from %s to heap total — per-instance"
                . ' retained over-counts shared subtree memory)',
                SizeFormatter::format($clamped_from),
            );
        }

        $facts = [
            'class_name' => $dominant_class,
            'instance_count' => $dominant_count,
            'per_instance_properties' => $per_instance,
            'shared_properties' => $shared,
            'scalar_properties_count' => $scalar_count,
            'per_instance_total_bytes' => $per_instance_total,
            'size_mode' => $size_label,
        ];
        if ($clamped_from !== null) {
            $facts['per_instance_total_bytes_unclamped'] = $clamped_from;
        }

        return [
            new Finding(
                kind: 'property_scaling',
                severity: FindingSeverity::Medium,
                confidence: FindingConfidence::Medium,
                summary: sprintf(
                    '%s (%s instances): %d per-instance props'
                    . ' (%s/instance %s), %d shared',
                    $short,
                    number_format($dominant_count),
                    count($per_instance),
                    SizeFormatter::format(
                        $dominant_count > 0
                            ? (int)($per_instance_total / $dominant_count)
                            : 0
                    ),
                    $size_label,
                    count($shared),
                ),
                facts: $facts,
                hypothesis: $hypothesis,
                next_checks: [
                    'Per-instance props with small values'
                    . ' may benefit from lazy init',
                    'Check if per-instance arrays can be replaced'
                    . ' with defaults',
                ],
                impact_bytes: $impact_bytes,
            ),
        ];
    }

    /**
     * In-memory analysis using GraphSubstrate. O(nodes).
     * @return list<array<string, mixed>>
     * @psalm-suppress InvalidOperand
     */
    private function analyzeWithGraph(string $dominant_class): array
    {
        assert($this->substrate !== null);
        $use_retained = $this->substrate->hasSubtreeSizes();

        $resolver = $this->link_resolver ?? new LinkNameResolver($this->db, $this->run_id);

        // For each object of dominant_class, walk children to find
        // object_properties → property children
        /** @var array<string, array{distinct: array<int, true>, total_refs: int, size: int}> */
        $prop_stats = [];

        foreach ($this->substrate->iterateNodeClasses() as $node_id => $cls) {
            if ($cls !== $dominant_class) {
                continue;
            }
            // Skip non-canonical duplicates to avoid double-counting instances
            if (!$this->substrate->isCanonicalOrUnique($node_id)) {
                continue;
            }
            foreach ($this->substrate->getChildren($node_id) as $child) {
                if (($resolver->lookup($child) ?? '') !== 'object_properties') {
                    continue;
                }
                // child = ObjectPropertiesContext
                // Deduplicate property children by canonical
                $seen_prop_canonicals = [];
                foreach ($this->substrate->getChildren($child) as $prop_child) {
                    $prop_name = $resolver->lookup($prop_child);
                    if ($prop_name === null) {
                        continue;
                    }
                    $prop_canon = $this->substrate->getCanonical($prop_child);
                    if (isset($seen_prop_canonicals[$prop_canon])) {
                        continue;
                    }
                    $seen_prop_canonicals[$prop_canon] = true;

                    if (!isset($prop_stats[$prop_name])) {
                        $prop_stats[$prop_name] = [
                            'distinct' => [],
                            'total_refs' => 0,
                            'size' => 0,
                        ];
                    }
                    $prop_stats[$prop_name]['distinct'][$prop_canon] = true;
                    $prop_stats[$prop_name]['total_refs']++;
                    // Use retained if available, else shallow
                    if ($use_retained) {
                        $prop_stats[$prop_name]['size']
                            += $this->substrate->getSubtreeSize($prop_child);
                    } else {
                        $prop_stats[$prop_name]['size']
                            += $this->substrate->getNodeSize($prop_child);
                    }
                }
                break;
            }
        }

        // Also count non-tree refs for scaling classification
        // (check all_children for non-tree property edges)
        // Not needed for graph — tree edges are sufficient for
        // distinct_targets counting.

        $results = [];
        foreach ($prop_stats as $prop_name => $stat) {
            $distinct = count($stat['distinct']);
            $total_refs = $stat['total_refs'];

            if ($distinct === 1) {
                $scaling = 'SHARED';
            } elseif ($distinct >= $total_refs * 0.9) {
                $scaling = 'PER-INSTANCE';
            } else {
                $scaling = 'PARTIALLY SHARED';
            }

            // Get a sample child_id for shared reason classification
            $sample_id = array_key_first($stat['distinct']) ?? 0;

            $results[] = [
                'property' => $prop_name,
                'distinct_targets' => $distinct,
                'total_refs' => $total_refs,
                'size' => $stat['size'],
                'scaling' => $scaling,
                'sample_child_id' => $sample_id,
            ];
        }

        return $results;
    }

    /**
     * SQL-based analysis (Phase 2 fallback).
     * @return list<array<string, mixed>>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, InvalidOperand
     */
    private function analyzeWithSql(string $dominant_class): array
    {
        $stmt = $this->db->query("
            SELECT
                e_prop.link_name,
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
        ");

        $results = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $distinct = (int)$row['distinct_targets'];
            $total_refs = (int)$row['total_refs'];

            if ($distinct === 1) {
                $scaling = 'SHARED';
            } elseif ($distinct >= $total_refs * 0.9) {
                $scaling = 'PER-INSTANCE';
            } else {
                $scaling = 'PARTIALLY SHARED';
            }

            $results[] = [
                'property' => $row['link_name'],
                'distinct_targets' => $distinct,
                'total_refs' => $total_refs,
                'size' => (int)$row['tree_size'],
                'scaling' => $scaling,
                'sample_child_id' => (int)$row['sample_child_id'],
            ];
        }
        return $results;
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

        $info = [];
        if ($this->substrate !== null) {
            // Substrate path: get node type directly, skip location_type
            // (it's only used for label refinement, not critical)
            foreach ($child_ids as $cid) {
                $type = $this->substrate->getNodeType($cid) ?? '';
                $info[$cid] = ['type' => $type, 'loc_type' => ''];
            }
        } else {
            $placeholders = implode(',', $child_ids);
            $stmt = $this->db->query(
                "SELECT cn.node_id, cn.type, cnl.location_type"
                . " FROM context_nodes cn"
                . " LEFT JOIN context_node_locations cnl"
                . " ON cnl.node_id = cn.node_id"
                . " AND cnl.run_id = cn.run_id"
                . " WHERE cn.run_id = {$this->run_id}"
                . " AND cn.node_id IN ({$placeholders})"
            );
            while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $info[(int)$r['node_id']] = [
                    'type' => (string)($r['type'] ?? ''),
                    'loc_type' => (string)($r['location_type'] ?? ''),
                ];
            }
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
