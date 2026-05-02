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

/**
 * Root context for EG(regular_list) — the active resource list.
 *
 * Like ObjectsStoreContext, edges from this root to individual resources
 * are weak so that resources whose only reachability is via regular_list
 * surface as leak candidates rather than appearing live.
 */
final class RegularListContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public function __construct(
        public ZendArrayTableMemoryLocation $memory_location,
    ) {
    }

    #[\Override]
    public function getLocations(): iterable
    {
        return [$this->memory_location];
    }

    #[\Override]
    public function getContexts(): iterable
    {
        return [
            '#count' => count($this->referencing_contexts),
        ];
    }

    #[\Override]
    public function getLinkStrength(string $link_name): EdgeStrength
    {
        return EdgeStrength::Weak;
    }
}
