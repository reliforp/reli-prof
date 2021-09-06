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

namespace PhpProfiler\Lib\Dwarf\Expression;

final class Stack
{
    public function __construct(
        private array $stack
    ) {
    }

    public function push($value)
    {
        array_push($this->stack, $value);
    }

    public function pop()
    {
        return array_pop($this->stack);
    }

    public function peakTop()
    {
        return $this->stack[array_key_last($this->stack)];
    }
}
