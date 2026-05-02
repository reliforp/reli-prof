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
 * Decode the JSON-serialised `bin_walk` summary entry written by
 * {@see \Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\BinWalkResult::toSummaryHistogramArray}.
 *
 * Lives here (and not in the BinWalk namespace) because it is purely a
 * comparison-layer concern — analyze writes the raw payload, and only
 * the comparison data providers need to round-trip it back into a typed
 * structure.
 */
final class BinHistogramSnapshot
{
    /**
     * @return array{
     *     histogram: array<int, array{count: int, total_bytes: int}>,
     *     large_run_count?: int,
     *     large_run_bytes?: int,
     *     live_small_slot_count?: int,
     *     live_small_slot_bytes?: int,
     *     walked_chunk_count?: int,
     *     partial?: bool
     * }|null
     * @psalm-suppress MixedAssignment, MixedArgument, MixedArrayAccess
     */
    public static function decode(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['histogram']) || !is_array($decoded['histogram'])) {
            return null;
        }

        /** @var array<int, array{count: int, total_bytes: int}> $histogram */
        $histogram = [];
        foreach ($decoded['histogram'] as $bin_num => $row) {
            if (!is_array($row)) {
                continue;
            }
            $histogram[(int)$bin_num] = [
                'count' => (int)($row['count'] ?? 0),
                'total_bytes' => (int)($row['total_bytes'] ?? 0),
            ];
        }
        ksort($histogram);

        $out = ['histogram' => $histogram];
        foreach (
            [
                'large_run_count',
                'large_run_bytes',
                'live_small_slot_count',
                'live_small_slot_bytes',
                'walked_chunk_count',
            ] as $key
        ) {
            if (isset($decoded[$key]) && is_scalar($decoded[$key])) {
                $out[$key] = (int)$decoded[$key];
            }
        }
        if (isset($decoded['partial'])) {
            $out['partial'] = (bool)$decoded['partial'];
        }
        /**
         * @var array{
         *     histogram: array<int, array{count: int, total_bytes: int}>,
         *     large_run_count?: int,
         *     large_run_bytes?: int,
         *     live_small_slot_count?: int,
         *     live_small_slot_bytes?: int,
         *     walked_chunk_count?: int,
         *     partial?: bool
         * } $out
         */
        return $out;
    }
}
