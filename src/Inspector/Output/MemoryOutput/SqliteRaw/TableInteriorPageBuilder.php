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

namespace Reli\Inspector\Output\MemoryOutput\SqliteRaw;

/**
 * Build a multi-level interior b-tree above an already-written set
 * of table-leaf pages.
 *
 * Input is a sorted-by-rowid index `[(max_rowid_in_leaf, leaf_pgno), ...]`
 * (which {@see LeafPageBuilder::finalize} produces directly). Output
 * is a list of `(pgno, page_bytes)` interior pages and the pgno of
 * the resulting root. Caller is expected to have reserved a
 * contiguous page range for interior pages and supplied its first
 * pgno; the builder allocates upward greedily.
 *
 * Interior cell format for table b-trees (type 0x05):
 *   - 4 bytes BE: left-child pgno
 *   - varint:     largest rowid in the left subtree
 * The rightmost child has no cell — it lives in the page header at
 * offset 8 (`right_most_pgno`). For N children there are N-1 cells.
 *
 * Reference: https://www.sqlite.org/fileformat.html#b_tree_pages
 *
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArgument
 */
final class TableInteriorPageBuilder
{
    public const int PAGE_SIZE = 4096;
    private const int HEADER_SIZE = 12;
    private const int TABLE_INTERIOR_TYPE = 0x05;

    /**
     * Build the interior tree. If `$leaf_index` has only one entry
     * the result is empty and the single leaf is the root.
     *
     * `$root_pgno_hint` lets the caller place the final root at a
     * pre-known pgno — typically the original `sqlite_master.rootpage`
     * for the table — so no `sqlite_master` UPDATE is needed and
     * SQLite's `integrity_check` won't flag the original empty
     * rootpage as an orphan. When the hint is supplied the root
     * page bytes are emitted at that pgno and no pgno from the
     * `[first_pgno, …]` range is consumed for the root, so the
     * caller's reservation can be exactly `(returned pages count)`.
     *
     * @param list<array{int, int}> $leaf_index ordered list of
     *     (max_rowid, leaf_pgno). max_rowid must be strictly
     *     ascending across the list.
     * @param int $first_pgno first pgno reserved for interior pages.
     * @return array{list<array{int, string}>, int} [pages, root_pgno]
     */
    public static function build(
        array $leaf_index,
        int $first_pgno,
        ?int $root_pgno_hint = null,
    ): array {
        $n = count($leaf_index);
        if ($n === 0) {
            throw new \InvalidArgumentException('leaf_index must be non-empty');
        }
        if ($n === 1) {
            return [[], $leaf_index[0][1]];
        }

        /** @var list<array{int, string}> $pages */
        $pages = [];
        $next_pgno = $first_pgno;
        /** @var list<array{int, int}> $current_level */
        $current_level = $leaf_index;

        while (count($current_level) > 1) {
            $level_n = count($current_level);
            // Pre-scan to know if this iteration produces the root
            // (i.e. fits in a single page). When it does and a hint
            // was supplied, the root takes the hint pgno instead of
            // consuming one from the linear range.
            [$first_group, $first_consumed] = self::greedyGroup($current_level, 0);
            $is_final_level = ($first_consumed === $level_n);

            /** @var list<array{int, int}> $next_level */
            $next_level = [];
            $i = 0;
            while ($i < $level_n) {
                if ($i === 0) {
                    $group = $first_group;
                    $consumed = $first_consumed;
                } else {
                    [$group, $consumed] = self::greedyGroup($current_level, $i);
                }
                $i += $consumed;
                $page_bytes = self::assembleInteriorPage($group);
                $pgno = ($is_final_level && $root_pgno_hint !== null)
                    ? $root_pgno_hint
                    : $next_pgno++;
                $pages[] = [$pgno, $page_bytes];
                if ($group === []) {
                    throw new \LogicException('greedyGroup returned an empty group');
                }
                $last = $group[array_key_last($group)];
                $next_level[] = [$last[0], $pgno];
            }
            $current_level = $next_level;
        }
        if (!isset($current_level[0])) {
            throw new \LogicException('current_level became empty before producing a root');
        }
        return [$pages, $current_level[0][1]];
    }

