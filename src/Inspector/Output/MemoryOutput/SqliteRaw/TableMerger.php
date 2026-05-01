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
 * Format-direct merge of shard table b-trees into a target SQLite
 * database. See `docs/internals/format-direct-merge.md` for the
 * design.
 *
 * Restrictions for this MVP:
 *   - shards' rowids must be globally disjoint and contiguous so the
 *     merged b-tree's rowid order is the concatenation of shards in
 *     order — the merger does not re-sort by rowid.
 *   - leaf cells must not have overflow chains (caller must avoid
 *     payloads larger than the inline threshold). Detected at merge
 *     time; throws OverflowNotSupported if violated.
 *   - the target rootpage's table must be empty (one empty leaf).
 *     The merger does not currently implement merge-into-non-empty.
 *
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArrayAccess
 * @psalm-suppress MixedOperand
 * @psalm-suppress MixedArgument
 * @psalm-suppress PossiblyInvalidArrayAccess
 * @psalm-suppress MixedArgumentTypeCoercion
 * @psalm-suppress MixedArrayAssignment
 * @psalm-suppress MixedReturnStatement
 * @psalm-suppress InvalidArrayOffset
 * @psalm-suppress RedundantCast
 */
final class TableMerger
{
    /**
     * @param list<Reader> $shards in rowid-range order
     */
    public function __construct(
        private Writer $main,
        private array $shards,
    ) {
    }

    /**
     * Merge the given table from all shards into main.
     */
    public function merge(string $table_name, int $main_rootpage): void
    {
        $page_size = $this->main->page_size;
        $inline_max = $page_size - 35; // U = page_size - reserved(0) - 35

        // 1) Walk each shard's table b-tree, collect leaf page bytes
        //    in order. Verify no overflow and capture the maximum
        //    rowid in each leaf so we can drive the new interior
        //    page.
        /** @var list<array{bytes: string, max_rowid: int}> $leaves */
        $leaves = [];
        foreach ($this->shards as $shard) {
            $entry = $shard->findSchemaEntry($table_name);
            if ($entry === null) {
                continue; // shard has no such table — skip
            }
            $shard_leaves = $shard->collectTableLeaves($entry['rootpage']);
            foreach ($shard_leaves as $shard_leaf_pgno) {
                $page = $shard->readPage($shard_leaf_pgno);
                $leaf_btree_offset = $shard_leaf_pgno === 1 ? Format::HEADER_SIZE : 0;
                $type = ord($page[$leaf_btree_offset]);
                if ($type !== Format::BTREE_LEAF_TABLE) {
                    throw new \RuntimeException(
                        sprintf(
                            'expected table leaf at shard pgno %d, got 0x%02x',
                            $shard_leaf_pgno,
                            $type,
                        )
                    );
                }
                if ($leaf_btree_offset !== 0) {
                    throw new \RuntimeException(
                        'shard rootpage cannot be page 1 (sqlite_master clash)'
                    );
                }
                $cell_count = unpack('n', substr($page, Format::PG_CELL_COUNT, 2))[1];
                if ((int)$cell_count === 0) {
                    continue; // skip empty leaves
                }
                $max_rowid = $this->verifyLeafAndGetMaxRowid(
                    $page,
                    (int)$cell_count,
                    $inline_max,
                );
                $leaves[] = ['bytes' => $page, 'max_rowid' => $max_rowid];
            }
        }

        // 2) Special cases for the new root structure.
        if ($leaves === []) {
            // No data — leave main's existing empty leaf in place.
            return;
        }
        if (count($leaves) === 1) {
            // One leaf: just copy it over the main rootpage.
            $this->main->writePage($main_rootpage, $leaves[0]['bytes']);
            return;
        }

        // 3) Append leaves to main and remember their new page numbers.
        /** @var list<array{pgno: int, max_rowid: int}> $appended */
        $appended = [];
        foreach ($leaves as $leaf) {
            $pgno = $this->main->appendPage($leaf['bytes']);
            $appended[] = ['pgno' => $pgno, 'max_rowid' => $leaf['max_rowid']];
        }

        // 4) Build interior page hierarchy bottom-up. Each level
        //    groups its children into chunks that fit in one page;
        //    the resulting interior pages become the next level. The
        //    last page produced is the new root and goes at
        //    main_rootpage (overwriting main's pre-existing empty
        //    leaf there).
        $max_children = $this->maxInteriorCellCount($page_size);
        $level = $appended;
        while (count($level) > $max_children) {
            /** @var list<array{pgno: int, max_rowid: int}> $next_level */
            $next_level = [];
            for ($i = 0, $n = count($level); $i < $n; $i += $max_children) {
                $chunk = array_slice($level, $i, $max_children);
                $page = $this->buildInteriorPage($chunk, $page_size);
                $pgno = $this->main->appendPage($page);
                $next_level[] = ['pgno' => $pgno, 'max_rowid' => $chunk[count($chunk) - 1]['max_rowid']];
            }
            $level = $next_level;
        }

        $root_page = $this->buildInteriorPage($level, $page_size);
        $this->main->writePage($main_rootpage, $root_page);
    }

