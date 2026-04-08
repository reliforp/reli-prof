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

final class ClassRankingPass implements PassInterface
{
    /** @param array<string, array{count: int, memory_usage: int}> $class_objects_summary */
    public function __construct(
        private array $class_objects_summary,
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
        if ($this->class_objects_summary === []) {
            return [];
        }

        $total_object_memory = 0;
        foreach ($this->class_objects_summary as $entry) {
            $total_object_memory += $entry['memory_usage'];
        }
        if ($total_object_memory === 0) {
            return [];
        }

        $findings = [];
        $rank = 0;
        foreach ($this->class_objects_summary as $class_name => $entry) {
            $pct = $entry['memory_usage'] / $total_object_memory * 100.0;
            $avg_size = $entry['count'] > 0
                ? (int)($entry['memory_usage'] / $entry['count'])
                : 0;

            if ($pct > 50.0) {
                $findings[] = new Finding(
                    kind: 'dominant_class',
                    severity: FindingSeverity::High,
                    confidence: FindingConfidence::High,
                    summary: sprintf(
                        '%s: %s instances x %s = %.1f%% of object memory (%s)',
                        $class_name,
                        number_format($entry['count']),
                        SizeFormatter::format($avg_size),
                        $pct,
                        SizeFormatter::format($entry['memory_usage']),
                    ),
                    facts: [
                        'class_name' => $class_name,
                        'count' => $entry['count'],
                        'memory_bytes' => $entry['memory_usage'],
                        'percentage_of_object_memory' => round($pct, 1),
                        'avg_size' => $avg_size,
                    ],
                    hypothesis: 'Unbounded accumulation — likely a loop without limit',
                    next_checks: [
                        'Check if count scales with input size',
                        'Look for owner path to find the accumulating container',
                    ],
                    impact_bytes: $entry['memory_usage'],
                    replay_query: "SELECT class_name, count, memory_usage"
                        . " FROM class_objects_summary ORDER BY memory_usage DESC LIMIT 1",
                );
            }

            if (++$rank <= 20) {
                $findings[] = new Finding(
                    kind: 'class_ranking',
                    severity: FindingSeverity::Info,
                    confidence: FindingConfidence::High,
                    summary: sprintf(
                        '#%d %s: %s instances x %s (%s)',
                        $rank,
                        $class_name,
                        number_format($entry['count']),
                        SizeFormatter::format($avg_size),
                        SizeFormatter::format($entry['memory_usage']),
                    ),
                    facts: [
                        'rank' => $rank,
                        'class_name' => $class_name,
                        'count' => $entry['count'],
                        'memory_bytes' => $entry['memory_usage'],
                        'percentage_of_object_memory' => round($pct, 1),
                        'avg_size' => $avg_size,
                    ],
                    impact_bytes: $entry['memory_usage'],
                );
            }
        }

        return $findings;
    }
}
