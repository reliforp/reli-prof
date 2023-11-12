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

namespace Reli\Lib\PhpInternals\Types\Zend\V73;

use FFI\CData;
use FFI\PhpInternals\zval_u1;
use Reli\Lib\PhpInternals\Types\Zend\ZvalU1 as BaseZvalU1;

final class ZvalU1 extends BaseZvalU1
{
    public function getType(): string
    {
        return match ($this->type) {
            0 => 'IS_UNDEF',
            1 => 'IS_NULL',
            2 => 'IS_FALSE',
            3 => 'IS_TRUE',
            4 => 'IS_LONG',
            5 => 'IS_DOUBLE',
            6 => 'IS_STRING',
            7 => 'IS_ARRAY',
            8 => 'IS_OBJECT',
            9 => 'IS_RESOURCE',
            10 => 'IS_REFERENCE',
            11 => 'IS_CONSTANT_AST',
            13 => 'IS_INDIRECT',
            14 => 'IS_PTR',
            default => 'UNKNOWN',
        };
    }
}
