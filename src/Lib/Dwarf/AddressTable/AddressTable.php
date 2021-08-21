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

final class AddressTable
{
    /** @param AddressTableEntry[] $addresses */
    public function __construct(
        public AddressTableHeader $address_table_header,
        public array $addresses,
    ) {
    }
}
