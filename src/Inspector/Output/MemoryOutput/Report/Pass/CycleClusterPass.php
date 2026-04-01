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
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument
     * @psalm-suppress MixedOperand, InvalidOperand
     * @psalm-suppress PossiblyInvalidArgument, InvalidArgument
     */
    #[\Override]
    public function analyze(): array
    {
        if ($this->substrate->scc_profiles === []) {
            return [];
        }

        $pattern_groups = [];
        foreach ($this->substrate->scc_profiles as $profile) {
            $pattern_groups[$profile['signature']][] = $profile;
        }

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
            $class_count = count($example['class_counts']);

            $composition = $this->formatComposition(
                $example['class_counts']
            );

            // Micro-cycles (2 nodes)
            if ($example['node_count'] === 2) {
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
                        'class_count' => $class_count,
                        'total_size' => $g['total'],
                    ],
                    hypothesis: 'Bidirectional references'
                        . ' between two objects',
                    next_checks: [
                        'Check if back-reference can use WeakReference',
                    ],
                    impact_bytes: $g['total'],
                );
                continue;
            }

            // Large DI-container-like cycles (many classes, typically 1 instance)
            if ($class_count > 15 && $count <= 2) {
                $findings[] = new Finding(
                    kind: 'di_container_cycle',
                    severity: FindingSeverity::Info,
                    confidence: FindingConfidence::High,
                    summary: sprintf(
                        '%d classes forming %d cycle%s (%.2f KB)',
                        $class_count,
                        $count,
                        $count > 1 ? 's' : '',
                        $g['total'] / 1024,
                    ),
                    facts: [
                        'composition' => $composition,
                        'count' => $count,
                        'class_count' => $class_count,
                        'total_size' => $g['total'],
                    ],
                    hypothesis: 'Structural cost of DI container.'
                        . "\nTop classes: {$composition}",
                    next_checks: [
                        'Reducing container instances'
                        . ' (workers/tests) reduces this',
                    ],
                    impact_bytes: $g['total'],
                );
                continue;
            }

            // Normal cycle clusters
            $findings[] = new Finding(
                kind: 'cycle_cluster',
                severity: $g['total'] > 1024 * 100
                    ? FindingSeverity::Medium
                    : FindingSeverity::Low,
                confidence: FindingConfidence::High,
                summary: sprintf(
                    '%d identical cycle%s (%d classes, %.2f KB total)',
                    $count,
                    $count > 1 ? 's' : '',
                    $class_count,
                    $g['total'] / 1024,
                ),
                facts: [
                    'composition' => $composition,
                    'count' => $count,
                    'class_count' => $class_count,
                    'total_size' => $g['total'],
                    'ext_incoming' => $example['ext_in'],
                    'single_owner_likelihood' => $example['single_owner_likelihood'],
                ],
                hypothesis: "Per cycle: {$composition}\n"
                    . ($example['single_owner_likelihood'] === 'high'
                        ? 'Single entry point — breaking the owner'
                        . ' reference likely frees this cycle'
                        : ($count > 10
                            ? "{$count} identical cycles"
                            . ' — a structural pattern'
                            : 'Circular reference chain')),
                next_checks: [
                    'Identify the back-reference causing the cycle',
                    'Consider using WeakReference or explicit cleanup',
                ],
                impact_bytes: $g['total'],
                evidence_node_ids: array_slice($example['nodes'], 0, 5),
            );
        }

        return $findings;
    }

    /**
     * Format class_counts as "1x Message + 3x Attachment".
     * Truncates to top 5 if more than 5 classes.
     * @param array<string, int> $class_counts
     */
    private function formatComposition(array $class_counts): string
    {
        $parts = [];
        $i = 0;
        foreach ($class_counts as $cls => $cnt) {
            if ($i >= 5) {
                $remaining = count($class_counts) - 5;
                $parts[] = "... +{$remaining} more";
                break;
            }
            $parts[] = "{$cnt}x {$cls}";
            $i++;
        }
        return implode(' + ', $parts);
    }
}
