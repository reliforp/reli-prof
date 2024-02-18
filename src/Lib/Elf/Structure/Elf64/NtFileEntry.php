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

namespace Reli\Lib\Elf\Structure\Elf64;

use Reli\Lib\Integer\UInt64;

class NtFileEntry
{
    public function __construct(
        public string $name,
        public UInt64 $start,
        public UInt64 $end,
        public UInt64 $file_offset,
    ) {
    }

    public function isInRange(UInt64 $address)
    {
        return $address->toInt() >= $this->start->toInt()
            and $address->toInt() <= $this->end->toInt();
    }
}
