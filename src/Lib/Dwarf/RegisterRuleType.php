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

namespace Reli\Lib\Dwarf;

enum RegisterRuleType
{
    case Undefined;
    case SameValue;
    case Offset;
    case ValOffset;
    case Register;
    case Expression;
    case ValExpression;
}
