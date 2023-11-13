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

use Reli\Lib\PhpInternals\Types\Zend\ZendObject;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\MemoryLocation;

class ZendObjectHandlersMemoryLocation extends MemoryLocation
{
    public static function fromZendObject(
        ZendObject $zend_object,
        ZendTypeReader $zend_type_reader,
    ): self {
        return new self(
            $zend_object->getHandlersAddress(),
            $zend_type_reader->sizeOf('zend_object_handlers'),
        );
    }
}
