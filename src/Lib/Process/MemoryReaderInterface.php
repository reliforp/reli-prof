<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpProfiler\Lib\Process;

use FFI\CData;

interface MemoryReaderInterface
{
    /**
     * @param int $pid
     * @param int $remote_address
     * @param int $size
     * @return \FFI\CArray
     * @throws MemoryReaderException
     */
    public function read(int $pid, int $remote_address, int $size): CData;

    /**
     * @param int $pid
     * @param int $remote_address
     * @return int
     * @throws MemoryReaderException
     */
    public function readAsInt64(int $pid, int $remote_address): int;
}
