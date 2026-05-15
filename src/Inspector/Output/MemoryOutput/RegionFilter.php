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

namespace Reli\Inspector\Output\MemoryOutput;

/**
 * Single source of truth for the size-attribution region policy.
 *
 * Memory locations carry a `region` tag assigned by
 * {@see \Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries::classifyRegion}.
 * Locations whose region is `'outside'` (the catch-all the classifier
 * returns when the address sits in none of the tracked allocator
 * regions) are typically dangling/persistent allocations whose `size`
 * is read from arbitrary bytes — for example, a stale
 * `php_stream_memory_data->data` walked by
 * {@see \Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\Job\EmitResourceJob::collectMemoryStreamData}
 * is dereferenced as a `zend_string` whose `len` field reads as a
 * multi-exabyte garbage value, and `getSize() = len + 24` follows.
 *
 * If those rows are summed into per-node sizes:
 *   - on the FFI CSR substrate, the running total overflows
 *     `PHP_INT_MAX`, PHP promotes the addition to `float`, and
 *     assignment to `int $nodeSizesSum` raises
 *     `Cannot assign float to property`;
 *   - on the PHP-array substrate, the same overflow goes silent and
 *     surfaces as broken multi-exabyte rows in ChokePoint and
 *     Top Strings, while Type Breakdown — which already filters by
 *     region — stays clean and the asymmetry confuses the report.
 *
 * Every surface that aggregates `size` (or surfaces raw `size` for
 * ranking) must therefore apply the same filter:
 *   - SQL paths use {@see self::sqlPredicate()};
 *   - PHP paths use {@see self::isRelevant()}.
 *
 * `region IS NULL` is preserved on the SQL side and `null` returns
 * `true` from {@see self::isRelevant()} so legacy rows captured before
 * region tagging continue to flow through unchanged.
 */
final class RegionFilter
{
    /**
     * Regions whose locations participate in size aggregation.
     */
    public const RELEVANT_REGIONS = [
        'zend_mm_heap',
        'zend_mm_huge',
        'vm_stack',
        'compiler_arena',
    ];

    /**
     * True for regions that count toward per-node size sums (and for
     * NULL — see class docblock for the legacy-row rationale).
     */
    public static function isRelevant(?string $region): bool
    {
        return $region === null
            || in_array($region, self::RELEVANT_REGIONS, true);
    }

    /**
     * SQL fragment matching {@see self::isRelevant()} for the given
     * column. Drop into a `WHERE` / `AND` clause as-is; the returned
     * string is parenthesised and contains no parameter markers (the
     * region list is a closed set of internal identifiers, not user
     * input).
     */
    public static function sqlPredicate(string $column): string
    {
        $quoted = array_map(
            static fn (string $r): string => "'" . $r . "'",
            self::RELEVANT_REGIONS,
        );
        $list = implode(', ', $quoted);
        return "({$column} IN ({$list}) OR {$column} IS NULL)";
    }
}
