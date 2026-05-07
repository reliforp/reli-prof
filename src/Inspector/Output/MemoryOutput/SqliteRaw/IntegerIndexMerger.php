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
 * K-way merger + index b-tree builder for indexes with the column
 * shape (run_id INTEGER, key INTEGER, rowid INTEGER).
 *
 * Inputs are SortRun files dumped by workers, one per shard, each
 * already sorted by (key, rowid) AND already carrying the
 * fully-encoded SQLite leaf cell for its row (since v2 of the
 * sort-run format). The merger streams them through a
 * heap-of-iterators and writes the cells verbatim into a freshly
 * assembled b-tree at the empty index rootpage SQLite created at
 * CREATE INDEX time. Per-row record encoding now happens once per
 * row in the worker that produced the row, not per row in main —
 * the encoding work parallelises across workers and the merge in
 * main is heap-pull + memcpy.
 *
 * Index leaf cell shape:
 *   varint payload_size
 *   payload (record format with three columns: run_id, key, rowid)
 *
 * Index interior cell shape:
 *   u32 left_child_pgno
 *   varint payload_size
 *   payload (record format, divider key + rowid of the right-most
 *           entry in the left subtree)
 *
 * The merger refuses overflow (cell payload bigger than the inline
 * threshold). For 3-int payloads the encoded record is ≤ 28 bytes,
 * comfortably below the 4061-byte inline limit, so overflow doesn't
 * happen in practice — the SortRunWriter's u8 cell_len field also
 * caps any record at 255 bytes upstream.
 *
 * @psalm-suppress MixedArgument
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedReturnStatement
 * @psalm-suppress MixedArrayAccess
 * @psalm-suppress RedundantCast
 * @psalm-suppress MixedOperand
 * @psalm-suppress MixedArgumentTypeCoercion
 * @psalm-suppress MixedArrayAssignment
 * @psalm-suppress MixedReturnTypeCoercion
 * @psalm-suppress PossiblyInvalidArrayAccess
 * @psalm-suppress InvalidArrayOffset
 * @psalm-suppress MissingTemplateParam
 */
final class IntegerIndexMerger
{
    /**
     * @param Writer $main target SQLite writer
     * @param list<SortRunReader> $sort_runs one per shard, already sorted (key, rowid)
     */
    public function __construct(
        private Writer $main,
        private array $sort_runs,
    ) {
    }

    /**
     * Build the index and write its root at `$main_index_rootpage`.
     */
    public function merge(int $main_index_rootpage): void
    {
        ['leaves' => $leaves_with_dividers, 'rightmost_pgno' => $rightmost_pgno] =
            $this->extractLeavesForRange(null, null, true);
        $this->assembleInteriorTree(
            $leaves_with_dividers,
            $rightmost_pgno,
            $main_index_rootpage,
        );
    }

