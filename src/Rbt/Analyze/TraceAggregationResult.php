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

namespace Reli\Rbt\Analyze;

/**
 * Aggregated counts produced by {@see TraceAggregator::aggregate()}.
 *
 * Self-time counts the leaf frame of each sample once. Total-time counts
 * every frame on the stack once per sample (deduped so recursive calls
 * don't double-count). Caller / callee counts are populated only when
 * the corresponding regex was supplied to the aggregator. The tail
 * buffer keeps the most recent N samples' formatted frame stacks for
 * `--last`-style live tailing.
 */
final class TraceAggregationResult
{
    /**
     * @param array<string, int> $self_counts
     * @param array<string, int> $total_counts
     * @param array<string, int> $caller_counts
     * @param array<string, int> $callee_counts
     * @param list<array{frames: list<string>, annotations: array<string, string>|null}> $tail
     */
    public function __construct(
        public readonly int $sample_count,
        public readonly int $matched_samples,
        public readonly array $self_counts,
        public readonly array $total_counts,
        public readonly array $caller_counts,
        public readonly array $callee_counts,
        public readonly array $tail,
    ) {
    }
}
