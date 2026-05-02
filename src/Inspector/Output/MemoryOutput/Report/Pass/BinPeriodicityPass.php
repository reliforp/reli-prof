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

use PhpCast\Cast;
use Reli\Inspector\Output\MemoryOutput\Report\Finding;
use Reli\Inspector\Output\MemoryOutput\Report\FindingConfidence;
use Reli\Inspector\Output\MemoryOutput\Report\FindingSeverity;

/**
 * Surface periodic same-shape allocation runs detected by the bin walker.
 *
 * "Periodic" here is the design's "stride 32, count 16,000" pattern — the
 * fingerprint of an extension leaking N copies of a single struct. The bin
 * walker has already done the grouping; this pass only renders the result
 * as findings with the right severity (Medium when a single group dominates
 * a bin; Info otherwise).
 */
final class BinPeriodicityPass implements PassInterface
{
    /**
     * Above this fraction of a bin's live slots, a single periodic group is
     * treated as a likely orphan-allocation hotspot.
     */
    private const HOTSPOT_FRACTION = 0.5;

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

        $groups = $this->decode($flat['bin_walk_periodic_groups'] ?? null);
        if ($groups === null || $groups === []) {
            return [];
        }

        // Total live count per bin lets us compute hotspot ratios.
        /** @var array<int, int> $bin_counts */
        $bin_counts = [];
        $bin_walk = $this->decode($flat['bin_walk'] ?? null);
        if (is_array($bin_walk) && isset($bin_walk['histogram']) && is_array($bin_walk['histogram'])) {
            foreach ($bin_walk['histogram'] as $bin_num => $row) {
                if (is_array($row)) {
                    $bin_counts[(int)$bin_num] = (int)($row['count'] ?? 0);
                }
            }
        }

        $findings = [];
        foreach ($groups as $g) {
            if (!is_array($g)) {
                continue;
            }
            $bin_num = (int)($g['bin_num'] ?? 0);
            $bin_size = (int)($g['bin_size'] ?? 0);
            $count = (int)($g['count'] ?? 0);
            $stride = (int)($g['stride'] ?? 0);
            $fp = (string)($g['fingerprint'] ?? '');
            $sample_addr = (int)($g['sample_addr'] ?? 0);
            $inferred_shape = isset($g['inferred_shape']) ? (string)$g['inferred_shape'] : '';
            $inferred_confidence = isset($g['inferred_confidence'])
                ? (string)$g['inferred_confidence']
                : '';
            if ($count <= 0 || $bin_size <= 0) {
                continue;
            }

            $bin_total = $bin_counts[$bin_num] ?? 0;
            $is_hotspot = $bin_total > 0
                && Cast::toFloat($count) / Cast::toFloat($bin_total) >= self::HOTSPOT_FRACTION;

            // Inferred shape, when present, leads the summary text — it's
            // the bit a human reader actually wants ("16,000 Buckets")
            // rather than the raw fingerprint.
            $shape_prefix = $inferred_shape !== ''
                ? sprintf('%s × ', $inferred_shape)
                : '';

            $facts = [
                'bin_num' => $bin_num,
                'bin_size' => $bin_size,
                'count' => $count,
                'stride' => $stride,
                'fingerprint' => $fp,
                'sample_addr' => $sample_addr,
                'bin_total' => $bin_total,
            ];
            if ($inferred_shape !== '') {
                $facts['inferred_shape'] = $inferred_shape;
                $facts['inferred_confidence'] = $inferred_confidence;
            }

            $findings[] = new Finding(
                kind: $is_hotspot ? 'bin_periodic_hotspot' : 'bin_periodic_group',
                severity: $is_hotspot ? FindingSeverity::Medium : FindingSeverity::Info,
                confidence: FindingConfidence::Medium,
                summary: sprintf(
                    '%sbin[%d B]: %s same-shape live slots, stride %d B%s'
                    . ' (sample 0x%x, fp %s%s)',
                    $shape_prefix,
                    $bin_size,
                    number_format($count),
                    $stride,
                    $bin_total > 0
                        ? sprintf(
                            ' — %.0f%% of bin',
                            Cast::toFloat($count) / Cast::toFloat($bin_total) * 100.0,
                        )
                        : '',
                    $sample_addr,
                    substr($fp, 0, 16),
                    strlen($fp) > 16 ? '…' : '',
                ),
                facts: $facts,
                hypothesis: $is_hotspot
                    ? 'Likely orphan / C-extension emalloc — many same-shape allocations'
                        . ' dominate this bin without a typed PHP root.'
                    : '',
                impact_bytes: $count * $bin_size,
            );
        }

        return $findings;
    }

    /**
     * @return list<array<string, mixed>>|array<string, mixed>|null
     * @psalm-suppress MixedAssignment
     */
    private function decode(mixed $value): array|null
    {
        if (is_array($value)) {
            /** @var list<array<string, mixed>>|array<string, mixed> $value */
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                /** @var list<array<string, mixed>>|array<string, mixed> $decoded */
                return $decoded;
            }
        }
        return null;
    }
}
