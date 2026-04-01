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

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendResourceMemoryLocation;

final class ResourceContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public ?string $stream_type_label = null;

    public function __construct(
        public ZendResourceMemoryLocation $memory_location,
    ) {
    }

    #[\Override]
    public function getLocations(): iterable
    {
        return [$this->memory_location];
    }
}
