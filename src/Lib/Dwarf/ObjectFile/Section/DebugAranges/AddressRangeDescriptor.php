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

final class AddressRangeDescriptor
{
    public function __construct(
        public int $segment_selector,
        public int $beginning_address,
        public int $length,
    ) {
    }

    public function isTerminator(): bool
    {
        return $this->segment_selector === 0
            and $this->beginning_address === 0
            and $this->length === 0
        ;
    }
}
