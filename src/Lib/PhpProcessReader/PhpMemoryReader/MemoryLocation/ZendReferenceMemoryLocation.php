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

use Reli\Lib\PhpInternals\Types\Zend\ZendReference;

class ZendReferenceMemoryLocation extends RefcountedMemoryLocation
{
    public static function fromZendReference(ZendReference $zend_reference): self
    {
        return new self(
            $zend_reference->getPointer()->address,
            $zend_reference->getPointer()->size,
            $zend_reference->gc->refcount,
            $zend_reference->gc->type_info,
        );
    }
}
