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

namespace Reli\Inspector\Watch\Daemon\Worker;

use FFI\CData;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

final class FailingMemoryReader implements MemoryReaderInterface
{
    public function read(int $pid, int $remote_address, int $size): CData
    {
        throw new MemoryReaderException('simulated process_vm_readv failure');
    }
}
