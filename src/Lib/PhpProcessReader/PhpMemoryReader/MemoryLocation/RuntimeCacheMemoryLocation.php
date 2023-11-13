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

use Reli\Lib\PhpInternals\Types\Zend\ZendOpArray;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\MemoryLocation;
use Reli\Lib\Process\Pointer\Dereferencer;

class RuntimeCacheMemoryLocation extends MemoryLocation
{
    public static function fromZendOpArray(
        ZendOpArray $zend_op_array,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        int $map_ptr_base,
    ): self {
        $cache_address = $zend_type_reader->resolveMapPtr(
            $map_ptr_base,
            $zend_op_array->getRuntimeCacheAddress(),
            $dereferencer,
        );
        return new self(
            $cache_address,
            $zend_op_array->cache_size,
        );
    }
}
