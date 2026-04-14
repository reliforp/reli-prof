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

use Reli\Converter\BinaryTrace\BinaryTraceSample;

/**
 * Aggregates a stream of binary trace samples into the count tables
 * the `rbt:analyze` CLI prints. Pure logic — no I/O, no formatting,
 * no Symfony Console — so it can be unit tested in isolation and
 * reused from other entry points.
 *
 * Pattern conventions: `*_re` parameters are PCRE patterns *with*
 * delimiters already wrapped (see {@see self::wrapPattern()}). Pass
 * `null` to disable the corresponding filter / aggregation.
 *
 *   - hide_re   : drop frames matching this pattern from each stack
 *                 *before* counting. Used to prune frame noise that
 *                 would otherwise dominate the rankings.
 *   - match_re  : keep only samples whose stack contains at least
 *                 one matching frame; everything else is skipped
 *                 entirely (no self/total/tail contribution).
 *   - callers_re: for each sample, locate the leafmost matching
 *                 frame and attribute one count to its immediate
 *                 caller (or `<root>` if it's already the root).
 *   - callees_re: symmetric to callers_re — find the rootmost
 *                 matching frame and attribute one count to its
 *                 immediate callee (or `<leaf>` for an actual leaf).
 *
 * `last_count > 0` keeps a ring buffer of the most recent samples'
 * formatted frame stacks (with the same hide/no-line rules applied)
 * so the CLI can render a `--last N` tail without re-processing.
 */
final class TraceAggregator
{
    /**
     * @param non-empty-string|null $hide_re
     * @param non-empty-string|null $match_re
     * @param non-empty-string|null $callers_re
     * @param non-empty-string|null $callees_re
     */
    public function __construct(
        public readonly bool $no_line = false,
        public readonly bool $with_opcode = false,
        public readonly ?string $hide_re = null,
        public readonly ?string $match_re = null,
        public readonly ?string $callers_re = null,
        public readonly ?string $callees_re = null,
        public readonly int $last_count = 0,
    ) {
    }

    /**
     * Wrap a user-supplied PCRE pattern in `#...#` delimiters and
     * escape any embedded `#`. Returns a non-empty regex string, or
     * null when the input was missing or empty so callers can skip
     * the preg_match step entirely.
     *
     * Lives here (not in the CLI command) because the regex shape
     * is part of the aggregator's contract — every `*_re` field
     * passed to the constructor is expected to come from this
     * helper or be null.
     *
     * @return non-empty-string|null
     */
    public static function wrapPattern(?string $pattern): ?string
    {
        if ($pattern === null || $pattern === '') {
            return null;
        }
        return '#' . str_replace('#', '\\#', $pattern) . '#';
    }

    /**
     * @param iterable<BinaryTraceSample> $samples
     */
    public function aggregate(iterable $samples): TraceAggregationResult
    {
        /** @var array<string, int> $self_counts */
        $self_counts = [];
        /** @var array<string, int> $total_counts */
        $total_counts = [];
        /** @var array<string, int> $caller_counts */
        $caller_counts = [];
        /** @var array<string, int> $callee_counts */
        $callee_counts = [];

        $sample_count = 0;
        $matched_samples = 0;

        /** @var list<list<string>> $tail_buffer */
        $tail_buffer = [];

        foreach ($samples as $sample) {
            $sample_count++;

            $keys = [];
            foreach ($sample->trace->call_frames as $frame) {
                $key = $this->no_line
                    ? $frame->function_name
                    : $frame->function_name . ' ' . $frame->file_name . ':' . $frame->lineno;
                if ($this->with_opcode && $frame->opcode_name !== null) {
                    $key .= ' [' . $frame->opcode_name . ']';
                }
                $keys[] = $key;
            }

            $hide_re = $this->hide_re;
            if ($hide_re !== null) {
                $keys = array_values(
                    array_filter($keys, static fn (string $k): bool => preg_match($hide_re, $k) !== 1),
                );
            }
            if ($keys === []) {
                continue;
            }

            $match_re = $this->match_re;
            if ($match_re !== null) {
                $hit = false;
                foreach ($keys as $k) {
                    if (preg_match($match_re, $k) === 1) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    continue;
                }
            }

            $matched_samples++;

            // self-time: leaf frame (call_frames[0] is innermost)
            $leaf = $keys[0];
            $self_counts[$leaf] = ($self_counts[$leaf] ?? 0) + 1;

            // inclusive: each frame on the stack, deduped per sample so
            // recursive calls only count once.
            $seen = [];
            foreach ($keys as $k) {
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $total_counts[$k] = ($total_counts[$k] ?? 0) + 1;
            }

            $callers_re = $this->callers_re;
            if ($callers_re !== null) {
                // Find topmost (closest to leaf) frame matching pattern,
                // attribute one count to its immediate caller.
                $n = count($keys);
                for ($i = 0; $i < $n; $i++) {
                    if (preg_match($callers_re, $keys[$i]) === 1) {
                        $caller = $keys[$i + 1] ?? '<root>';
                        $caller_counts[$caller] = ($caller_counts[$caller] ?? 0) + 1;
                        break;
                    }
                }
            }

            $callees_re = $this->callees_re;
            if ($callees_re !== null) {
                // Find bottommost (closest to root) matching frame, attribute
                // one count to its immediate callee.
                for ($i = count($keys) - 1; $i >= 0; $i--) {
                    if (preg_match($callees_re, $keys[$i]) === 1) {
                        $callee = $i > 0 ? $keys[$i - 1] : '<leaf>';
                        $callee_counts[$callee] = ($callee_counts[$callee] ?? 0) + 1;
                        break;
                    }
                }
            }

            if ($this->last_count > 0) {
                $tail_buffer[] = $keys;
                if (count($tail_buffer) > $this->last_count) {
                    array_shift($tail_buffer);
                }
            }
        }

        return new TraceAggregationResult(
            sample_count: $sample_count,
            matched_samples: $matched_samples,
            self_counts: $self_counts,
            total_counts: $total_counts,
            caller_counts: $caller_counts,
            callee_counts: $callee_counts,
            tail: $tail_buffer,
        );
    }
}
