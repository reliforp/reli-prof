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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk;

/**
 * Output of {@see ZendMmBinWalker::walk()}.
 *
 * Captures the per-bin live-allocation distribution recovered by walking
 * `chunk->map` and subtracting `heap->free_slot[]` freelists. The histogram
 * always covers all 30 small-bin classes (8 B → 3072 B); large/huge runs are
 * summarised separately.
 *
 * Periodic groups are runs of like-fingerprinted live slots in a single bin
 * within a single chunk that the walker observed back-to-back at a constant
 * stride. They are the headline signal for "C-extension is leaking N copies
 * of the same struct" — see docs/internals/design-orphan-allocation-analysis.md
 * section D.
 */
final class BinWalkResult
{
    /**
     * @param array<int, array{count: int, total_bytes: int}> $small_bin_histogram
     *     Keyed by bin id (0..29). Only bins with at least one live slot are present.
     * @param list<PeriodicGroup> $periodic_groups
     * @param array<int, array<string, array{
     *     count: int,
     *     reachable_count: int,
     *     confidence: string
     * }>> $bin_shape_counts
     *     Per-(bin, shape) tally from the per-slot detector scan. Lets the
     *     report surface "76% of bin[3] is Bucket(IS_STRING)" for shapes
     *     the periodic-group path skips because the bytes vary per slot.
     * @param array<int, int> $bin_unclassified_counts Per-bin slots no
     *     detector matched.
     */
    public function __construct(
        public readonly array $small_bin_histogram,
        public readonly int $large_run_count,
        public readonly int $large_run_bytes,
        public readonly int $live_small_slot_count,
        public readonly int $live_small_slot_bytes,
        public readonly array $periodic_groups,
        public readonly int $walked_chunk_count,
        public readonly bool $partial,
        public readonly array $bin_shape_counts = [],
        public readonly array $bin_unclassified_counts = [],
    ) {
    }

    /**
     * Compact dict for embedding inside the rmem summary section.
     *
     * Stored as a single JSON-encoded summary value (see how
     * {@see \Reli\Inspector\Output\MemoryOutput\BinaryMemoryOutput::serializeSummary}
     * handles non-scalar values). Schema is intentionally flat so a future
     * reader can decode without classloading this DTO.
     *
     * @return array{
     *     histogram: array<int, array{count: int, total_bytes: int}>,
     *     large_run_count: int,
     *     large_run_bytes: int,
     *     live_small_slot_count: int,
     *     live_small_slot_bytes: int,
     *     walked_chunk_count: int,
     *     partial: bool
     * }
     */
    public function toSummaryHistogramArray(): array
    {
        return [
            'histogram' => $this->small_bin_histogram,
            'large_run_count' => $this->large_run_count,
            'large_run_bytes' => $this->large_run_bytes,
            'live_small_slot_count' => $this->live_small_slot_count,
            'live_small_slot_bytes' => $this->live_small_slot_bytes,
            'walked_chunk_count' => $this->walked_chunk_count,
            'partial' => $this->partial,
        ];
    }

    /**
     * @return list<array{
     *     bin_num: int,
     *     bin_size: int,
     *     count: int,
     *     stride: int,
     *     fingerprint: string,
     *     sample_addr: int,
     *     inferred_shape?: string,
     *     inferred_confidence?: string,
     *     effective_confidence?: string,
     *     reachability?: string,
     *     reachable_samples?: int,
     *     sampled_count?: int
     * }>
     */
    /**
     * Per-bin shape tally for the rmem summary section.
     *
     * Schema:
     *   {
     *     "bin": [
     *       {
     *         "bin_num": int,
     *         "shapes": [
     *           {label, count, reachable_count, confidence}, …
     *         ],
     *         "unclassified": int
     *       }, …
     *     ]
     *   }
     *
     * Stored as a single JSON entry keyed `bin_shape_counts` so the
     * comparison provider can decode it without the rmem reader caring
     * about the inner shape.
     *
     * @return array{
     *     bin: list<array{
     *         bin_num: int,
     *         shapes: list<array{
     *             label: string,
     *             count: int,
     *             reachable_count: int,
     *             confidence: string
     *         }>,
     *         unclassified: int
     *     }>
     * }
     */
    public function toSummaryShapeCountsArray(): array
    {
        $bin_rows = [];
        $bin_nums = array_unique(array_merge(
            array_keys($this->bin_shape_counts),
            array_keys($this->bin_unclassified_counts),
        ));
        sort($bin_nums);
        foreach ($bin_nums as $bin_num) {
            $shapes = [];
            foreach ($this->bin_shape_counts[$bin_num] ?? [] as $label => $row) {
                $shapes[] = [
                    'label' => $label,
                    'count' => $row['count'],
                    'reachable_count' => $row['reachable_count'],
                    'confidence' => $row['confidence'],
                ];
            }
            usort(
                $shapes,
                static fn(array $a, array $b): int => $b['count'] <=> $a['count'],
            );
            $bin_rows[] = [
                'bin_num' => $bin_num,
                'shapes' => $shapes,
                'unclassified' => $this->bin_unclassified_counts[$bin_num] ?? 0,
            ];
        }
        return ['bin' => $bin_rows];
    }

    public function toSummaryPeriodicGroupsArray(): array
    {
        $out = [];
        foreach ($this->periodic_groups as $g) {
            $row = [
                'bin_num' => $g->bin_num,
                'bin_size' => $g->bin_size,
                'count' => $g->count,
                'stride' => $g->stride,
                'fingerprint' => $g->fingerprint_hex,
                'sample_addr' => $g->sample_addr,
            ];
            if ($g->detection !== null) {
                $row['inferred_shape'] = $g->detection->label;
                $row['inferred_confidence'] = $g->detection->confidence;
                $effective = $g->effectiveConfidence();
                if ($effective !== null) {
                    $row['effective_confidence'] = $effective;
                }
            }
            if ($g->reachability !== null) {
                $row['reachability'] = $g->reachability;
                $row['reachable_samples'] = $g->reachable_samples;
                $row['sampled_count'] = $g->sampled_count;
            }
            $out[] = $row;
        }
        return $out;
    }
}
