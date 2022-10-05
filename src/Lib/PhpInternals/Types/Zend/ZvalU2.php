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

class ZvalU2
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $next;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $opline_num;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $lineno;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $num_args;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $fe_pos;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $fe_iter_idx;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $access_flags;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $property_guard;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $constant_flags;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $extra;

    public function __construct(
        private CData $cdata
    ) {
        unset($this->next);
        unset($this->opline_num);
        unset($this->lineno);
        unset($this->num_args);
        unset($this->fe_pos);
        unset($this->fe_iter_idx);
        unset($this->access_flags);
        unset($this->property_guard);
        unset($this->constant_flags);
        unset($this->extra);
    }

    public function __get(string $field_name): mixed
    {
        return $this->cdata->$field_name;
    }
}