    /**
     * Walk the cell pointer array, decode each cell's payload size
     * varint, and return the maximum rowid (== last cell's rowid for
     * a sane leaf, but compute it explicitly for safety). Throws
     * if any cell would need an overflow chain.
     */
    private function verifyLeafAndGetMaxRowid(
        string $page,
        int $cell_count,
        int $inline_max,
    ): int {
        $cell_ptr_array_offset = 8; // leaf pages: header is 8 bytes
        $max_rowid = PHP_INT_MIN;
        for ($i = 0; $i < $cell_count; $i++) {
            $cell_offset = unpack('n', substr($page, $cell_ptr_array_offset + $i * 2, 2))[1];
            $cell_offset = (int)$cell_offset;
            [$payload_size, $sz_len] = Format::readVarint($page, $cell_offset);
            if ($payload_size > $inline_max) {
                throw new OverflowNotSupportedException(
                    "cell at offset {$cell_offset} has overflow chain (payload {$payload_size} > {$inline_max})"
                );
            }
            [$rowid, $_rowid_len] = Format::readVarint($page, $cell_offset + $sz_len);
            if ($rowid > $max_rowid) {
                $max_rowid = $rowid;
            }
        }
        return $max_rowid;
    }

    /**
     * Worst-case interior cell is u32 child + 9-byte varint = 13
     * bytes plus 2 bytes for the cell pointer. Page header is 12
     * bytes (interior). So:
     *   max_cells = (page_size - 12) / 15
     * Round down. For 4096: (4096 - 12) / 15 = 272. We use a more
     * generous estimate based on typical varint length (5 bytes for
     * < 2^32 rowid):
     *   typical_cells = (page_size - 12) / 11 = ~371 for 4096.
     */
    private function maxInteriorCellCount(int $page_size): int
    {
        return intdiv($page_size - 12, 15); // worst-case bound, safe
    }

    /**
     * Build a single-page interior table b-tree with `count($appended) - 1`
     * cells (leaves [0..N-2]) plus a rightmost pointer to leaves[N-1].
     *
     * Header (12 bytes): type 0x05, first_freeblock 0, cell_count,
     * cell_content_area_start, fragmented 0, rightmost_ptr.
     *
     * Cells layout: from end of page backward. Each cell:
     *   u32 child_pgno + varint max_rowid_in_left_subtree.
     *
     * Cell pointer array: u16 BE offsets in cell-emission order
     * (which matches rowid order for our merge).
     *
     * @param list<array{pgno: int, max_rowid: int}> $appended
     */
    private function buildInteriorPage(array $appended, int $page_size): string
    {
        $count = count($appended);
        // The rightmost pointer takes the last entry; remaining (N-1)
        // become regular interior cells.
        $rightmost = $appended[$count - 1]['pgno'];
        $interior_count = $count - 1;

        // Build cells back-to-front so we can compute their offsets.
        // We'll emit them in order 0..N-2; each cell's offset is
        // determined by accumulating the cells written after it.
        /** @var list<string> $cell_blobs */
        $cell_blobs = [];
        $total_cell_bytes = 0;
        for ($i = 0; $i < $interior_count; $i++) {
            $entry = $appended[$i];
            $blob = pack('N', $entry['pgno']) . Format::writeVarint($entry['max_rowid']);
            $cell_blobs[] = $blob;
            $total_cell_bytes += strlen($blob);
        }

        // Cell content area starts at: page_size - total_cell_bytes.
        // SQLite stores cells from the end of the page going down,
        // with the cell pointer array growing forward from offset 12.
        // Each pointer is 2 bytes; required space is
        //   12 + 2*interior_count + total_cell_bytes <= page_size.
        $required = 12 + 2 * $interior_count + $total_cell_bytes;
        if ($required > $page_size) {
            throw new \RuntimeException(
                "interior page would overflow ({$required} > {$page_size})"
            );
        }

        $page = str_repeat("\x00", $page_size);
        // Header
        $page[0] = chr(Format::BTREE_INTERIOR_TABLE);
        $page = substr_replace($page, pack('n', 0), 1, 2); // first freeblock
        $page = substr_replace($page, pack('n', $interior_count), 3, 2);
        $cell_content_start = $page_size - $total_cell_bytes;
        // SQLite encodes "65536" as 0 in the cell content start field.
        $cc_field = $cell_content_start === 65536 ? 0 : $cell_content_start;
        $page = substr_replace($page, pack('n', $cc_field), 5, 2);
        $page[7] = "\x00"; // fragmented free bytes
        $page = substr_replace($page, pack('N', $rightmost), 8, 4);

        // Cells: emit at $cell_content_start onward, in rowid order.
        $offset = $cell_content_start;
        $cell_ptrs = [];
        foreach ($cell_blobs as $blob) {
            $page = substr_replace($page, $blob, $offset, strlen($blob));
            $cell_ptrs[] = $offset;
            $offset += strlen($blob);
        }

        // Cell pointer array: starts at offset 12 (after the 12-byte
        // interior header).
        $ptr_offset = 12;
        foreach ($cell_ptrs as $p) {
            $page = substr_replace($page, pack('n', $p), $ptr_offset, 2);
            $ptr_offset += 2;
        }

        return $page;
    }
}
