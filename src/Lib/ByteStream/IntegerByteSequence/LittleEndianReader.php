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

namespace Reli\Lib\ByteStream\IntegerByteSequence;

use Reli\Lib\ByteStream\ByteReaderInterface;
use Reli\Lib\Integer\UInt64;

use function unpack;

final class LittleEndianReader implements IntegerByteSequenceReader
{
    #[\Override]
    public function read8(ByteReaderInterface $data, int $offset): int
    {
        return $data[$offset];
    }

    #[\Override]
    public function read16(ByteReaderInterface $data, int $offset): int
    {
        return ($data[$offset + 1] << 8) | $data[$offset];
    }

    #[\Override]
    public function read32(ByteReaderInterface $data, int $offset): int
    {
        // Hot path on cold attach (ELF symbol/hash-table parsing). Going
        // through ArrayAccess::offsetGet four times per read32 means four
        // PHP function calls + four ord() calls per uint32; for libphp.so
        // with tens of thousands of symbols that adds up to millions of
        // PHP-level calls. unpack('V', $bytes) collapses each read32 to two
        // native calls.
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('V', $data->createSliceAsString($offset, 4));
        return $unpacked[1];
    }

    #[\Override]
    public function read64(ByteReaderInterface $data, int $offset): UInt64
    {
        /** @var array{1: int, 2: int} $unpacked */
        $unpacked = unpack('V2', $data->createSliceAsString($offset, 8));
        return new UInt64($unpacked[2], $unpacked[1]);
    }
}
