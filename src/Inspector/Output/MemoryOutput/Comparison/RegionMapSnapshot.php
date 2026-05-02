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
 * Decode the JSON-serialised `region_map` summary entry written by
 * {@see \Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionMap::toSummaryArray}.
 *
 * Companion to {@see BinHistogramSnapshot} — the comparison data
 * providers JSON-encode non-scalar summary values, so a typed
 * round-trip helper keeps the consumer side honest.
 */
final class RegionMapSnapshot
{
    /**
     * @return list<array{kind: string, address: int, size: int}>|null
     * @psalm-suppress MixedAssignment, MixedArgument, MixedArrayAccess
     */
    public static function decode(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }
        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (
                !isset($row['kind'], $row['address'], $row['size'])
                || !is_string($row['kind'])
            ) {
                continue;
            }
            $out[] = [
                'kind' => $row['kind'],
                'address' => (int)$row['address'],
                'size' => (int)$row['size'],
            ];
        }
        return $out;
    }
}
