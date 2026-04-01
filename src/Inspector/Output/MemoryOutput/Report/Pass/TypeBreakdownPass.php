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

final class TypeBreakdownPass implements PassInterface
{
    /** @param array<string, array{count: int, memory_usage: int}> $location_types_summary */
    public function __construct(
        private array $location_types_summary,
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
        if ($this->location_types_summary === []) {
            return [];
        }

        $total = 0;
        foreach ($this->location_types_summary as $entry) {
            $total += $entry['memory_usage'];
        }
        if ($total === 0) {
            return [];
        }

        $findings = [];
        foreach ($this->location_types_summary as $type => $entry) {
            $pct = $entry['memory_usage'] / $total * 100.0;
            if ($pct > 50.0) {
                $short_type = preg_replace('/^.*\\\\/', '', $type) ?? $type;
                $short_type = str_replace('MemoryLocation', '', $short_type);

                $findings[] = new Finding(
                    kind: 'dominant_type',
                    severity: $pct > 80.0 ? FindingSeverity::High : FindingSeverity::Medium,
                    confidence: FindingConfidence::High,
                    summary: sprintf(
                        '%s accounts for %.1f%% of heap (%s, %.2f MB)',
                        $short_type,
                        $pct,
                        number_format($entry['count']),
                        $entry['memory_usage'] / 1024 / 1024,
                    ),
                    facts: [
                        'type' => $type,
                        'count' => $entry['count'],
                        'memory_usage' => $entry['memory_usage'],
                        'percentage' => round($pct, 1),
                    ],
                    hypothesis: sprintf('Memory is dominated by %s allocations', $short_type),
                    next_checks: [
                        'Check class ranking for the dominant consumers',
                        'Look for unbounded accumulation patterns',
                    ],
                    impact_bytes: $entry['memory_usage'],
                    replay_query: "SELECT type, count, memory_usage"
                        . " FROM location_types_summary ORDER BY memory_usage DESC",
                );
            }
        }

        return $findings;
    }
}
