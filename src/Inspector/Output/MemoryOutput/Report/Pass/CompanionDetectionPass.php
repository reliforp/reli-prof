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
                $classes[] = ['name' => $name, 'count' => $entry['count'], 'memory' => $entry['memory_usage']];
            }
        }

        $findings = [];
        $count = count($classes);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $classes[$i];
                $b = $classes[$j];
                $max_count = max($a['count'], $b['count']);
                if ($max_count === 0) {
                    continue;
                }
                $diff = abs($a['count'] - $b['count']);
                if ($diff < $a['count'] * 0.05) {
                    $short_a = preg_replace('/^.*\\\\/', '', $a['name']) ?? $a['name'];
                    $short_b = preg_replace('/^.*\\\\/', '', $b['name']) ?? $b['name'];
                    $total_memory = $a['memory'] + $b['memory'];

                    $findings[] = new Finding(
                        kind: 'companion_pair',
                        severity: FindingSeverity::Medium,
                        confidence: FindingConfidence::Medium,
                        summary: sprintf(
                            '%s (%s) always paired with %s (%s)',
                            $short_a,
                            number_format($a['count']),
                            $short_b,
                            number_format($b['count']),
                        ),
                        facts: [
                            'class_a' => $a['name'],
                            'count_a' => $a['count'],
                            'class_b' => $b['name'],
                            'count_b' => $b['count'],
                            'total_memory' => $total_memory,
                        ],
                        hypothesis: 'These classes are likely created together — one owns the other',
                        next_checks: [
                            'Check if one class references the other directly',
                            'Reducing one will likely reduce the other proportionally',
                        ],
                        impact_bytes: $total_memory,
                        replay_query: "SELECT a.class_name, a.count, b.class_name, b.count"
                            . " FROM class_objects_summary a JOIN class_objects_summary b"
                            . " ON a.run_id = b.run_id AND a.class_name < b.class_name"
                            . " AND abs(a.count - b.count) < a.count * 0.05"
                            . " WHERE a.count > 100 ORDER BY a.memory_usage + b.memory_usage DESC",
                    );
                }
            }
        }

        return $findings;
    }
}
