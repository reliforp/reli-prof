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

use Reli\Lib\PhpInternals\Types\Zend\ZendVmStack;
use Reli\Lib\Process\MemoryLocation;

final class VmStackMemoryLocation extends MemoryLocation
{
    public static function fromZendVmStack(
        ZendVmStack $zend_vm_stack
    ): self {
        assert(!is_null($zend_vm_stack->end));
        $begin = $zend_vm_stack->getPointer()->address;
        $end = $zend_vm_stack->end->address;
        return new self(
            $zend_vm_stack->getPointer()->address,
            $end - $begin,
        );
    }
}
