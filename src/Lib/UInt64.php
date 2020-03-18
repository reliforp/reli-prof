<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */


namespace PhpProfiler\Lib;

/**
 * Class UInt64
 * @package PhpProfiler\Lib
 */
class UInt64
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
        $hi_hex = str_pad(base_convert($this->hi, 10, 16), 8, '0', STR_PAD_LEFT);
        $lo_hex = str_pad(base_convert($this->lo, 10, 16), 8, '0', STR_PAD_LEFT);
        return base_convert($hi_hex . $lo_hex, 16, 10);
    }
}