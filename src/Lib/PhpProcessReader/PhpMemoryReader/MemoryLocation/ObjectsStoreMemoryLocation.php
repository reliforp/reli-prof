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

use Reli\Lib\PhpInternals\Types\Zend\ZendObjectsStore;
use Reli\Lib\Process\MemoryLocation;

class ObjectsStoreMemoryLocation extends MemoryLocation
{
    public static function fromZendObjectsStore(ZendObjectsStore $zend_objects_store): self
    {
        assert($zend_objects_store->object_buckets !== null);
        return new self(
            $zend_objects_store->object_buckets->address,
            $zend_objects_store->object_buckets->size,
        );
    }
}
