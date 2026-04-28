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

namespace Reli\Converter\BinaryTrace;

final class Varint
{
    /**
     * Encode a 64-bit integer as a base-128 varint (protobuf int64 wire format).
     *
     * Non-negative values use 1–9 bytes. Negative values are encoded as their
     * 64-bit two's-complement representation and always take 10 bytes —
     * matching protobuf's int64 encoding. Round-trips through {@see decode}
     * back to the original signed PHP int.
     */
    public static function encode(int $value): string
    {
        $bytes = '';
        do {
            $byte = $value & 0x7F;
            // PHP's >> is arithmetic (sign-extending). Mask the top 7 bits to
            // make this a logical right shift so negative values terminate at
            // 10 bytes instead of looping forever.
            $value = ($value >> 7) & 0x01FFFFFFFFFFFFFF;
            if ($value !== 0) {
                $byte |= 0x80;
            }
            $bytes .= chr($byte);
        } while ($value !== 0);
        return $bytes;
    }

    /**
     * Decode a varint from a string buffer at the given offset.
     *
     * @return array{int, int} [decoded_value, bytes_consumed]
     */
    public static function decode(string $buffer, int $offset = 0): array
    {
        $value = 0;
        $shift = 0;
        $pos = $offset;
        $length = strlen($buffer);
        do {
            if ($pos >= $length) {
                throw new BinaryTraceException('Unexpected end of buffer while decoding varint');
            }
            $byte = ord($buffer[$pos]);
            $value |= ($byte & 0x7F) << $shift;
            $shift += 7;
            $pos++;
        } while (($byte & 0x80) !== 0);
        return [$value, $pos - $offset];
    }

    /**
     * Decode a varint from a stream resource.
     *
     * @param resource $stream
     */
    public static function decodeFromStream($stream): int
    {
        $value = 0;
        $shift = 0;
        do {
            $byte_str = fread($stream, 1);
            if ($byte_str === '' || $byte_str === false) {
                throw new BinaryTraceException('Unexpected end of stream while decoding varint');
            }
            $byte = ord($byte_str);
            $value |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while (($byte & 0x80) !== 0);
        return $value;
    }
}
