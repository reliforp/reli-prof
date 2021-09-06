<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\Dwarf\ObjectFile\Section;

use PhpProfiler\Lib\Dwarf\AddressTable\AddressTable;

final class DebugAddr
{
    public function __construct(
        public AddressTable $address_table
    ) {
    }
}