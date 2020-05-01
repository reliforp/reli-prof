<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\Binary;

use LogicException;

/**
 * Trait ByteReaderDisableWriteAccessTrait
 * @package PhpProfiler\Lib\Binary
 */
trait ByteReaderDisableWriteAccessTrait
{
    public function offsetSet($offset, $value): void
    {
        throw new LogicException('write access is forbidden for ByteReaderInteface');
    }

    public function offsetUnset($offset): void
    {
        throw new LogicException('write access is forbidden for ByteReaderInteface');
    }
}
