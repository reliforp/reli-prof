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

use Reli\Lib\PhpInternals\Types\Zend\ZendExecuteData;
use Reli\Lib\Process\MemoryLocation;
use Reli\Lib\Process\Pointer\Dereferencer;

final class CallFrameVariableTableMemoryLocation extends MemoryLocation
{
    public static function fromZendExecuteData(ZendExecuteData $zend_execute_data, Dereferencer $dereferencer): self
    {
        $variable_table_pointer = $zend_execute_data->getVariableTablePointer($dereferencer);
        return new self(
            $variable_table_pointer->address,
            $variable_table_pointer->size,
        );
    }
}
