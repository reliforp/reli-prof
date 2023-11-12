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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation;

use Reli\Lib\PhpInternals\Types\Zend\ZendArray;

final class ZendArrayMemoryLocation extends RefcountedMemoryLocation
{
    public static function fromZendArray(ZendArray $zend_array): self
    {
        return new self(
            $zend_array->getPointer()->address,
            $zend_array->getPointer()->size,
            $zend_array->gc->refcount,
            $zend_array->gc->type_info,
        );
    }
}
