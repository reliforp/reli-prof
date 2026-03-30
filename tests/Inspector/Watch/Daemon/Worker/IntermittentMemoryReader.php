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

use FFI;
use FFI\CData;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

final class IntermittentMemoryReader implements MemoryReaderInterface
{
    private int $call_count = 0;

    public function __construct(
        private int $fail_count,
        private int $succeed_count,
    ) {
    }

    public function read(int $pid, int $remote_address, int $size): CData
    {
        $this->call_count++;
        $cycle_pos = ($this->call_count - 1) % ($this->fail_count + $this->succeed_count);
        if ($cycle_pos < $this->fail_count) {
            throw new MemoryReaderException('simulated intermittent failure');
        }
        // Return a zeroed buffer
        $buf = FFI::new("uint8_t[$size]");
        assert($buf instanceof CData);
        return $buf;
    }
}