    /**
     * Pack as many entries into one interior page as fit, starting at
     * index `$start`. The last entry of the returned group becomes
     * the rightmost child of that interior page (no divider cell);
     * the others are left-children that each cost a cell + pointer.
     *
     * Includes a lookahead: if greedy would leave exactly one entry
     * for the next call (which could not form a valid ≥2-child
     * interior page), one entry is given back so the next group is
     * guaranteed at least 2.
     *
     * @param list<array{int, int}> $level
     * @return array{list<array{int, int}>, int}
     */
    private static function greedyGroup(array $level, int $start): array
    {
        $level_n = count($level);
        $group = [];
        $left_child_bytes = 0;
        $left_child_count = 0;
        $available = self::PAGE_SIZE - self::HEADER_SIZE;
        for ($i = $start; $i < $level_n; $i++) {
            $entry = $level[$i];
            if (count($group) === 0) {
                $group[] = $entry;
                continue;
            }
            // Adding $entry promotes the previous last to left-child
            // (gains a cell + pointer) and makes $entry the new
            // rightmost (free).
            $prev = $group[count($group) - 1];
            $prev_cell_size = 4 + strlen(Format::writeVarint($prev[0]));
            $needed = $left_child_bytes + $prev_cell_size + 2 * ($left_child_count + 1);
            if ($needed > $available) {
                break;
            }
            $left_child_bytes += $prev_cell_size;
            $left_child_count++;
            $group[] = $entry;
        }
        $remaining = $level_n - $start - count($group);
        if ($remaining === 1 && count($group) >= 3) {
            array_pop($group);
        }
        return [$group, count($group)];
    }

    /**
     * Assemble one interior page's bytes from a group of children.
     * Last entry in `$group` is the rightmost child (no cell, lives
     * in header).
     *
     * @param list<array{int, int}> $group
     */
    public static function assembleInteriorPage(array $group): string
    {
        $n_children = count($group);
        if ($n_children < 2) {
            throw new \InvalidArgumentException('interior page needs at least 2 children');
        }
        $rightmost = $group[$n_children - 1];

        $cells = [];
        $cells_bytes = 0;
        for ($i = 0; $i < $n_children - 1; $i++) {
            [$max_rowid, $pgno] = $group[$i];
            $cell = pack('N', $pgno) . Format::writeVarint($max_rowid);
            $cells[] = $cell;
            $cells_bytes += strlen($cell);
        }
        $n_cells = count($cells);

        $content_start = self::PAGE_SIZE - $cells_bytes;
        $pointer_array_end = self::HEADER_SIZE + 2 * $n_cells;
        if ($content_start < $pointer_array_end) {
            throw new \RuntimeException('interior cells exceed page capacity');
        }

        $offsets = [];
        $cursor = self::PAGE_SIZE;
        foreach ($cells as $cell) {
            $cursor -= strlen($cell);
            $offsets[] = $cursor;
        }

        // PAGE_SIZE = 4096 → content_start fits in u16 verbatim.
        // SQLite's "0 means 65536" encoding for the cell-content-start
        // field only matters when page_size = 65536, which we don't
        // support.
        $header = chr(self::TABLE_INTERIOR_TYPE)
            . "\x00\x00"
            . pack('n', $n_cells)
            . pack('n', $content_start)
            . "\x00"
            . pack('N', $rightmost[1]);
        $pointer_array = pack('n*', ...$offsets);
        $free_bytes = $content_start - $pointer_array_end;
        return $header
            . $pointer_array
            . ($free_bytes > 0 ? str_repeat("\x00", $free_bytes) : '')
            . implode('', array_reverse($cells));
    }
}
