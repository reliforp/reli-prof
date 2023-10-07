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

namespace Reli\Lib\PhpInternals\Types\Zend;

use FFI\CData;
use FFI\PhpInternals\zval_u1;

class ZvalU1
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $type_info;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $type;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $type_flags;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $extra;

    /** @param zval_u1 $cdata */
    public function __construct(
        private CData $cdata
    ) {
        unset($this->type_info);
        unset($this->type);
        unset($this->type_flags);
        unset($this->extra);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'type_info' => $this->cdata->type_info,
            'type' => $this->cdata->v->type,
            'type_flags' => $this->cdata->v->type_flags,
            'extra' => $this->cdata->v->u->extra,
        };
    }

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
            12 => 'IS_INDIRECT',
            13 => 'IS_PTR',
            default => 'UNKNOWN',
        };
    }
}