    /**
     * Build leaves for the (key, rowid) entries that fall inside
     * `[$low_inclusive, $high_exclusive)`, using the existing k-way
     * merge over the supplied sort runs and the same SQLite leaf
     * encoding that {@see merge} uses. Used for the parallel-
     * partition path: each partition worker invokes this with its
     * own key window and emits its leaves to the shared writer.
     *
     * `$is_global_rightmost` controls how the partition's last leaf
     * is treated:
     *   - true : the last leaf is the global rightmost, no
     *            promotion (returned via `rightmost_pgno`).
     *   - false: the last leaf gets its last cell promoted as the
     *            divider into the next partition (added to the
     *            `leaves` list, `rightmost_pgno` is null).
     *
     * @return array{leaves: list<array{pgno: int, divider_payload: string}>, rightmost_pgno: ?int}
     */
    public function extractLeavesForRange(
        ?int $low_inclusive,
        ?int $high_exclusive,
        bool $is_global_rightmost,
    ): array {
        $page_size = $this->main->page_size;
        $inline_max = $page_size - 35;

        // Build leaf pages by streaming the k-way merge into a
        // running leaf buffer. SQLite's index b-tree convention
        // *promotes* the last cell of each non-rightmost leaf into
        // the interior page as the divider key; that promoted entry
        // lives only in the interior, not in the leaf below. So
        // when we flush a non-final leaf we hold back its last
        // cell and turn it into the interior cell.
        /** @var list<array{pgno: int, divider_payload: string}> $leaves_with_dividers */
        $leaves_with_dividers = [];
        /** @var list<string> $cells */
        $cells = [];
        /** @var list<string> $payloads */
        $payloads = [];
        $cells_size = 0;
        // Most-recent flushed leaf — kept around so the tail block
        // can re-absorb the previously-promoted cell + the lone tail
        // cell back into it when a non-rightmost partition's tail
        // ends up with exactly one cell. See the bug-B note in the
        // tail block below.
        /** @var list<string>|null $prev_full_cells */
        $prev_full_cells = null;
        $prev_pgno = 0;

        foreach ($this->mergeStream() as [$key, $_rowid, $cell]) {
            if ($low_inclusive !== null && $key < $low_inclusive) {
                continue;
            }
            if ($high_exclusive !== null && $key >= $high_exclusive) {
                // Sort runs are sorted by (key, rowid), so once we
                // pass `$high_exclusive` no later entry can fall
                // back into our window.
                break;
            }
            // $cell is the worker-encoded leaf cell:
            //   varint(payload_size) + payload(record bytes).
            // Strip the size varint to get the payload bytes — we
            // need the raw payload as the divider when promoting,
            // and we need the cell as a unit when packing leaves.
            $cell_len = strlen($cell);
            // Payload size in our records is < 128 so the size
            // varint is one byte; we still bail to the generic
            // varint reader for any record that lies in the
            // multi-byte range, just in case.
            $size_byte = ord($cell[0]);
            if ($size_byte < 0x80) {
                $payload_size = $size_byte;
                $sz_len = 1;
            } else {
                [$payload_size, $sz_len] = Format::readVarint($cell, 0);
            }
            if ($payload_size > $inline_max) {
                throw new OverflowNotSupportedException(
                    'integer index payload exceeds inline threshold ('
                    . $payload_size . ' > ' . $inline_max . ')'
                );
            }
            $payload = substr($cell, $sz_len, $payload_size);

            $needed = 8 + (count($cells) + 1) * 2 + $cells_size + $cell_len;
            if ($needed > $page_size && count($cells) > 0) {
                // Promote the last cell as the divider; emit the
                // remaining cells (still ≥ 1 because we required
                // count > 0 above) as the leaf.
                $promoted_payload = $payloads[count($payloads) - 1];
                $promoted_cell = $cells[count($cells) - 1];
                $promoted_cell_size = strlen($promoted_cell);
                // Snapshot the full cell list (still including the
                // promoted cell) so the tail block can put it back
                // into the leaf if it ends up with a single tail
                // cell that would otherwise duplicate as both leaf
                // entry and interior divider.
                $full_cells_before_pop = $cells;
                $cells_size -= $promoted_cell_size;
                array_pop($cells);
                array_pop($payloads);
                if ($cells === []) {
                    // The single-cell-leaf case: SQLite would still
                    // require at least one entry per leaf. Fall
                    // back to keeping it (no promotion this time)
                    // and starting the next leaf with $cell. The
                    // next promotion happens normally.
                    $cells[] = $promoted_cell;
                    $payloads[] = $promoted_payload;
                    $cells_size += $promoted_cell_size;
                    $page = $this->buildLeafPage($cells, $page_size);
                    $pgno = $this->main->appendPage($page);
                    // Use the only cell we have as the divider.
                    $leaves_with_dividers[] = [
                        'pgno' => $pgno,
                        'divider_payload' => $promoted_payload,
                    ];
                } else {
                    $page = $this->buildLeafPage($cells, $page_size);
                    $pgno = $this->main->appendPage($page);
                    $leaves_with_dividers[] = [
                        'pgno' => $pgno,
                        'divider_payload' => $promoted_payload,
                    ];
                }
                $prev_full_cells = $full_cells_before_pop;
                $prev_pgno = $pgno;
                $cells = [];
                $payloads = [];
                $cells_size = 0;
            }
            $cells[] = $cell;
            $payloads[] = $payload;
            $cells_size += $cell_len;
        }

        // Final partial buffer. If this is the global rightmost
        // partition the last leaf carries no divider (its content
        // is the rightmost-child pointer in the parent interior
        // page); otherwise we promote its last cell as the divider
        // into the next partition.
        $rightmost_pgno = null;
        if (count($cells) > 0) {
            if ($is_global_rightmost) {
                $page = $this->buildLeafPage($cells, $page_size);
                $rightmost_pgno = $this->main->appendPage($page);
            } else {
                $promoted_payload = $payloads[count($payloads) - 1];
                $promoted_cell = $cells[count($cells) - 1];
                $promoted_cell_size = strlen($promoted_cell);
                array_pop($cells);
                array_pop($payloads);
                if ($cells === [] && $prev_full_cells !== null) {
                    // Bug B: when a non-rightmost partition's
                    // entry count is (272 * k + 1) for k ≥ 1 — i.e.
                    // a full leaf cycle plus one orphan cell — the
                    // tail is exactly one cell. Promoting it as
                    // the divider and also keeping it in the leaf
                    // (the old fallback) writes the same cell twice
                    // into the b-tree (once in the leaf, once in
                    // the parent interior page) and trips
                    // PRAGMA integrity_check with "wrong # of
                    // entries in index". The cell is also visible
                    // to a full-table scan as an extra row whose
                    // rowid duplicates an existing one — silent
                    // table corruption everything except
                    // integrity_check happily ignores.
                    //
                    // Fix: re-absorb the lone cell into the
                    // previously-flushed leaf. The old leaf had
                    // 271 cells in the page + 1 promoted divider
                    // = 272 entries; we re-build it with all 272
                    // cells in the page, overwrite the leaf at the
                    // same pgno, and let the lone tail cell take
                    // over as the new divider. Total entries are
                    // unchanged (271 + 1 → 272 + 1, but the
                    // previously-promoted cell got moved from
                    // interior back into the leaf so the count is
                    // identical).
                    $merged_page = $this->buildLeafPage(
                        $prev_full_cells,
                        $page_size,
                    );
                    $this->main->writePage($prev_pgno, $merged_page);
                    $last_idx = count($leaves_with_dividers) - 1;
                    \assert($last_idx >= 0); // $prev_pgno !== 0 implies the
                    //                          loop already pushed at least
                    //                          one leaf.
                    $leaves_with_dividers[$last_idx]['divider_payload']
                        = $promoted_payload;
                } else {
                    if ($cells === []) {
                        // Single-cell-leaf with no previous leaf to
                        // merge into: the partition has exactly one
                        // entry. Keep the cell in the leaf and use
                        // it as the divider too — produces the same
                        // +1 inflation as the legacy fallback, but
                        // the partition-with-1-entry corner is rare
                        // in practice (would need the boundary
                        // sampler to bracket a 1-key range).
                        $cells[] = $promoted_cell;
                        $payloads[] = $promoted_payload;
                    }
                    $page = $this->buildLeafPage($cells, $page_size);
                    $pgno = $this->main->appendPage($page);
                    $leaves_with_dividers[] = [
                        'pgno' => $pgno,
                        'divider_payload' => $promoted_payload,
                    ];
                }
            }
        }

        return ['leaves' => $leaves_with_dividers, 'rightmost_pgno' => $rightmost_pgno];
    }

