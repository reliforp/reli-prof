<?php

/**
 * This file is part of the sj-i/ package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Inspector\Output\TopLike;

final class FunctionEntry
{
    public function __construct(
        public string $name,
        public string $file,
        public int $lineno,
        public int $count_exclusive = 0,
        public int $count_inclusive = 0,
        public int $total_count_exclusive = 0,
        public int $total_count_inclusive = 0,
        public float $percent_exclusive = 0,
    ) {
    }
}
