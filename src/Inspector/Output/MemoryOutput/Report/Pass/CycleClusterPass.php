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

final class CycleClusterPass implements PassInterface
{
    public function __construct(
        private GraphSubstrate $substrate,
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
        if ($this->substrate->scc_profiles === []) {
            return [];
        }

        // Group by signature
        $pattern_groups = [];
        foreach ($this->substrate->scc_profiles as $profile) {
            $pattern_groups[$profile['signature']][] = $profile;
        }

        // Sort by total memory impact
        $groups_sorted = [];
        foreach ($pattern_groups as $sig => $group) {
            $total = 0;
            foreach ($group as $profile) {
                $total += $profile['total_size'];
            }
            $groups_sorted[] = [
                'sig' => $sig,
                'group' => $group,
                'total' => $total,
            ];
        }
        usort($groups_sorted, fn($a, $b) => $b['total'] <=> $a['total']);

        $findings = [];
        foreach (array_slice($groups_sorted, 0, 10) as $g) {
            $group = $g['group'];
            $example = $group[0];
            $count = count($group);

            // Build "Nx Class" composition string
            $composition = $this->formatComposition($example['class_counts']);

            // Object count vs total nodes
            $object_count = array_sum($example['class_counts']);
            $internal_count = $example['node_count'] - $object_count;

            // Micro-cycles (2 nodes)
            $is_micro = $example['node_count'] === 2;

            if ($is_micro) {
                $findings[] = new Finding(
                    kind: 'micro_cycle',
                    severity: FindingSeverity::Low,
                    confidence: FindingConfidence::High,
                    summary: sprintf(
                        '%s micro-cycle%s: %s (%.2f KB total)',
                        number_format($count),
                        $count > 1 ? 's' : '',
                        $composition,
                        $g['total'] / 1024,
                    ),
                    facts: [
                        'composition' => $composition,
                        'count' => $count,
                        'nodes_per_cycle' => $example['node_count'],
                        'total_size' => $g['total'],
                    ],
                    hypothesis: 'Bidirectional references between two objects',
                    next_checks: [
                        'Check if back-reference can be replaced with WeakReference',
                    ],
                    impact_bytes: $g['total'],
                );
            } else {
                $node_desc = $internal_count > 0
                    ? sprintf(
                        '%d object%s + %d internal nodes per cycle',
                        $object_count,
                        $object_count > 1 ? 's' : '',
                        $internal_count,
                    )
                    : sprintf(
                        '%d object%s per cycle',
                        $object_count,
                        $object_count > 1 ? 's' : '',
                    );

                $findings[] = new Finding(
                    kind: 'cycle_cluster',
                    severity: $g['total'] > 1024 * 100
                        ? FindingSeverity::Medium
                        : FindingSeverity::Low,
                    confidence: FindingConfidence::High,
                    summary: sprintf(
                        '%d identical cycle%s (%s, %.2f KB total)',
                        $count,
                        $count > 1 ? 's' : '',
                        $node_desc,
                        $g['total'] / 1024,
                    ),
                    facts: [
                        'composition' => $composition,
                        'count' => $count,
                        'object_count_per_cycle' => $object_count,
                        'internal_nodes_per_cycle' => $internal_count,
                        'size_per_cycle' => $example['total_size'],
                        'total_size' => $g['total'],
                        'ext_incoming' => $example['ext_in'],
                        'ext_outgoing' => $example['ext_out'],
                        'single_owner_likelihood' => $example['single_owner_likelihood'],
                    ],
                    hypothesis: "Per cycle: {$composition}\n"
                        . ($example['single_owner_likelihood'] === 'high'
                            ? 'Single entry point — breaking the owner reference likely frees this cycle'
                            : ($count > 10
                                ? "{$count} identical cycles — a structural pattern, not accidental"
                                : 'Circular reference chain')),
                    next_checks: [
                        'Identify the back-reference causing the cycle',
                        'Consider using WeakReference or explicit cleanup',
                    ],
                    impact_bytes: $g['total'],
                    evidence_node_ids: array_slice($example['nodes'], 0, 5),
                );
            }
        }

        return $findings;
    }

    /**
     * Format class_counts as "1x Message + 3x Attachment + 1x AttachmentCollection"
     * @param array<string, int> $class_counts
     */
    private function formatComposition(array $class_counts): string
    {
        $parts = [];
        foreach ($class_counts as $cls => $cnt) {
            $parts[] = "{$cnt}x {$cls}";
        }
        return implode(' + ', $parts);
    }
}