    /**
     * Build the interior tree on top of an already-laid-out leaf
     * level, write the resulting root at `$main_index_rootpage`.
     *
     * `$leaves_with_dividers` is the ordered list of non-rightmost
     * leaves with their promoted divider payloads. `$rightmost_pgno`
     * is the global rightmost leaf (or null if the entire range
     * is empty).
     *
     * @param list<array{pgno: int, divider_payload: string}> $leaves_with_dividers
     */
    public function assembleInteriorTree(
        array $leaves_with_dividers,
        ?int $rightmost_pgno,
        int $main_index_rootpage,
    ): void {
        $page_size = $this->main->page_size;

        if ($leaves_with_dividers === []) {
            if ($rightmost_pgno === null) {
                // No data — leave the empty leaf in place.
                return;
            }
            // Only one leaf; copy it over the rootpage.
            $this->main->writePage(
                $main_index_rootpage,
                $this->main->readPage($rightmost_pgno),
            );
            return;
        }

        // Multi-level interior tree. Build the first level above
        // the leaves, then recurse upward.
        /** @var list<array{pgno: int, divider_payload: string}> $level */
        $level = $leaves_with_dividers;
        // The very last child of this level is the rightmost leaf.
        // If a non-rightmost-only partition trail returned no
        // explicit rightmost, fall back to treating the last entry
        // in $leaves_with_dividers as it (its divider becomes
        // unused at the top of the tree).
        if ($rightmost_pgno === null) {
            $last = array_pop($level);
            \assert($last !== null);
            $rightmost = $last['pgno'];
        } else {
            $rightmost = $rightmost_pgno;
        }

        $max_children = $this->maxInteriorCellCount($page_size);
        while (count($level) >= $max_children) {
            /** @var list<array{pgno: int, divider_payload: string}> $next */
            $next = [];
            $remaining_rightmost = $rightmost;
            for ($i = 0, $n = count($level); $i < $n; $i += $max_children) {
                $chunk = array_slice($level, $i, $max_children);
                $is_last_chunk = ($i + $max_children >= $n);
                if ($is_last_chunk) {
                    $page = $this->buildInteriorPage($chunk, $remaining_rightmost, $page_size);
                    $rightmost = $this->main->appendPage($page);
                    break; // no divider needed for the last chunk; it's the rightmost of the next level
                }
                // The last entry of this chunk is *removed* from the
                // chunk before it gets passed to buildInteriorPage:
                // its pgno becomes this internal page's
                // rightmost-child pointer (lives in the page header,
                // not as a cell), and its divider becomes the divider
                // promoted up to the parent level. Leaving it in
                // $chunk would write it as both cell N-1 and as the
                // rightmost pointer, so its pgno gets referenced
                // twice on the page — exactly the corruption shape
                // PRAGMA integrity_check reports as
                // "page X cell N-1: 2nd reference to page Y" and
                // "wrong # of entries in index ...".
                $last = array_pop($chunk);
                \assert($last !== null); // $chunk had $max_children entries
                $page = $this->buildInteriorPage($chunk, $last['pgno'], $page_size);
                $pgno = $this->main->appendPage($page);
                $next[] = ['pgno' => $pgno, 'divider_payload' => $last['divider_payload']];
            }
            $level = $next;
        }

        // Final root: $level entries plus rightmost.
        if (count($level) === 0) {
            // The rightmost is the entire tree — copy it.
            $this->main->writePage($main_index_rootpage, $this->main->readPage($rightmost));
            return;
        }
        $root = $this->buildInteriorPage($level, $rightmost, $page_size);
        $this->main->writePage($main_index_rootpage, $root);
    }

