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

/**
 * Surface the per-bin shape tally produced by the bin walker's per-slot
 * detector scan.
 *
 * The periodic-group path (BinPeriodicityPass) only fires when many
 * slots share the same fingerprint — which excludes content-varying
 * shapes like Bucket entries, where every slot carries a distinct hash
 * and key pointer. This pass walks the bin walker's per-slot scan
 * results and emits one info-level row per (bin, shape) so users see
 * "76% of bin[3] is Bucket(IS_STRING)" even though no two of those
 * Buckets had the same bytes.
 *
 * The pass is intentionally noisy at Info severity — false positives
 * are real in header-only detection, so the user reads it as evidence,
 * not a verdict. The actionable signal sits in BinPeriodicityPass +
 * the per-bin compare delta.
 */
final class BinShapePass implements PassInterface
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

        $payload = $this->decode($flat['bin_shape_counts'] ?? null);
        if ($payload === null || !isset($payload['bin']) || !is_array($payload['bin'])) {
            return [];
        }

        $findings = [];
        foreach ($payload['bin'] as $bin_row) {
            if (!is_array($bin_row)) {
                continue;
            }
            $bin_num = (int)($bin_row['bin_num'] ?? -1);
            $unclassified = (int)($bin_row['unclassified'] ?? 0);
            $shapes = is_array($bin_row['shapes'] ?? null) ? $bin_row['shapes'] : [];

            if ($bin_num < 0) {
                continue;
            }

            $total = $unclassified;
            foreach ($shapes as $row) {
                if (is_array($row) && isset($row['count'])) {
                    $total += (int)$row['count'];
                }
            }
            if ($total <= 0) {
                continue;
            }

            foreach ($shapes as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $label = (string)($row['label'] ?? '');
                $count = (int)($row['count'] ?? 0);
                $reachable = (int)($row['reachable_count'] ?? 0);
                $confidence = (string)($row['confidence'] ?? 'medium');
                if ($label === '' || $count === 0) {
                    continue;
                }
                $orphan = $count - $reachable;
                $pct = (float)$count / (float)$total * 100.0;

                $findings[] = new Finding(
                    kind: 'bin_shape_count',
                    severity: FindingSeverity::Info,
                    confidence: $this->mapConfidence($confidence),
                    summary: sprintf(
                        'bin[%d]: %s × %s (%.1f%%, %d orphan / %d reachable)',
                        $bin_num,
                        $label,
                        number_format($count),
                        $pct,
                        $orphan,
                        $reachable,
                    ),
                    facts: [
                        'bin_num' => $bin_num,
                        'shape' => $label,
                        'count' => $count,
                        'reachable_count' => $reachable,
                        'orphan_count' => $orphan,
                        'percentage' => round($pct, 1),
                        'confidence' => $confidence,
                    ],
                );
            }

            if ($unclassified > 0) {
                $pct = (float)$unclassified / (float)$total * 100.0;
                $findings[] = new Finding(
                    kind: 'bin_shape_unclassified',
                    severity: FindingSeverity::Info,
                    confidence: FindingConfidence::Low,
                    summary: sprintf(
                        'bin[%d]: unclassified × %s (%.1f%%)',
                        $bin_num,
                        number_format($unclassified),
                        $pct,
                    ),
                    facts: [
                        'bin_num' => $bin_num,
                        'count' => $unclassified,
                        'percentage' => round($pct, 1),
                    ],
                );
            }
        }

        return $findings;
    }

    private function mapConfidence(string $raw): FindingConfidence
    {
        return match ($raw) {
            'high' => FindingConfidence::High,
            'low' => FindingConfidence::Low,
            default => FindingConfidence::Medium,
        };
    }

    /**
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
