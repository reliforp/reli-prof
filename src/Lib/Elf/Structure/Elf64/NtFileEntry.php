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

final class NtFileEntry
{
    public function __construct(
        public string $name,
        public UInt64 $start,
        public UInt64 $end,
        public UInt64 $file_offset,
    ) {
    }

    public function isInRange(UInt64 $address): bool
    {
        // NT_FILE notes use [start, end) like /proc/<pid>/maps — end is
        // exclusive. Treating it as inclusive misattributes the boundary
        // VMA to the preceding entry when two libraries are mapped
        // back-to-back (e.g. libtinfo ending exactly where libc begins),
        // which makes findByNameRegex drop the lowest VMA of the second
        // library and skews module_memory_map.getBaseAddress() by that
        // VMA's size — symbol resolution then targets bytes one segment
        // off in the wrong direction.
        return $address->toInt() >= $this->start->toInt()
            and $address->toInt() < $this->end->toInt();
    }
}
