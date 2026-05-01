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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk;

/**
 * A run of like-shaped live small-bin slots observed at constant stride.
 *
 * "Like-shaped" means the slots share the same first-24-byte fingerprint —
 * cheap structural similarity that, combined with the stride / count, is
 * enough to surface "16,000 copies of one shape in bin[32 B]" without any
 * detector knowing what the shape is.
 */
final class PeriodicGroup
{
    public function __construct(
        public readonly int $bin_num,
        public readonly int $bin_size,
        public readonly int $count,
        public readonly int $stride,
        public readonly string $fingerprint_hex,
        public readonly int $sample_addr,
    ) {
    }
}
