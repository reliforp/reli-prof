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

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;

final class ObjectContext implements ReferenceContext
{
    use ReferenceContextDefault;

    /** @var list<\Reli\Lib\Process\MemoryLocation> */
    private array $extra_locations = [];

    public function __construct(
        public ZendObjectMemoryLocation $memory_location,
    ) {
    }

    public function addLocation(\Reli\Lib\Process\MemoryLocation $location): void
    {
        $this->extra_locations[] = $location;
    }

    #[\Override]
    public function getLocations(): array
    {
        return array_merge([$this->memory_location], $this->extra_locations);
    }

    #[\Override]
    public function getLinkStrength(string $link_name): EdgeStrength
    {
        return match ($link_name) {
            'object_handlers' => EdgeStrength::Structural,
            default => EdgeStrength::Strong,
        };
    }
}
