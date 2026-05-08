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

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringReservedCapacityMemoryLocation;

final class StringContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public function __construct(
        public ZendStringMemoryLocation $memory_location,
        public ?ZendStringReservedCapacityMemoryLocation $reserved_capacity_location = null,
    ) {
    }

    public function getValue(): string
    {
        return $this->memory_location->value;
    }

    #[\Override]
    public function add(string $link_name, ReferenceContext|int $reference_context): void
    {
        throw new \LogicException("StringContext cannot have reference to another context");
    }

    #[\Override]
    public function getLocations(): iterable
    {
        if ($this->reserved_capacity_location !== null) {
            return [$this->memory_location, $this->reserved_capacity_location];
        }
        return [$this->memory_location];
    }
}
