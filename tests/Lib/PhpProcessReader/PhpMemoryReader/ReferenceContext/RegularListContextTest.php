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

use Reli\BaseTestCase;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableMemoryLocation;

class RegularListContextTest extends BaseTestCase
{
    private function makeMemoryLocation(): ZendArrayTableMemoryLocation
    {
        return new ZendArrayTableMemoryLocation(0x1000, 256, false);
    }

    public function testGetName(): void
    {
        $context = new RegularListContext($this->makeMemoryLocation());
        $this->assertSame('RegularListContext', $context->getName());
    }

    public function testGetLocationsReturnsTheBackingTable(): void
    {
        $location = $this->makeMemoryLocation();
        $context = new RegularListContext($location);
        $this->assertSame([$location], iterator_to_array($context->getLocations()));
    }

    public function testGetContextsReturnsCount(): void
    {
        $context = new RegularListContext($this->makeMemoryLocation());
        $context->add('1', new SentinelContext(10));
        $context->add('2', new SentinelContext(20));
        $contexts = iterator_to_array($context->getContexts());
        $this->assertSame(2, $contexts['#count']);
    }

    /**
     * Edges out of regular_list must be Weak so resources reachable only
     * through this registry surface as leak candidates instead of looking
     * pinned alive — same reasoning as ObjectsStoreContext.
     */
    public function testGetLinkStrengthIsWeak(): void
    {
        $context = new RegularListContext($this->makeMemoryLocation());
        $this->assertSame(EdgeStrength::Weak, $context->getLinkStrength('1'));
        $this->assertSame(EdgeStrength::Weak, $context->getLinkStrength('any'));
    }
}
