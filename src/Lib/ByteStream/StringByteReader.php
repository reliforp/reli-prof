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

use function ord;
use function substr;

final class StringByteReader implements ByteReaderInterface
{
    use ByteReaderDisableWriteAccessTrait;

    public function __construct(
        public string $source
    ) {
    }

    public function offsetExists($offset): bool
    {
        return isset($this->source[$offset]);
    }

    public function offsetGet($offset): int
    {
        if (!isset($this->source[$offset])) {
            throw new \OutOfBoundsException();
        }
        return ord($this->source[$offset]);
    }

    public function createSliceAsString(int $offset, int $size): string
    {
        return substr($this->source, $offset, $size);
    }
}
