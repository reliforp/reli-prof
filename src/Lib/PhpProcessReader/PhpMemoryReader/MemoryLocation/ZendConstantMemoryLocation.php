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

use Reli\Lib\PhpInternals\Types\Zend\ZendConstant;
use Reli\Lib\Process\MemoryLocation;

final class ZendConstantMemoryLocation extends MemoryLocation
{
    public static function fromZendConstant(ZendConstant $zend_constant): self
    {
        return new self(
            $zend_constant->getPointer()->address,
            $zend_constant->getPointer()->size,
        );
    }
}
