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

interface IntegerByteSequenceReader
{
    public function read8(ByteReaderInterface $data, int $offset): int;
    public function read16(ByteReaderInterface $data, int $offset): int;
    public function read32(ByteReaderInterface $data, int $offset): int;
    public function read64(ByteReaderInterface $data, int $offset): UInt64;
}
