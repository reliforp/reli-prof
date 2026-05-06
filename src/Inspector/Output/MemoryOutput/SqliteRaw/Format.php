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
 * Constants and tiny helpers for the SQLite database file format.
 *
 * Reference: https://www.sqlite.org/fileformat.html
 *
 * All multi-byte integers in SQLite files are big-endian.
 */
final class Format
{
    /** "SQLite format 3\000" */
    public const MAGIC = "SQLite format 3\0";

    public const HEADER_SIZE = 100;

    // B-tree page types
    public const BTREE_INTERIOR_INDEX = 0x02;
    public const BTREE_INTERIOR_TABLE = 0x05;
    public const BTREE_LEAF_INDEX = 0x0a;
    public const BTREE_LEAF_TABLE = 0x0d;

    // Header field offsets (within page 1)
    public const HDR_PAGE_SIZE = 16;
    public const HDR_FILE_FORMAT_WRITE = 18;
    public const HDR_FILE_FORMAT_READ = 19;
    public const HDR_RESERVED_PER_PAGE = 20;
    public const HDR_CHANGE_COUNTER = 24;
    public const HDR_DB_PAGE_COUNT = 28;
    public const HDR_FREELIST_TRUNK = 32;
    public const HDR_FREELIST_PAGES = 36;
    public const HDR_SCHEMA_COOKIE = 40;
    public const HDR_TEXT_ENCODING = 56;
    public const HDR_LARGEST_ROOT_BTREE = 52; // for autovacuum

    // Page header offsets within a B-tree page
    public const PG_TYPE = 0;
    public const PG_FIRST_FREEBLOCK = 1;
    public const PG_CELL_COUNT = 3;
    public const PG_CELL_CONTENT_START = 5;
    public const PG_FRAGMENTED_FREE_BYTES = 7;
    public const PG_RIGHTMOST_PTR = 8; // interior pages only

    /**
     * Read a SQLite varint (1-9 byte big-endian variable-length integer).
     * The 9th byte (if reached) uses all 8 bits as data.
     *
     * @return array{int, int} [value, byte_length]
     */
    public static function readVarint(string $buf, int $offset): array
    {
        $value = 0;
        for ($i = 0; $i < 8; $i++) {
            $byte = ord($buf[$offset + $i]);
            $value = ($value << 7) | ($byte & 0x7f);
            if (($byte & 0x80) === 0) {
                return [$value, $i + 1];
            }
        }
        // 9th byte: 8 bits raw
        $value = ($value << 8) | ord($buf[$offset + 8]);
        return [$value, 9];
    }

    /**
     * Encode a non-negative integer as a SQLite varint.
     */
    public static function writeVarint(int $value): string
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('Negative varint not supported by this writer');
        }
        if ($value < 0x80) {
            return chr($value);
        }
        // Build 7-bit groups MSB-first.
        $bytes = [];
        // 9-byte form for >= 2^56
        if ($value >= (1 << 56)) {
            $tail = $value & 0xff;
            $value >>= 8;
            $head = [];
            for ($i = 0; $i < 8; $i++) {
                $head[] = ($value & 0x7f) | 0x80;
                $value >>= 7;
            }
            $head = array_reverse($head);
            return implode('', array_map('chr', $head)) . chr($tail);
        }
        while ($value > 0) {
            $bytes[] = ($value & 0x7f);
            $value >>= 7;
        }
        $bytes = array_reverse($bytes);
        // Set continuation bit on all but the last
        for ($i = 0, $n = count($bytes); $i < $n - 1; $i++) {
            $bytes[$i] |= 0x80;
        }
        return implode('', array_map('chr', $bytes));
    }
}
