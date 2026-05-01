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
 * Mixed-type record encoder for SQLite cell payloads.
 *
 * Generalises {@see RecordEncoder} to handle the column shapes the
 * shared-mmap parallel writer needs: INTEGER (signed, multi-width),
 * TEXT (UTF-8), NULL. BLOB is stubbed out for now — none of our
 * production tables use BLOB columns.
 *
 * Reference: https://www.sqlite.org/fileformat.html#record_format
 *
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedOperand
 * @psalm-suppress MixedArgument
 * @psalm-suppress MixedReturnStatement
 */
final class TableCellEncoder
{
    /**
     * Encode a table-leaf cell: `varint payload_size, varint rowid,
     * payload`. Returns the full cell byte sequence ready to be
     * memcpy'd into a leaf page slot.
     *
     * Caller is responsible for ensuring the payload fits inline
     * (i.e. payload_size ≤ page_size - 35); overflow chains are not
     * yet supported by this encoder.
     *
     * @param list<int|string|null> $values
     */
    public static function encodeTableLeafCell(int $rowid, array $values): string
    {
        $payload = self::encodeRecord($values);
        return Format::writeVarint(strlen($payload))
            . Format::writeVarint($rowid)
            . $payload;
    }

    /**
     * Encode a SQLite record: header (varint header_size + per-column
     * type-code varints) + bodies in order.
     *
     * @param list<int|string|null> $values
     */
    public static function encodeRecord(array $values): string
    {
        $type_codes = [];
        $bodies = [];
        foreach ($values as $v) {
            [$tc, $body] = self::encodeValue($v);
            $type_codes[] = $tc;
            $bodies[] = $body;
        }
        $header_inner = '';
        foreach ($type_codes as $tc) {
            $header_inner .= Format::writeVarint($tc);
        }
        // SQLite's "header size" varint includes the size of the
        // varint itself — same iterative resolution as RecordEncoder.
        $header_size = strlen($header_inner) + 1;
        $size_varint = Format::writeVarint($header_size);
        if (strlen($size_varint) > 1) {
            $header_size = strlen($header_inner) + strlen($size_varint);
            $size_varint = Format::writeVarint($header_size);
            if (strlen($size_varint) + strlen($header_inner) !== $header_size) {
                $header_size = strlen($size_varint) + strlen($header_inner);
                $size_varint = Format::writeVarint($header_size);
            }
        }
        return $size_varint . $header_inner . implode('', $bodies);
    }

    /**
     * @return array{int, string} [type_code, body_bytes]
     */
    private static function encodeValue(int|string|null $v): array
    {
        if ($v === null) {
            return [0, ''];
        }
        if (is_int($v)) {
            return self::encodeInteger($v);
        }
        // TEXT: type code = 13 + 2 * length. Body is the UTF-8 bytes.
        return [13 + 2 * strlen($v), $v];
    }

    /**
     * @return array{int, string}
     */
    private static function encodeInteger(int $v): array
    {
        if ($v === 0) {
            return [8, ''];
        }
        if ($v === 1) {
            return [9, ''];
        }
        if ($v >= -128 && $v <= 127) {
            return [1, pack('c', $v)];
        }
        if ($v >= -32768 && $v <= 32767) {
            return [2, pack('n', $v & 0xffff)];
        }
        if ($v >= -8388608 && $v <= 8388607) {
            $u = $v & 0xffffff;
            return [3, chr(($u >> 16) & 0xff) . chr(($u >> 8) & 0xff) . chr($u & 0xff)];
        }
        if ($v >= -2147483648 && $v <= 2147483647) {
            return [4, pack('N', $v & 0xffffffff)];
        }
        if ($v >= -(1 << 47) && $v <= ((1 << 47) - 1)) {
            $u = $v & 0xffffffffffff;
            return [
                5,
                chr(($u >> 40) & 0xff) . chr(($u >> 32) & 0xff) . chr(($u >> 24) & 0xff)
                . chr(($u >> 16) & 0xff) . chr(($u >> 8) & 0xff) . chr($u & 0xff),
            ];
        }
        return [6, pack('J', $v)];
    }
}
