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

namespace Reli\Inspector\MemoryDump\FastPath;

/**
 * Low-level byte readers for the analyze fast path.
 *
 * All methods assume little-endian, 64-bit layout — matching the
 * platform scope of the current analyze pipeline.
 *
 * Implementations deliberately avoid unpack() for small reads to
 * skip the intermediate array allocation. unpack('P', ...) is used
 * only for 64-bit reads where manual decoding is verbose.
 */
final class PrimitiveReaders
{
    public static function u8(string $buf, int $off): int
    {
        return ord($buf[$off]);
    }

    public static function u16le(string $buf, int $off): int
    {
        return ord($buf[$off]) | (ord($buf[$off + 1]) << 8);
    }

    public static function u32le(string $buf, int $off): int
    {
        return ord($buf[$off])
            | (ord($buf[$off + 1]) << 8)
            | (ord($buf[$off + 2]) << 16)
            | (ord($buf[$off + 3]) << 24);
    }

    public static function u64le(string $buf, int $off): int
    {
        /** @var array{1: int} */
        $r = unpack('P', $buf, $off);
        return $r[1];
    }

    /** Alias for u64le — semantic marker for pointer fields. */
    public static function ptr(string $buf, int $off): int
    {
        /** @var array{1: int} */
        $r = unpack('P', $buf, $off);
        return $r[1];
    }

    public static function i32le(string $buf, int $off): int
    {
        /** @var array{1: int} */
        $r = unpack('l', $buf, $off);
        return $r[1];
    }

    public static function slice(string $buf, int $off, int $len): string
    {
        return substr($buf, $off, $len);
    }
}
