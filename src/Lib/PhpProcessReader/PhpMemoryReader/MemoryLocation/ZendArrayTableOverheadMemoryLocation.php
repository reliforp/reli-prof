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

use Reli\Lib\PhpInternals\Types\Zend\ZendArray;
use Reli\Lib\Process\MemoryLocation;

final class ZendArrayTableOverheadMemoryLocation extends MemoryLocation
{
    public function __construct(
        int $address,
        int $size,
        public ZendArrayTableMemoryLocation $used_location,
    ) {
        parent::__construct($address, $size);
    }

    public static function fromZendArrayAndUsedLocation(
        ZendArray $zend_array,
        ZendArrayTableMemoryLocation $used_location,
    ): self {
        $table_address = $zend_array->getRealTableAddress();
        $unused_region_begin = $table_address + $zend_array->getUsedTableSize();
        return new self(
            $unused_region_begin,
            $zend_array->getTableSize() - $zend_array->getUsedTableSize(),
            $used_location,
        );
    }
}
