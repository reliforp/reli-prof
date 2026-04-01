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
            $groups_sorted[] = ['sig' => $sig, 'group' => $group, 'total' => $total];
        }
        usort($groups_sorted, fn($a, $b) => $b['total'] <=> $a['total']);

        $findings = [];
        foreach (array_slice($groups_sorted, 0, 10) as $g) {
            $group = $g['group'];
            $example = $group[0];
            $count = count($group);

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
                        $g['sig'],
                        $g['total'] / 1024,
                    ),
                    facts: [
                        'pattern' => $g['sig'],
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
                $findings[] = new Finding(
                    kind: 'cycle_cluster',
                    severity: $g['total'] > 1024 * 100 ? FindingSeverity::Medium : FindingSeverity::Low,
                    confidence: FindingConfidence::High,
                    summary: sprintf(
                        '%d identical cycle%s: %s (%d nodes each, %.2f KB total)',
                        $count,
                        $count > 1 ? 's' : '',
                        $g['sig'],
                        $example['node_count'],
                        $g['total'] / 1024,
                    ),
                    facts: [
                        'pattern' => $g['sig'],
                        'count' => $count,
                        'nodes_per_cycle' => $example['node_count'],
                        'size_per_cycle' => $example['total_size'],
                        'total_size' => $g['total'],
                        'ext_incoming' => $example['ext_in'],
                        'ext_outgoing' => $example['ext_out'],
                        'single_owner_likelihood' => $example['single_owner_likelihood'],
                    ],
                    hypothesis: $example['single_owner_likelihood'] === 'high'
                        ? 'Single entry point — breaking the owner reference likely frees this cycle'
                        : ($count > 10
                            ? "{$count} identical cycles — a structural pattern, not accidental"
                            : 'Circular reference chain'),
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
}
