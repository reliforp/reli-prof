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
use Reli\Lib\Process\MemoryLocation;

class DynamicFuncDefsTableMemoryLocation extends MemoryLocation
{
    public static function fromZendOpArray(ZendOpArray $zend_op_array): self
    {
        assert($zend_op_array->dynamic_func_defs !== null);
        return new self(
            $zend_op_array->dynamic_func_defs->address,
            $zend_op_array->dynamic_func_defs->size,
        );
    }
}