    /**
     * K-way merge generator. Yields each (key, rowid, cell) in
     * ascending (key, rowid) order across all sort runs. `cell` is
     * the worker-encoded SQLite leaf cell bytes for that row,
     * ready to drop into the result leaf without further per-row
     * encoding work.
     *
     * Uses a manual array-backed binary min-heap with three parallel
     * arrays (keys / rowids / source-indexes) instead of
     * SplPriorityQueue. SPL's compare() trampoline crosses the
     * PHP↔C boundary on every sift-up/sift-down step; for k=4 sort
     * runs and 300K total entries (3 indexes × 100K rows) that
     * dominates the merge time. The manual heap stays in pure PHP
     * but does only int comparisons inline, no method dispatch.
     *
     * @return \Generator<int, array{int, int, string}>
     */
    private function mergeStream(): \Generator
    {
        /** @var list<int> $keys */
        $keys = [];
        /** @var list<int> $rowids */
        $rowids = [];
        /** @var list<int> $sources */
        $sources = [];

        foreach ($this->sort_runs as $i => $run) {
            if ($run->hasMore()) {
                [$k, $r, $_c] = $run->peek();
                $keys[] = $k;
                $rowids[] = $r;
                $sources[] = $i;
            }
        }
        $size = count($keys);
        for ($i = ($size >> 1) - 1; $i >= 0; $i--) {
            $this->siftDown($keys, $rowids, $sources, $i, $size);
        }

        while ($size > 0) {
            $top_source = $sources[0];
            // Re-peek the cell bytes from the source — the heap only
            // tracks (key, rowid) so we don't have to copy variable
            // strings around on every sift-down. The peek is just an
            // array index into the reader's pre-decoded arrays.
            [$top_key, $top_rowid, $top_cell] = $this->sort_runs[$top_source]->peek();
            yield [$top_key, $top_rowid, $top_cell];

            $this->sort_runs[$top_source]->advance();
            if ($this->sort_runs[$top_source]->hasMore()) {
                [$nk, $nr, $_nc] = $this->sort_runs[$top_source]->peek();
                $keys[0] = $nk;
                $rowids[0] = $nr;
                // sources[0] stays the same — we're just replacing the
                // top with the next element from the same source.
                $this->siftDown($keys, $rowids, $sources, 0, $size);
            } else {
                $size--;
                if ($size > 0) {
                    $keys[0] = $keys[$size];
                    $rowids[0] = $rowids[$size];
                    $sources[0] = $sources[$size];
                    $this->siftDown($keys, $rowids, $sources, 0, $size);
                }
            }
        }
    }

