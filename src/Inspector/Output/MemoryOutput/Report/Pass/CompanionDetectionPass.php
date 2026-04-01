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

final class CompanionDetectionPass implements PassInterface
{
    /** @param array<string, array{count: int, memory_usage: int}> $class_objects_summary */
    public function __construct(
        private array $class_objects_summary,
    ) {
    }

    /**
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedOperand, InvalidOperand
     * @psalm-suppress TypeDoesNotContainType
     */
    #[\Override]
    public function analyze(): array
    {
        $classes = [];
        foreach ($this->class_objects_summary as $name => $entry) {
            if ($entry['count'] > 100) {
                $classes[] = [
                    'name' => $name,
                    'count' => $entry['count'],
                    'memory' => $entry['memory_usage'],
                ];
            }
        }

        // Union-Find to group classes with similar counts
        $count = count($classes);
        /** @var array<int, int> */
        $uf_parent = range(0, max($count - 1, 0));

        /**
         * @param array<int, int> $p
         * @psalm-suppress MixedArrayOffset, MixedArrayTypeCoercion, MixedReturnStatement
         */
        $ufFind = static function (array &$p, int $x): int {
            while ($p[$x] !== $x) {
                $p[$x] = $p[$p[$x]];
                $x = $p[$x];
            }
            return $x;
        };

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $diff = abs($classes[$i]['count'] - $classes[$j]['count']);
                if ($diff < $classes[$i]['count'] * 0.05) {
                    $ra = $ufFind($uf_parent, $i);
                    $rb = $ufFind($uf_parent, $j);
                    if ($ra !== $rb) {
                        $uf_parent[$ra] = $rb;
                    }
                }
            }
        }

        // Group by root
        /** @var array<int, list<int>> */
        $groups = [];
        for ($i = 0; $i < $count; $i++) {
            $groups[$ufFind($uf_parent, $i)][] = $i;
        }

        $findings = [];
        foreach ($groups as $members) {
            if (count($members) < 2) {
                continue;
            }

            $group_classes = array_map(
                fn(int $idx) => $classes[$idx],
                $members
            );
            $total_memory = array_sum(
                array_column($group_classes, 'memory')
            );

            // Sort by memory descending within group
            usort(
                $group_classes,
                fn($a, $b) => $b['memory'] <=> $a['memory']
            );

            $names = array_map(
                fn($c) => sprintf(
                    '%s (%s)',
                    preg_replace('/^.*\\\\/', '', $c['name']) ?? $c['name'],
                    number_format($c['count'])
                ),
                $group_classes
            );

            if (count($members) === 2) {
                $findings[] = new Finding(
                    kind: 'companion_pair',
                    severity: FindingSeverity::Medium,
                    confidence: FindingConfidence::Medium,
                    summary: sprintf(
                        '%s always paired with %s',
                        $names[0],
                        $names[1],
                    ),
                    facts: [
                        'classes' => array_map(
                            fn($c) => [
                                'name' => $c['name'],
                                'count' => $c['count'],
                            ],
                            $group_classes
                        ),
                        'total_memory' => $total_memory,
                    ],
                    hypothesis: 'These classes are likely created together'
                        . ' — one owns the other',
                    next_checks: [
                        'Check if one class references the other',
                        'Reducing one will likely reduce the other',
                    ],
                    impact_bytes: $total_memory,
                );
            } else {
                $findings[] = new Finding(
                    kind: 'companion_cluster',
                    severity: FindingSeverity::Medium,
                    confidence: FindingConfidence::Medium,
                    summary: sprintf(
                        '%d classes with ~%s instances each: %s',
                        count($members),
                        number_format($group_classes[0]['count']),
                        implode(', ', $names),
                    ),
                    facts: [
                        'classes' => array_map(
                            fn($c) => [
                                'name' => $c['name'],
                                'count' => $c['count'],
                            ],
                            $group_classes
                        ),
                        'total_memory' => $total_memory,
                    ],
                    hypothesis: 'These classes are created together'
                        . ' as a group — likely one per entity',
                    next_checks: [
                        'Find the common owner that creates all of them',
                        'Reducing the owner count reduces all proportionally',
                    ],
                    impact_bytes: $total_memory,
                );
            }
        }

        return $findings;
    }
}
