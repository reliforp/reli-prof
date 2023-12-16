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

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableMemoryLocation;

class ArrayElementsContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public function __construct(
        public ZendArrayTableMemoryLocation $memory_location,
    ) {
    }

    public function getCount(): int
    {
        return count($this->referencing_contexts);
    }

    public function getElementByKey(int|string $key): ?ReferenceContext
    {
        return $this->referencing_contexts[(string)$key] ?? null;
    }

    public function getLocations(): iterable
    {
        return [$this->memory_location];
    }

    public function getContexts(): iterable
    {
        return [
            '#count' => $this->getCount(),
        ];
    }
}
