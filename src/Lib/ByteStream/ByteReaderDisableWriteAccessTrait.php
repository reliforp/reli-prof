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

namespace PhpProfiler\Lib\ByteStream;

use LogicException;

trait ByteReaderDisableWriteAccessTrait
{
    /**
     * @param mixed $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value): void
    {
        throw new LogicException('write access is forbidden for ByteReaderInteface');
    }

    /** @param mixed $offset */
    public function offsetUnset($offset): void
    {
        throw new LogicException('write access is forbidden for ByteReaderInteface');
    }
}
