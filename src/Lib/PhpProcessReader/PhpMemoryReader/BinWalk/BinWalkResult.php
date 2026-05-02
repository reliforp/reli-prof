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
     *     inferred_confidence?: string
     * }>
     */
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
            }
            $out[] = $row;
        }
        return $out;
    }
}
