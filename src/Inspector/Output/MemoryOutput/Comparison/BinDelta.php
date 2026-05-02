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
 * Per-bin live-allocation delta between two snapshots.
 *
 * Materialised by {@see ComparisonGenerator::compareBinHistogram} from the
 * `bin_walk` summary entry the analyze path writes (see
 * {@see \Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\ZendMmBinWalker}).
 *
 * The big payoff for the user is "16,041 new 32-byte slots between
 * baseline and target" landing as a single row instead of having to diff
 * two `memory:dump:inspect` runs by hand.
 */
final class BinDelta
{
    public function __construct(
        public readonly int $bin_num,
        public readonly int $bin_size,
        public readonly int $baseline_count,
        public readonly int $target_count,
        public readonly int $baseline_bytes,
        public readonly int $target_bytes,
        public readonly int $count_delta,
        public readonly int $bytes_delta,
    ) {
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'bin_num' => $this->bin_num,
            'bin_size' => $this->bin_size,
            'baseline_count' => $this->baseline_count,
            'target_count' => $this->target_count,
            'baseline_bytes' => $this->baseline_bytes,
            'target_bytes' => $this->target_bytes,
            'count_delta' => $this->count_delta,
            'bytes_delta' => $this->bytes_delta,
        ];
    }
}
