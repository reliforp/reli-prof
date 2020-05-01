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

namespace PhpProfiler\Lib;

/**
 * Class UInt64
 * @package PhpProfiler\Lib
 */
final class UInt64
{
    public int $hi;
    public int $lo;

    /**
     * UInt64 constructor.
     * @param int $hi
     * @param int $lo
     */
    public function __construct(int $hi, int $lo)
    {
        $this->hi = $hi;
        $this->lo = $lo;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        $hi_hex = str_pad(base_convert((string)$this->hi, 10, 16), 8, '0', STR_PAD_LEFT);
        $lo_hex = str_pad(base_convert((string)$this->lo, 10, 16), 8, '0', STR_PAD_LEFT);
        return base_convert($hi_hex . $lo_hex, 16, 10);
    }

    /**
     * do the wrong thing
     *
     * @return int
     */
    public function toInt(): int
    {
        return (int)(string)$this;
    }

    /**
     * @param int $bit_pos
     * @return bool
     */
    public function checkBitSet(int $bit_pos): bool
    {
        $binary = str_pad(base_convert((string)$this, 10, 2), 64, '0', STR_PAD_LEFT);
        return (bool)strrev($binary)[$bit_pos];
    }
}
