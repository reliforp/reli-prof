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

namespace PhpProfiler\Lib\Dwarf\CompilationUnit;

final class UnitType
{
    public const DW_UT_compile = 0x01;
    public const DW_UT_type = 0x02;
    public const DW_UT_partial = 0x03;
    public const DW_UT_skeleton = 0x04;
    public const DW_UT_split_compile = 0x05;
    public const DW_UT_split_type = 0x06;
    public const DW_UT_lo_user = 0x080;
    public const DW_UT_hi_user = 0x0ff;
}
