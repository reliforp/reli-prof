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
use Reli\Lib\PhpInternals\Types\Zend\ZendMmBinsInfo;

/**
 * Surface the per-bin live-allocation histogram produced by the bin walker.
 *
 * Emits one informational `bin_histogram_entry` finding per non-empty small
 * bin plus a single `bin_histogram_overview` line. These findings carry the
 * raw shape of "where is the heap right now": whether the leak is in 32 B
 * Buckets, 192 B stat structs, large pages, etc. The text formatter renders
 * them as a table; the comparison path can diff them across runs.
 *
 * See docs/internals/design-orphan-allocation-analysis.md (sections A and
 * "Output integration").
 */
final class BinHistogramPass implements PassInterface
{
    /** @param array<int, array<string, mixed>> $summary */
    public function __construct(
        private array $summary,
    ) {
    }

    /**
     * @return list<Finding>
     * @psalm-suppress MixedAssignment, MixedArgument, MixedArrayAccess, MixedOperand
     */
    #[\Override]
    public function analyze(): array
    {
        $flat = [];
        foreach ($this->summary as $entry) {
            foreach ($entry as $k => $v) {
                $flat[$k] = $v;
            }
        }

        $bin_walk = $this->decode($flat['bin_walk'] ?? null);
        if ($bin_walk === null) {
            return [];
        }

        $histogram = $bin_walk['histogram'] ?? [];
        if (!is_array($histogram) || $histogram === []) {
            return [];
        }

        /** @var array<int, array{count: int, total_bytes: int}> $histogram_typed */
        $histogram_typed = [];
        foreach ($histogram as $bin_num => $row) {
            if (!is_array($row)) {
                continue;
            }
            $histogram_typed[(int)$bin_num] = [
                'count' => (int)($row['count'] ?? 0),
                'total_bytes' => (int)($row['total_bytes'] ?? 0),
            ];
        }
        ksort($histogram_typed);

        $live_count = (int)($bin_walk['live_small_slot_count'] ?? 0);
        $live_bytes = (int)($bin_walk['live_small_slot_bytes'] ?? 0);
        $large_count = (int)($bin_walk['large_run_count'] ?? 0);
        $large_bytes = (int)($bin_walk['large_run_bytes'] ?? 0);
        $walked_chunks = (int)($bin_walk['walked_chunk_count'] ?? 0);
        $partial = (bool)($bin_walk['partial'] ?? false);

        $findings = [];

        $findings[] = new Finding(
            kind: 'bin_histogram_overview',
            severity: FindingSeverity::Info,
            confidence: FindingConfidence::High,
            summary: sprintf(
                'ZendMM live: %s in %s small slots across %d bin classes'
                . ' + %s in %d large runs (%d chunk%s walked%s)',
                SizeFormatter::format($live_bytes),
                number_format($live_count),
                count($histogram_typed),
                SizeFormatter::format($large_bytes),
                $large_count,
                $walked_chunks,
                $walked_chunks === 1 ? '' : 's',
                $partial ? ', partial walk' : '',
            ),
            facts: [
                'live_small_slot_count' => $live_count,
                'live_small_slot_bytes' => $live_bytes,
                'large_run_count' => $large_count,
                'large_run_bytes' => $large_bytes,
                'walked_chunk_count' => $walked_chunks,
                'partial' => $partial,
            ],
        );

        foreach ($histogram_typed as $bin_num => $row) {
            $bin_size = ZendMmBinsInfo::getSize($bin_num);
            $findings[] = new Finding(
                kind: 'bin_histogram_entry',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::High,
                summary: sprintf(
                    'bin[%d B]: %s live (%s)',
                    $bin_size,
                    number_format($row['count']),
                    SizeFormatter::format($row['total_bytes']),
                ),
                facts: [
                    'bin_num' => $bin_num,
                    'bin_size' => $bin_size,
                    'count' => $row['count'],
                    'total_bytes' => $row['total_bytes'],
                ],
                impact_bytes: $row['total_bytes'],
            );
        }

        return $findings;
    }

    /**
     * Tolerate either a JSON string (from rmem / sqlite summary) or a
     * decoded array (in-process tests).
     *
     * @return array<string, mixed>|null
     * @psalm-suppress MixedAssignment
     */
    private function decode(mixed $value): ?array
    {
        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }
        return null;
    }
}
