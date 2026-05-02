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
 * Decode the JSON-serialised `bin_shape_counts` summary entry written by
 * {@see \Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\BinWalkResult::toSummaryShapeCountsArray}.
 *
 * Returns a flat map keyed by `bin_num => label => row` so the comparison
 * generator can do a straight outer-join on (bin, shape) without having
 * to walk a list-of-lists shape twice.
 */
final class BinShapeCountsSnapshot
{
    /**
     * @return array<int, array<string, array{
     *     count: int,
     *     reachable_count: int,
     *     confidence: string
     * }>>|null
     * @psalm-suppress MixedAssignment, MixedArgument, MixedArrayAccess
     */
    public static function decode(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['bin']) || !is_array($decoded['bin'])) {
            return null;
        }
        $out = [];
        foreach ($decoded['bin'] as $bin_row) {
            if (!is_array($bin_row) || !isset($bin_row['bin_num'], $bin_row['shapes'])) {
                continue;
            }
            $bin_num = (int)$bin_row['bin_num'];
            $shapes = is_array($bin_row['shapes']) ? $bin_row['shapes'] : [];
            $by_label = [];
            foreach ($shapes as $shape) {
                if (!is_array($shape) || !isset($shape['label'])) {
                    continue;
                }
                $label = (string)$shape['label'];
                $by_label[$label] = [
                    'count' => (int)($shape['count'] ?? 0),
                    'reachable_count' => (int)($shape['reachable_count'] ?? 0),
                    'confidence' => (string)($shape['confidence'] ?? 'medium'),
                ];
            }
            $out[$bin_num] = $by_label;
        }
        return $out;
    }
}
