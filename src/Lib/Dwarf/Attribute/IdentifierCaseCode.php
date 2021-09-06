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

namespace PhpProfiler\Lib\Dwarf\Attribute;

final class IdentifierCaseCode
{
    public const DW_ID_case_sensitive = 0x00;
    public const DW_ID_up_case = 0x01;
    public const DW_ID_down_case = 0x02;
    public const DW_ID_case_insensitive = 0x03;
}
