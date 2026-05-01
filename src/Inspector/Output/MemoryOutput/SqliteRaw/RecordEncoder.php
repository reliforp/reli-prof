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
 * SQLite record format encoder for the integer columns we need to
 * index. The full record format supports floats, blobs, and text
 * with various widths; this MVP only emits records made of NULL,
 * "integer 0", "integer 1", and signed integers up to 64 bits — the
 * column types that appear in our integer-only indexes.
 *
 * Reference: https://www.sqlite.org/fileformat.html#record_format
 *
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedOperand
 * @psalm-suppress MixedArgument
 */
final class RecordEncoder
{
    /**
     * Encode a row of integer values (and at most one trailing NULL,
     * which never happens in our index payloads) into the SQLite
     * record format: header (varints), then column bodies in order.
     *
     * The integer column type code depends on the value's magnitude:
     *   0  -> code 0 (NULL)
     *   0  -> code 8 (zero, no body bytes)
     *   1  -> code 9 (one, no body bytes)
     *   small -> code 1..6 (1, 2, 3, 4, 6, 8 body bytes respectively)
     *
     * @param list<int|null> $values
     */
    public static function encodeIntegerRow(array $values): string
    {
        $type_codes = [];
        $bodies = [];
        foreach ($values as $v) {
            [$tc, $body] = self::encodeIntegerValue($v);
            $type_codes[] = $tc;
            $bodies[] = $body;
        }
        $header_inner = '';
        foreach ($type_codes as $tc) {
            $header_inner .= Format::writeVarint($tc);
        }
        // SQLite's "header size" varint includes the size of the
        // varint itself. Compute it iteratively (it's almost always
        // 1 byte; very long records can be 2-byte).
        $header_size = strlen($header_inner) + 1;
        $size_varint = Format::writeVarint($header_size);
        if (strlen($size_varint) > 1) {
            $header_size = strlen($header_inner) + strlen($size_varint);
            $size_varint = Format::writeVarint($header_size);
            // Pathological long-header iteration safety: re-check.
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
    private static function encodeIntegerValue(?int $v): array
    {
        if ($v === null) {
            return [0, ''];
        }
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
