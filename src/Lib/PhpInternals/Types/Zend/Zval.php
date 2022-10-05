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

namespace PhpProfiler\Lib\PhpInternals\Types\Zend;

use FFI\CData;

class Zval
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendValue $value;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZvalU1 $u1;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZvalU2 $u2;

    /** @param \FFI\PhpInternals\zval $cdata */
    public function __construct(
        private CData $cdata
    ) {
        unset($this->value);
        unset($this->u1);
        unset($this->u2);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'value' => $this->value = new ZendValue($this->cdata->value),
            'u1' => $this->u1 = new ZvalU1($this->cdata->u1),
            'u2' => $this->u2 = new ZvalU2($this->cdata->u2),
        };
    }
}
