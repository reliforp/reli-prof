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

class ZendType
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public ?int $ptr;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $type_mask;

    /** @param \FFI\PhpInternals\zend_type $cdata */
    public function __construct(
        private CData $cdata,
    ) {
        unset($this->ptr);
        unset($this->type_mask);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'ptr' => $this->ptr = $this->cdata->ptr,
            'type_mask' => $this->type_mask = $this->cdata->type_mask,
        };
    }
}
