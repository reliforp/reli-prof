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

use ArrayAccess;
use LogicException;

/** @extends ArrayAccess<int, int> */
interface ByteReaderInterface extends ArrayAccess
{
    /**
     * Whether a offset exists
     * @param int $offset
     */
    public function offsetExists($offset): bool;

    /**
     * Offset to retrieve
     * @param int $offset
     */
    public function offsetGet($offset): int;

    /** create a slice as string */
    public function createSliceAsString(int $offset, int $size): string;

    /**
     * Offset to set
     *
     * always throws LogicException if accessed for write
     *
     * @param mixed $offset
     * @param mixed $value
     * @throws LogicException
     */
    public function offsetSet($offset, $value): void;

    /**
     * Offset to unset
     *
     * always throws LogicException if accessed for write
     *
     * @param int $offset
     * @throws LogicException
     */
    public function offsetUnset($offset): void;
}
