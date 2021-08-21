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

namespace PhpProfiler\Lib\Dwarf\AddressTable;

final class AddressTableHeader
{
    public function __construct(
        public int $unit_length,
        public int $half,
        public int $address_size,
        public int $segment_selector_size,
    ) {
    }
}