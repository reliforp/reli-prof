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

namespace PhpProfiler\Lib\ByteStream;

use ArrayAccess;
use LogicException;

/**
 * Interface ByteReaderInterface
 *
 * @extends ArrayAccess<int, int>
 * @package PhpProfiler\Lib\Binary
 */
interface ByteReaderInterface extends ArrayAccess
{
    /**
     * Whether a offset exists
     * @param int $offset
     * @return bool true on success or false on failure.
     */
    public function offsetExists($offset): bool;

    /**
     * Offset to retrieve
     * @param int $offset
     * @return int
     */
    public function offsetGet($offset): int;

    /**
     * create a slice as string
     * @param $offset
     * @param $size
     * @return string
     */
    public function createSliceAsString(int $offset, int $size): string;

    /**
     * Offset to set
     *
     * always throws LogicException if accessed for write
     *
     * @param mixed $offset
     * @param mixed $value
     * @return void
     * @throws LogicException
     */
    public function offsetSet($offset, $value): void;

    /**
     * Offset to unset
     *
     * always throws LogicException if accessed for write
     *
     * @param int $offset
     * @return void
     * @throws LogicException
     */
    public function offsetUnset($offset): void;
}
