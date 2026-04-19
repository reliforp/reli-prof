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

use Reli\Lib\PhpInternals\CastedCData;

final class ZendRefcountedH
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $refcount;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $type_info;

    /** @param CastedCData<\FFI\PhpInternals\zend_refcounted_h> $cdata */
    public function __construct(
        private CastedCData $cdata,
    ) {
        unset($this->refcount);
        unset($this->type_info);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'refcount' => $this->cdata->casted->refcount,
            'type_info' => $this->cdata->casted->u->type_info,
        };
    }
}
