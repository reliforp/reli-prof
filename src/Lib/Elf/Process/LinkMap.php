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

namespace Reli\Lib\Elf\Process;

final class LinkMap
{
    public function __construct(
        public int $this_address,
        public int $l_addr,
        public string $l_name,
        public int $l_ld,
        public int $l_next_address,
        public int $l_prev_address,
    ) {
    }
}
