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
        // running leaf buffer. When the buffer can't fit the next
        // cell we flush it as a new page in main.
        /** @var list<array{pgno: int, max_key: int, max_rowid: int}> $leaves */
        $leaves = [];
        $cells = [];
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
                // Current buffer is full — emit it.
                [$max_key, $max_rowid] = $this->lastDecoded($cells[count($cells) - 1]);
                $page = $this->buildLeafPage($cells, $page_size);
                $pgno = $this->main->appendPage($page);
                $leaves[] = ['pgno' => $pgno, 'max_key' => $max_key, 'max_rowid' => $max_rowid];
                $cells = [];
                $cells_size = 0;
            }
            $cells[] = $cell;
            $cells_size += strlen($cell);
        }

        // Final partial buffer.
        if (count($cells) > 0) {
            [$max_key, $max_rowid] = $this->lastDecoded($cells[count($cells) - 1]);
            $page = $this->buildLeafPage($cells, $page_size);
            $pgno = $this->main->appendPage($page);
            $leaves[] = ['pgno' => $pgno, 'max_key' => $max_key, 'max_rowid' => $max_rowid];
        }

        if ($leaves === []) {
            // No data — leave the empty leaf at $main_index_rootpage in
            // place (CREATE INDEX produced one).
            return;
        }

        if (count($leaves) === 1) {
            // Just one leaf — copy it over the rootpage.
            $this->main->writePage($main_index_rootpage, $this->main->readPage($leaves[0]['pgno']));
            // Note: the appended page at $leaves[0]['pgno'] becomes a
            // freelist leak. Acceptable for now — page count grows by
            // one. integrity_check tolerates this when freelist
            // accounting is consistent (we don't update freelist
            // bookkeeping; SQLite treats pages past page_count as
            // EOF and beyond page_count as not-mine).
            return;
        }

        // Multi-level interior tree.
        $level = $leaves;
        $max_children = $this->maxInteriorCellCount($page_size);
        while (count($level) > $max_children) {
            /** @var list<array{pgno: int, max_key: int, max_rowid: int}> $next */
            $next = [];
            for ($i = 0, $n = count($level); $i < $n; $i += $max_children) {
                $chunk = array_slice($level, $i, $max_children);
                $page = $this->buildInteriorPage($chunk, $page_size);
                $pgno = $this->main->appendPage($page);
                $last = $chunk[count($chunk) - 1];
                $next[] = ['pgno' => $pgno, 'max_key' => $last['max_key'], 'max_rowid' => $last['max_rowid']];
            }
            $level = $next;
        }

        $root = $this->buildInteriorPage($level, $page_size);
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

        $page = str_repeat("\x00", $page_size);
        $page[0] = chr(Format::BTREE_LEAF_INDEX);
        $page = substr_replace($page, pack('n', 0), 1, 2);
        $page = substr_replace($page, pack('n', $count), 3, 2);
        $cc_field = $cell_content_start === 65536 ? 0 : $cell_content_start;
        $page = substr_replace($page, pack('n', $cc_field), 5, 2);
        $page[7] = "\x00";

        // Cell pointer array starts at offset 8 (no rightmost ptr).
        $offset = $cell_content_start;
        $ptrs = [];
        foreach ($cells as $cell) {
            $page = substr_replace($page, $cell, $offset, strlen($cell));
            $ptrs[] = $offset;
            $offset += strlen($cell);
        }
        $ptr_offset = 8;
        foreach ($ptrs as $p) {
            $page = substr_replace($page, pack('n', $p), $ptr_offset, 2);
            $ptr_offset += 2;
        }
        return $page;
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
     * Build an interior index page (type 0x02) whose left-children
     * are entries [0..N-2] of $children and rightmost child is
     * entry N-1. Each interior cell stores the divider key/rowid as
     * its payload (the maximum (key, rowid) in the left subtree).
     *
     * @param list<array{pgno: int, max_key: int, max_rowid: int}> $children
     */
    private function buildInteriorPage(array $children, int $page_size): string
    {
        $count = count($children);
        $rightmost = $children[$count - 1]['pgno'];
        $interior_count = $count - 1;

        /** @var list<string> $cell_blobs */
        $cell_blobs = [];
        $cells_total_size = 0;
        for ($i = 0; $i < $interior_count; $i++) {
            $entry = $children[$i];
            $payload = RecordEncoder::encodeIntegerRow(
                [$this->run_id, $entry['max_key'], $entry['max_rowid']],
            );
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

        $page = str_repeat("\x00", $page_size);
        $page[0] = chr(Format::BTREE_INTERIOR_INDEX);
        $page = substr_replace($page, pack('n', 0), 1, 2);
        $page = substr_replace($page, pack('n', $interior_count), 3, 2);
        $cc_field = $cell_content_start === 65536 ? 0 : $cell_content_start;
        $page = substr_replace($page, pack('n', $cc_field), 5, 2);
        $page[7] = "\x00";
        $page = substr_replace($page, pack('N', $rightmost), 8, 4);

        $offset = $cell_content_start;
        $ptrs = [];
        foreach ($cell_blobs as $cell) {
            $page = substr_replace($page, $cell, $offset, strlen($cell));
            $ptrs[] = $offset;
            $offset += strlen($cell);
        }
        $ptr_offset = 12;
        foreach ($ptrs as $p) {
            $page = substr_replace($page, pack('n', $p), $ptr_offset, 2);
            $ptr_offset += 2;
        }
        return $page;
    }
}
