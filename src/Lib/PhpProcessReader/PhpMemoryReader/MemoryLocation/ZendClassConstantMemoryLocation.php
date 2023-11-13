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

use Reli\Lib\PhpInternals\Types\Zend\ZendClassConstant;
use Reli\Lib\Process\MemoryLocation;

class ZendClassConstantMemoryLocation extends MemoryLocation
{
    public static function fromZendClassConstant(
        ZendClassConstant $zend_class_constant
    ): self {
        return new self(
            $zend_class_constant->getPointer()->address,
            $zend_class_constant->getPointer()->size
        );
    }
}
