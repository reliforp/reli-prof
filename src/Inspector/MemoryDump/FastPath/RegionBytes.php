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

namespace Reli\Inspector\MemoryDump\FastPath;

/**
 * A contiguous byte range from a dump region, addressable by
 * remote (target-process) address.
 */
final class RegionBytes
{
    public function __construct(
        public readonly int $baseAddress,
        public readonly string $bytes,
    ) {
    }

    public function contains(int $address, int $size): bool
    {
        return $address >= $this->baseAddress
            && ($address + $size) <= ($this->baseAddress + strlen($this->bytes));
    }

    public function offsetOf(int $address): int
    {
        return $address - $this->baseAddress;
    }
}
