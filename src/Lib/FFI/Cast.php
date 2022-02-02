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

namespace PhpProfiler\Lib\FFI;

use FFI\CData;
use FFI\CInteger;
use FFI\CPointer;

class Cast
{
    /** @param CPointer $cdata */
    public static function castPointerToInt(CData &$cdata): int
    {
        /** @var CInteger $casted */
        $casted = \FFI::cast('long', $cdata);
        return $casted->cdata;
    }
}
