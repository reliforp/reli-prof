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

class Elf64PrStatus
{
    public function __construct(
        public int $pid, // Elf64_Word
        public int $ppid, // Elf64_Word
    ) {
    }
}
