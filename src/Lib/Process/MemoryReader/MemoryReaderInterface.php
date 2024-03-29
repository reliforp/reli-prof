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

namespace Reli\Lib\Process\MemoryReader;

use FFI\CData;

interface MemoryReaderInterface
{
    /**
     * @return \FFI\CArray<int>
     * @throws MemoryReaderException
     */
    public function read(int $pid, int $remote_address, int $size): CData;
}
