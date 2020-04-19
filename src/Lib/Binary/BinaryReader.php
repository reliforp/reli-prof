<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpProfiler\Lib\Binary;

use PhpProfiler\Lib\UInt64;

/**
 * Class BinaryReader
 * @package PhpProfiler\Lib\Binary
 */
final class BinaryReader
{
    public function read8(string $data, int $offset): int
    {
        return ord($data[$offset]);
    }

    public function read16(string $data, int $offset): int
    {
        return (ord($data[$offset + 1]) << 8) | ord($data[$offset]);
    }

    public function read32(string $data, int $offset): int
    {
        return (ord($data[$offset + 3]) << 24)
            | (ord($data[$offset + 2]) << 16)
            | (ord($data[$offset + 1]) << 8)
            | ord($data[$offset]);
    }

    public function read64(string $data, int $offset): UInt64
    {
        return new UInt64(
            $this->read32($data, $offset + 4),
            $this->read32($data, $offset),
        );
    }

    public function readString(string $data, int $offset, int $size): string
    {
        return substr($data, $offset, $size);
    }
}
