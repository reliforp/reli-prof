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
 * already sorted by (key, rowid). The merger streams them through a
 * heap-of-iterators, encodes each merged record as a SQLite index
 * leaf cell (with the supplied real run_id prepended), and writes
 * the resulting b-tree to main, overwriting the empty index rootpage
 * SQLite created at CREATE INDEX time.
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
 * threshold). For 3-int payloads the encoded record is ≤ 26 bytes,
 * comfortably below the 4061-byte inline limit, so overflow doesn't
 * happen in practice.
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
        private int $run_id,
    ) {
    }

    public function merge(int $main_index_rootpage): void
    {
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

        foreach ($this->mergeStream() as [$key, $rowid]) {
            $payload = RecordEncoder::encodeIntegerRow([$this->run_id, $key, $rowid]);
            if (strlen($payload) > $inline_max) {
                throw new OverflowNotSupportedException(
                    'integer index payload exceeds inline threshold ('
                    . strlen($payload) . ' > ' . $inline_max . ')'
                );
            }
            $cell = Format::writeVarint(strlen($payload)) . $payload;

            $needed = 8 + (count($cells) + 1) * 2 + $cells_size + strlen($cell);
            if ($needed > $page_size && count($cells) > 0) {
                // Promote the last cell as the divider; emit the
                // remaining cells (still ≥ 1 because we required
                // count > 0 above) as the leaf.
                $promoted_payload = $payloads[count($payloads) - 1];
                $promoted_cell = $cells[count($cells) - 1];
                $promoted_cell_size = strlen($promoted_cell);
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
                $cells = [];
                $payloads = [];
                $cells_size = 0;
            }
            $cells[] = $cell;
            $payloads[] = $payload;
            $cells_size += strlen($cell);
        }

        // Final partial buffer becomes the rightmost leaf; no
        // promotion (the rightmost has no divider in the interior
        // page — the rightmost-child pointer lives in the page
        // header instead).
        $rightmost_pgno = 0;
        if (count($cells) > 0) {
            $page = $this->buildLeafPage($cells, $page_size);
            $rightmost_pgno = $this->main->appendPage($page);
        }

        if ($leaves_with_dividers === [] && $rightmost_pgno === 0) {
            // No data — leave the empty leaf in place.
            return;
        }

        if ($leaves_with_dividers === [] && $rightmost_pgno !== 0) {
            // Only one leaf; copy it over the rootpage.
            $this->main->writePage($main_index_rootpage, $this->main->readPage($rightmost_pgno));
            return;
        }

        // Multi-level interior tree. Build the first level above
        // the leaves, then recurse upward.
        /** @var list<array{pgno: int, divider_payload: string}> $level */
        $level = $leaves_with_dividers;
        // The very last child of this level is the rightmost leaf
        // (which has no divider).
        $rightmost = $rightmost_pgno;

        $max_children = $this->maxInteriorCellCount($page_size);
        while (count($level) >= $max_children) {
            /** @var list<array{pgno: int, divider_payload: string}> $next */
            $next = [];
            $remaining_rightmost = $rightmost;
            for ($i = 0, $n = count($level); $i < $n; $i += $max_children) {
                $chunk = array_slice($level, $i, $max_children);
                $is_last_chunk = ($i + $max_children >= $n);
                if ($is_last_chunk) {
                    $chunk_rightmost = $remaining_rightmost;
                } else {
                    // The last entry of this chunk gets promoted to
                    // become the divider in the next-level interior
                    // cell; its child becomes that interior cell's
                    // rightmost descendant for this internal page.
                    $last = $chunk[count($chunk) - 1];
                    $chunk_rightmost = $last['pgno'];
                }
                $page = $this->buildInteriorPage($chunk, $chunk_rightmost, $page_size);
                $pgno = $this->main->appendPage($page);
                if ($is_last_chunk) {
                    $rightmost = $pgno;
                    break; // no divider needed for the last chunk; it's the rightmost of the next level
                }
                // The cell that gets promoted up: divider = the
                // promoted entry from the last leaf in this chunk.
                $promoted = $chunk[count($chunk) - 1]['divider_payload'];
                $next[] = ['pgno' => $pgno, 'divider_payload' => $promoted];
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
     * K-way merge generator. Yields each (key, rowid) in ascending
     * order across all sort runs.
     *
     * @return \Generator<int, array{int, int}>
     */
    private function mergeStream(): \Generator
    {
        // Use a flat min-heap of (key, rowid, source_idx) tuples.
        $heap = new class extends \SplPriorityQueue {
            #[\Override]
            public function compare(mixed $priority1, mixed $priority2): int
            {
                /** @var array{int,int,int} $priority1 */
                /** @var array{int,int,int} $priority2 */
                if ($priority1[0] !== $priority2[0]) {
                    return $priority1[0] < $priority2[0] ? 1 : -1;
                }
                if ($priority1[1] !== $priority2[1]) {
                    return $priority1[1] < $priority2[1] ? 1 : -1;
                }
                return 0;
            }
        };
        foreach ($this->sort_runs as $i => $run) {
            if ($run->hasMore()) {
                [$k, $r] = $run->peek();
                $heap->insert([$k, $r, $i], [$k, $r, $i]);
            }
        }
        while (!$heap->isEmpty()) {
            /** @var array{int,int,int} $top */
            $top = $heap->extract();
            $i = (int)$top[2];
            yield [(int)$top[0], (int)$top[1]];
            $this->sort_runs[$i]->advance();
            if ($this->sort_runs[$i]->hasMore()) {
                [$nk, $nr] = $this->sort_runs[$i]->peek();
                $heap->insert([$nk, $nr, $i], [$nk, $nr, $i]);
            }
        }
    }

    /**
     * Decode a leaf cell back to (key, rowid). Used to compute the
     * "max key in this leaf" record we hand the interior page.
     *
     * @return array{int, int}
     */
    private function lastDecoded(string $cell): array
    {
        [$payload_size, $sz_len] = Format::readVarint($cell, 0);
        $payload_offset = $sz_len;

        // Record header: varint header_size, then varint type codes.
        [$_header_size, $hl] = Format::readVarint($cell, $payload_offset);
        $hdr = $payload_offset + $hl;
        $type_codes = [];
        $end = $payload_offset + $_header_size;
        while ($hdr < $end) {
            [$tc, $tl] = Format::readVarint($cell, $hdr);
            $type_codes[] = $tc;
            $hdr += $tl;
        }
        // Skip column 0 (run_id) body.
        $body = $end;
        $body += $this->bodySizeFor($type_codes[0]);
        $key = $this->decodeIntegerColumn($cell, $body, $type_codes[1]);
        $body += $this->bodySizeFor($type_codes[1]);
        $rowid = $this->decodeIntegerColumn($cell, $body, $type_codes[2]);
        return [$key, $rowid];
    }

    private function bodySizeFor(int $type_code): int
    {
        return match (true) {
            $type_code === 0, $type_code === 8, $type_code === 9 => 0,
            $type_code === 1 => 1,
            $type_code === 2 => 2,
            $type_code === 3 => 3,
            $type_code === 4 => 4,
            $type_code === 5 => 6,
            $type_code === 6 => 8,
            default => throw new \RuntimeException("unsupported type code in lastDecoded: {$type_code}"),
        };
    }

    private function decodeIntegerColumn(string $bytes, int $offset, int $type_code): int
    {
        switch ($type_code) {
            case 0:
                return 0;
            case 8:
                return 0;
            case 9:
                return 1;
            case 1:
                $b = ord($bytes[$offset]);
                return $b >= 128 ? $b - 256 : $b;
            case 2:
                $v = (int)unpack('n', substr($bytes, $offset, 2))[1];
                return $v >= 0x8000 ? $v - 0x10000 : $v;
            case 3:
                $bs = unpack('C3', substr($bytes, $offset, 3));
                $v = ($bs[1] << 16) | ($bs[2] << 8) | $bs[3];
                return $v >= 0x800000 ? $v - 0x1000000 : $v;
            case 4:
                $v = (int)unpack('N', substr($bytes, $offset, 4))[1];
                return $v >= 0x80000000 ? $v - 0x100000000 : $v;
            case 5:
                $bs = unpack('C6', substr($bytes, $offset, 6));
                $v = ($bs[1] << 40) | ($bs[2] << 32) | ($bs[3] << 24)
                    | ($bs[4] << 16) | ($bs[5] << 8) | $bs[6];
                if ($v >= (1 << 47)) {
                    $v -= (1 << 48);
                }
                return $v;
            case 6:
                return (int)unpack('J', substr($bytes, $offset, 8))[1];
            default:
                throw new \RuntimeException("unsupported integer type code: {$type_code}");
        }
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

        $offset = $cell_content_start;
        $ptr_array = '';
        foreach ($cells as $cell) {
            $ptr_array .= pack('n', $offset);
            $offset += strlen($cell);
        }

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
        // cells.
        $cc_field = $cell_content_start === 65536 ? 0 : $cell_content_start;
        $header = chr(Format::BTREE_INTERIOR_INDEX) . pack('n', 0) . pack('n', $interior_count)
            . pack('n', $cc_field) . "\x00" . pack('N', $rightmost_pgno);

        $offset = $cell_content_start;
        $ptr_array = '';
        foreach ($cell_blobs as $cell) {
            $ptr_array .= pack('n', $offset);
            $offset += strlen($cell);
        }

        $padding_bytes = $cell_content_start - (12 + 2 * $interior_count);
        $padding = $padding_bytes > 0 ? str_repeat("\x00", $padding_bytes) : '';

        return $header . $ptr_array . $padding . implode('', $cell_blobs);
    }
}
