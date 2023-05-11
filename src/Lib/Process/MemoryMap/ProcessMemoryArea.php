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

namespace Reli\Lib\Process\MemoryMap;

final class ProcessMemoryArea
{
    public function __construct(
        public string $begin,
        public string $end,
        public string $file_offset,
        public ProcessMemoryAttribute $attribute,
        public string $device_id,
        public int $inode_num,
        public string $name,
    ) {
    }

    public function isInRange(int $address): bool
    {
        return $address >= hexdec($this->begin)
            and $address <= hexdec($this->end);
    }
}