    /**
     * Standard binary-heap sift-down keyed by (key, rowid). Operates
     * on three parallel arrays in lock-step.
     *
     * @param list<int> $keys
     * @param list<int> $rowids
     * @param list<int> $sources
     */
    private function siftDown(array &$keys, array &$rowids, array &$sources, int $i, int $size): void
    {
        $k = $keys[$i];
        $r = $rowids[$i];
        $s = $sources[$i];
        while (true) {
            $left = ($i << 1) + 1;
            if ($left >= $size) {
                break;
            }
            $right = $left + 1;
            $best = $left;
            if (
                $right < $size
                && (
                    $keys[$right] < $keys[$left]
                    || ($keys[$right] === $keys[$left] && $rowids[$right] < $rowids[$left])
                )
            ) {
                $best = $right;
            }
            if (
                $keys[$best] < $k
                || ($keys[$best] === $k && $rowids[$best] < $r)
            ) {
                $keys[$i] = $keys[$best];
                $rowids[$i] = $rowids[$best];
                $sources[$i] = $sources[$best];
                $i = $best;
            } else {
                break;
            }
        }
        $keys[$i] = $k;
        $rowids[$i] = $r;
        $sources[$i] = $s;
    }

    /**
     * Build an index leaf page (type 0x0a) from a list of cell blobs
     * (each already `varint payload_size + payload`).
     *
     * Assembled with a single `implode` rather than per-cell
     * `substr_replace` writes — repeated `substr_replace` on a
     * 4 KiB string for every cell is O(N²) memory traffic and
     * showed up at ~4 % of total ingest time on rbt:analyze. The
     * implode form does one linear pass over all cells.
     *
     * @param list<string> $cells
     */
    private function buildLeafPage(array $cells, int $page_size): string
    {
        $count = count($cells);
        $cells_total_size = 0;
        foreach ($cells as $c) {
            $cells_total_size += strlen($c);
        }
        $cell_content_start = $page_size - $cells_total_size;
        $required = 8 + 2 * $count + $cells_total_size;
        if ($required > $page_size) {
            throw new \RuntimeException(
                "leaf page would overflow ({$required} > {$page_size})"
            );
        }

        // Layout: 8-byte header, cell pointer array (2*N bytes),
        // free zeros, then cells starting at $cell_content_start.
        $cc_field = $cell_content_start === 65536 ? 0 : $cell_content_start;
        $header = chr(Format::BTREE_LEAF_INDEX) . pack('n', 0) . pack('n', $count)
            . pack('n', $cc_field) . "\x00";

        // Build the cell pointer array as one batched pack('n*')
        // call instead of a per-cell pack('n') loop — at ~370 cells
        // per leaf and 42 leaves per ingest the per-call dispatch
        // adds up.
        $offsets = [];
        $offset = $cell_content_start;
        foreach ($cells as $cell) {
            $offsets[] = $offset;
            $offset += strlen($cell);
        }
        $ptr_array = pack('n*', ...$offsets);

        $padding_bytes = $cell_content_start - (8 + 2 * $count);
        $padding = $padding_bytes > 0 ? str_repeat("\x00", $padding_bytes) : '';

        return $header . $ptr_array . $padding . implode('', $cells);
    }

