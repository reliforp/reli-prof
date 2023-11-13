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

class ZendArgInfosMemoryLocation extends MemoryLocation
{
    public static function fromZendOpArray(ZendOpArray $zend_op_array, ZendTypeReader $zend_type_reader): self
    {
        assert(!is_null($zend_op_array->arg_info));
        $begin = $zend_op_array->arg_info;
        $num = $zend_op_array->num_args;
        if ($zend_op_array->hasReturnType($zend_type_reader)) {
            $begin = $zend_op_array->arg_info->indexedAt(-1);
            $num++;
        }
        return new self(
            $begin->address,
            $num * $zend_type_reader->sizeOf('zend_arg_info'),
        );
    }
}
