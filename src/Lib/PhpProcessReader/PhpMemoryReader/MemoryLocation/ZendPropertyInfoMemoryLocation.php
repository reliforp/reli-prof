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

use Reli\Lib\PhpInternals\Types\Zend\ZendPropertyInfo;
use Reli\Lib\Process\MemoryLocation;

class ZendPropertyInfoMemoryLocation extends MemoryLocation
{
    public static function fromZendPropertyInfo(ZendPropertyInfo $zend_property_info): self
    {
        return new self(
            $zend_property_info->getPointer()->address,
            $zend_property_info->getPointer()->size,
        );
    }
}
