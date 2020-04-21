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

use FFI\CArray;
use FFI\CData;

final class CDataByteReader implements ByteReaderInterface
{
    use ByteReaderDisableWriteAccessTrait;

    /** @var CArray */
    private CData $source;

    /**
     * CDataByteReader constructor.
     * @param CArray $source
     */
    public function __construct(CData $source)
    {
        $this->source = $source;
    }

    public function offsetExists($offset): bool
    {
        return isset($this->source[$offset]);
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
