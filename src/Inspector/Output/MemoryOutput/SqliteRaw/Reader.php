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
 * Page-level reader for a SQLite database file.
 *
 * Loads the whole file into memory (file_get_contents). For our use
 * case shard files are bounded by the heap dump size and live for the
 * duration of the merge — paging-via-mmap is a future optimisation if
 * shards ever exceed available RAM.
 *
 * Only the operations the format-direct merger needs are exposed:
 * reading the header, fetching a page by 1-indexed page number,
 * resolving a table's rootpage via sqlite_master, and walking a
 * table b-tree to enumerate its leaf pages in rowid order.
 *
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArrayAccess
 * @psalm-suppress MixedOperand
 * @psalm-suppress MixedArgument
 * @psalm-suppress PossiblyInvalidArrayAccess
 * @psalm-suppress MixedArgumentTypeCoercion
 * @psalm-suppress MixedReturnStatement
 * @psalm-suppress MixedReturnTypeCoercion
 * @psalm-suppress RedundantCast
 */
final class Reader
{
    /** Cache of `sqlite_master` rows indexed by table name. */
    private ?array $sqlite_master_cache = null;

    private function __construct(
        private string $bytes,
        public readonly int $page_size,
        public readonly int $page_count,
    ) {
    }

    public static function open(string $path): self
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("failed to open SQLite file: {$path}");
        }
        if (strlen($bytes) < Format::HEADER_SIZE) {
            throw new \RuntimeException("file is shorter than SQLite header: {$path}");
        }
        if (substr($bytes, 0, 16) !== Format::MAGIC) {
            throw new \RuntimeException("not a SQLite database (bad magic): {$path}");
        }
        $page_size_field = unpack('n', substr($bytes, Format::HDR_PAGE_SIZE, 2))[1];
        $page_size = $page_size_field === 1 ? 65536 : (int)$page_size_field;
        $page_count = unpack('N', substr($bytes, Format::HDR_DB_PAGE_COUNT, 4))[1];
        if ($page_size < 512 || ($page_size & ($page_size - 1)) !== 0) {
            throw new \RuntimeException("invalid SQLite page size: {$page_size}");
        }
        return new self($bytes, (int)$page_size, (int)$page_count);
    }

    public function rawBytes(): string
    {
        return $this->bytes;
    }

    /**
     * Read page `$pgno` (1-indexed). Page 1 contains the 100-byte
     * header at the start; for our purposes the caller is expected
     * to skip past it when treating page 1 as a b-tree page.
     */
    public function readPage(int $pgno): string
    {
        if ($pgno < 1 || $pgno > $this->page_count) {
            throw new \OutOfRangeException("page {$pgno} out of range (1..{$this->page_count})");
        }
        $offset = ($pgno - 1) * $this->page_size;
        $page = substr($this->bytes, $offset, $this->page_size);
        if (strlen($page) !== $this->page_size) {
            throw new \RuntimeException("short read on page {$pgno}");
        }
        return $page;
    }

    /**
     * Look up a table or index in sqlite_master. Returns
     * `['type' => 'table'|'index'|..., 'name' => string,
     *   'tbl_name' => string, 'rootpage' => int, 'sql' => string]`,
     * or null if no entry matches `$name`.
     *
     * @return array{type:string,name:string,tbl_name:string,rootpage:int,sql:string}|null
     */
    public function findSchemaEntry(string $name): ?array
    {
        if ($this->sqlite_master_cache === null) {
            $this->sqlite_master_cache = $this->loadSqliteMaster();
        }
        return $this->sqlite_master_cache[$name] ?? null;
    }

    /**
     * Find a table's rootpage. Throws if there's no such table.
     */
    public function tableRootpage(string $name): int
    {
        $row = $this->findSchemaEntry($name);
        if ($row === null || $row['type'] !== 'table') {
            throw new \RuntimeException("no such table in SQLite file: {$name}");
        }
        return $row['rootpage'];
    }

    /**
     * Walk a table b-tree starting at `$rootpage` and return the leaf
     * page numbers in rowid order.
     *
     * @return list<int>
     */
    public function collectTableLeaves(int $rootpage): array
    {
        $leaves = [];
        $this->walkTablePages($rootpage, $leaves);
        return $leaves;
    }

    /**
     * @param list<int> $leaves
     */
    private function walkTablePages(int $pgno, array &$leaves): void
    {
        $page = $this->readPage($pgno);
        // Page 1 has the 100-byte file header before its b-tree page header.
        $btree_offset = $pgno === 1 ? Format::HEADER_SIZE : 0;
        $type = ord($page[$btree_offset]);
        if ($type === Format::BTREE_LEAF_TABLE) {
            $leaves[] = $pgno;
            return;
        }
        if ($type !== Format::BTREE_INTERIOR_TABLE) {
            throw new \RuntimeException(
                sprintf('expected table b-tree page at %d, got type 0x%02x', $pgno, $type)
            );
        }
        $cell_count = unpack('n', substr($page, $btree_offset + Format::PG_CELL_COUNT, 2))[1];
        $rightmost = unpack('N', substr($page, $btree_offset + Format::PG_RIGHTMOST_PTR, 4))[1];
        $cell_ptr_array_offset = $btree_offset + 12; // 8-byte hdr + rightmost
        for ($i = 0; $i < $cell_count; $i++) {
            $cell_ptr = unpack('n', substr($page, $cell_ptr_array_offset + $i * 2, 2))[1];
            // Interior table cell: u32 child page + varint rowid
            $child_pgno = unpack('N', substr($page, (int)$cell_ptr, 4))[1];
            $this->walkTablePages((int)$child_pgno, $leaves);
        }
        $this->walkTablePages((int)$rightmost, $leaves);
    }

    /**
     * Parse sqlite_master (rooted at page 1, b-tree starts at offset
     * 100). Returns a name => row map. Only single-leaf sqlite_master
     * is supported here; if the schema ever grows past one page,
     * this will need to walk like collectTableLeaves does. Our
     * shards have ≤ 4 tables so a single leaf is always enough.
     */
    private function loadSqliteMaster(): array
    {
        $page = $this->readPage(1);
        $btree_offset = Format::HEADER_SIZE;
        $type = ord($page[$btree_offset]);
        if ($type !== Format::BTREE_LEAF_TABLE) {
            throw new \RuntimeException(
                sprintf('sqlite_master root is not a leaf (type 0x%02x); not yet supported', $type)
            );
        }
        $cell_count = unpack('n', substr($page, $btree_offset + Format::PG_CELL_COUNT, 2))[1];
        $cell_ptr_array_offset = $btree_offset + 8;

        $entries = [];
        for ($i = 0; $i < $cell_count; $i++) {
            $cell_ptr = unpack('n', substr($page, $cell_ptr_array_offset + $i * 2, 2))[1];
            $row = $this->decodeSqliteMasterCell($page, (int)$cell_ptr);
            $entries[$row['name']] = $row;
        }
        return $entries;
    }

    /**
     * Decode a sqlite_master leaf cell starting at `$offset` within
     * `$page`. The cell shape is:
     *   varint payload_size
     *   varint rowid
     *   record { type:TEXT, name:TEXT, tbl_name:TEXT, rootpage:INT, sql:TEXT }
     *
     * @return array{type:string,name:string,tbl_name:string,rootpage:int,sql:string}
     */
    private function decodeSqliteMasterCell(string $page, int $offset): array
    {
        [$_payload_size, $sz_len] = Format::readVarint($page, $offset);
        $offset += $sz_len;
        [$_rowid, $rowid_len] = Format::readVarint($page, $offset);
        $offset += $rowid_len;
        $payload_offset = $offset;

        [$header_size, $hl] = Format::readVarint($page, $payload_offset);
        $hdr_offset = $payload_offset + $hl;
        $hdr_end = $payload_offset + $header_size;
        $type_codes = [];
        while ($hdr_offset < $hdr_end) {
            [$tc, $tl] = Format::readVarint($page, $hdr_offset);
            $type_codes[] = $tc;
            $hdr_offset += $tl;
        }

        $body_offset = $hdr_end;
        $values = [];
        foreach ($type_codes as $tc) {
            [$value, $bytes_used] = $this->decodeRecordValue($page, $body_offset, $tc);
            $values[] = $value;
            $body_offset += $bytes_used;
        }

        return [
            'type' => (string)($values[0] ?? ''),
            'name' => (string)($values[1] ?? ''),
            'tbl_name' => (string)($values[2] ?? ''),
            'rootpage' => (int)($values[3] ?? 0),
            'sql' => (string)($values[4] ?? ''),
        ];
    }

    /**
     * Decode a single column value at `$offset` given its type code.
     *
     * @return array{int|string|float|null, int} [value, bytes_consumed]
     */
    private function decodeRecordValue(string $page, int $offset, int $type_code): array
    {
        switch (true) {
            case $type_code === 0:
                return [null, 0];
            case $type_code === 1:
                $b = ord($page[$offset]);
                return [$b >= 128 ? $b - 256 : $b, 1];
            case $type_code === 2:
                $v = unpack('n', substr($page, $offset, 2))[1];
                return [$v >= 0x8000 ? $v - 0x10000 : $v, 2];
            case $type_code === 3:
                $b = unpack('C3', substr($page, $offset, 3));
                $v = ($b[1] << 16) | ($b[2] << 8) | $b[3];
                return [$v >= 0x800000 ? $v - 0x1000000 : $v, 3];
            case $type_code === 4:
                $v = unpack('N', substr($page, $offset, 4))[1];
                return [$v >= 0x80000000 ? $v - 0x100000000 : $v, 4];
            case $type_code === 5:
                $b = unpack('C6', substr($page, $offset, 6));
                $v = ($b[1] << 40) | ($b[2] << 32) | ($b[3] << 24)
                    | ($b[4] << 16) | ($b[5] << 8) | $b[6];
                if ($v >= (1 << 47)) {
                    $v -= (1 << 48);
                }
                return [$v, 6];
            case $type_code === 6:
                // 64-bit signed big-endian. PHP integers are 64-bit on
                // 64-bit platforms, so just unpack as int64.
                $v = unpack('J', substr($page, $offset, 8))[1];
                return [$v, 8];
            case $type_code === 7:
                $v = unpack('E', substr($page, $offset, 8))[1];
                return [$v, 8];
            case $type_code === 8:
                return [0, 0];
            case $type_code === 9:
                return [1, 0];
            case $type_code >= 12 && $type_code % 2 === 0:
                $len = ($type_code - 12) >> 1;
                return [substr($page, $offset, $len), $len];
            case $type_code >= 13 && $type_code % 2 === 1:
                $len = ($type_code - 13) >> 1;
                return [substr($page, $offset, $len), $len];
            default:
                throw new \RuntimeException("unsupported record type code: {$type_code}");
        }
    }
}
