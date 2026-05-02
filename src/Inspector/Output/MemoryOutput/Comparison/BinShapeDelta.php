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

namespace Reli\Inspector\Output\MemoryOutput\Comparison;

/**
 * One row of the per-(bin, shape) count delta — pairs with
 * {@see \Reli\Inspector\Output\MemoryOutput\Report\Pass\BinShapePass}'s
 * per-slot tally.
 *
 * The leak signal that came out of the issue #88 validation was
 * "+2,000 Bucket(IS_STRING) in bin[3]" — content-varying shapes that
 * the periodic-group path skipped because every Bucket carried distinct
 * hash + key bytes. This delta surfaces them directly. Reachability
 * deltas are tracked separately (`reachable_count_delta`) because the
 * orphan-count delta is the actionable bit ("orphans grew") while
 * reachable-count growth is usually normal.
 */
final class BinShapeDelta
{
    public function __construct(
        public readonly int $bin_num,
        public readonly int $bin_size,
        public readonly string $shape,
        public readonly int $baseline_count,
        public readonly int $target_count,
        public readonly int $count_delta,
        public readonly int $baseline_reachable,
        public readonly int $target_reachable,
        public readonly int $reachable_count_delta,
        public readonly int $orphan_count_delta,
        public readonly string $confidence,
    ) {
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'bin_num' => $this->bin_num,
            'bin_size' => $this->bin_size,
            'shape' => $this->shape,
            'baseline_count' => $this->baseline_count,
            'target_count' => $this->target_count,
            'count_delta' => $this->count_delta,
            'baseline_reachable' => $this->baseline_reachable,
            'target_reachable' => $this->target_reachable,
            'reachable_count_delta' => $this->reachable_count_delta,
            'orphan_count_delta' => $this->orphan_count_delta,
            'confidence' => $this->confidence,
        ];
    }
}