    /**
     * Worst-case interior cell size: 4 bytes child + 1 varint
     * payload_size + 3-int payload (≤ 26 bytes) = ≤ 31 bytes; with
     * the 2-byte cell pointer it's ≤ 33. So a 4 KiB interior page
     * holds at least (4096 - 12) / 33 ≈ 123 cells. That's enough
     * fan-out that two levels cover 123² ≈ 15K leaves, three cover
     * ~1.8M.
     */
    private function maxInteriorCellCount(int $page_size): int
    {
        return intdiv($page_size - 12, 33);
    }

    /**
     * Build an interior index page (type 0x02). $children is a list
     * of `{pgno, divider_payload}` pairs — each becomes an interior
     * cell pointing at `pgno` with the SQLite-record `divider_payload`
     * as the separator key. `$rightmost_pgno` is stored separately in
     * the page header (the rightmost child has no key in interior
     * index pages — same convention as table interior pages).
     *
     * @param list<array{pgno: int, divider_payload: string}> $children
     */
    private function buildInteriorPage(array $children, int $rightmost_pgno, int $page_size): string
    {
        $interior_count = count($children);

        /** @var list<string> $cell_blobs */
        $cell_blobs = [];
        $cells_total_size = 0;
        foreach ($children as $entry) {
            $payload = $entry['divider_payload'];
            $blob = pack('N', $entry['pgno']) . Format::writeVarint(strlen($payload)) . $payload;
            $cell_blobs[] = $blob;
            $cells_total_size += strlen($blob);
        }

        $cell_content_start = $page_size - $cells_total_size;
        $required = 12 + 2 * $interior_count + $cells_total_size;
        if ($required > $page_size) {
            throw new \RuntimeException(
                "interior index page would overflow ({$required} > {$page_size})"
            );
        }

        // Same single-implode shape as buildLeafPage: 12-byte
        // header + cell-pointer array + padding + concatenated
        // cells. The cell pointer array is one pack('n*') call so
        // we don't pay 370 × pack('n') dispatches per page.
        $cc_field = $cell_content_start === 65536 ? 0 : $cell_content_start;
        $header = chr(Format::BTREE_INTERIOR_INDEX) . pack('n', 0) . pack('n', $interior_count)
            . pack('n', $cc_field) . "\x00" . pack('N', $rightmost_pgno);

        $offsets = [];
        $offset = $cell_content_start;
        foreach ($cell_blobs as $cell) {
            $offsets[] = $offset;
            $offset += strlen($cell);
        }
        $ptr_array = pack('n*', ...$offsets);

        $padding_bytes = $cell_content_start - (12 + 2 * $interior_count);
        $padding = $padding_bytes > 0 ? str_repeat("\x00", $padding_bytes) : '';

        return $header . $ptr_array . $padding . implode('', $cell_blobs);
    }
}
