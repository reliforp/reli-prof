<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=0);

namespace PhpProfiler\Lib;

/**
 * Class PhpCast
 *
 * This file is declared as strict_types=0.
 * So always the weak mode type checking -- with implicit cast in the PHP manner -- is performed on return type,
 * regardless of the caller mode.
 * Normal cast like (int)$str is a bit dangerous, because $str may contain non-numeric string, and it doesn't notice it.
 * If you use PhpCast::anyToInt('abc'), a TypeError is thrown, but PhpCast::anyToInt('123') is OK.
 *
 * @package PhpProfiler\Lib
 */
final class PhpCast
{
    /**
     * @param mixed $str
     * @return int
     */
    public static function anyToInt($str): int
    {
        /** @var int */
        return $str;
    }
}
