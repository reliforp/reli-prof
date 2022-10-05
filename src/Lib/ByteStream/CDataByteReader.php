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

namespace Reli\Lib\ByteStream;

use FFI\CArray;
use FFI\CData;

use function chr;
use function count;
use function is_null;

final class CDataByteReader implements ByteReaderInterface
{
    use ByteReaderDisableWriteAccessTrait;

    /** @param CArray<int> $source */
    public function __construct(
        private CData $source
    ) {
    }

    public function offsetExists($offset): bool
    {
        if (count($this->source) <= $offset) {
            return false;
        }
        return !is_null($this->source[$offset]);
    }

    public function offsetGet($offset): int
    {
        return $this->source[$offset];
    }

    public function createSliceAsString(int $offset, int $size): string
    {
        $result = '';
        for ($i = $offset, $last_offset = $offset + $size; $i < $last_offset; $i++) {
            $result .= chr($this->source[$i]);
        }
        return $result;
    }
}
