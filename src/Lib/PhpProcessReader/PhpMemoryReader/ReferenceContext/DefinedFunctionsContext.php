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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext;

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableMemoryLocation;

class DefinedFunctionsContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public function __construct(
        private ZendArrayMemoryLocation $header_memory_location,
        private ZendArrayTableMemoryLocation $table_memory_location,
    ) {
    }

    public function getLocations(): array
    {
        return [$this->header_memory_location, $this->table_memory_location];
    }
}
