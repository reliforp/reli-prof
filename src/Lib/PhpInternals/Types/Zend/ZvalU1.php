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

namespace PhpProfiler\Lib\PhpInternals\Types\Zend;

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
}
