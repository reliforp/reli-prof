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

namespace PhpProfiler\Lib\Dwarf\ObjectFile\Section\DebugAranges;

final class DebugAranges
{
    /** @param AddressRangeDescriptor[] $address_range_descriptors */
    public function __construct(
        public DebugArangesHeader $header,
        public array $address_range_descriptors
    ) {
    }
}
