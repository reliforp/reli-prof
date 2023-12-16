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

final class ArrayHeaderContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public function __construct(
        public ZendArrayMemoryLocation $memory_location
    ) {
    }

    public function getElements(): ?ArrayElementsContext
    {
        /** @var ArrayElementsContext|null */
        return $this->referencing_contexts['array_elements'] ?? null;
    }

    public function getElement(int|string $key): ?ReferenceContext
    {
        return $this->getElements()?->getElementByKey($key) ?? null;
    }

    public function getLocations(): iterable
    {
        return [$this->memory_location];
    }
}
