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

class SentinelContextTest extends BaseTestCase
{
    public function testGetName(): void
    {
        $sentinel = new SentinelContext(42);
        $this->assertSame('SentinelContext', $sentinel->getName());
    }

    public function testNodeIdIsAccessible(): void
    {
        $sentinel = new SentinelContext(99);
        $this->assertSame(99, $sentinel->node_id);
    }

    public function testEmitReferenceEdgeDefaultsToTrue(): void
    {
        $sentinel = new SentinelContext(1);
        $this->assertTrue($sentinel->emit_reference_edge);
    }

    public function testReferenceEmissionCanBeSuppressed(): void
    {
        $sentinel = new SentinelContext(1, false);
        $this->assertFalse($sentinel->emit_reference_edge);
    }

    public function testAddThrowsLogicException(): void
    {
        $sentinel = new SentinelContext(1);
        $this->expectException(\LogicException::class);
        $sentinel->add('child', new SentinelContext(2));
    }

    public function testGetLinksReturnsEmpty(): void
    {
        $sentinel = new SentinelContext(1);
        $this->assertSame([], iterator_to_array($sentinel->getLinks()));
    }

    public function testGetLocationsReturnsEmpty(): void
    {
        $sentinel = new SentinelContext(1);
        $this->assertSame([], iterator_to_array($sentinel->getLocations()));
    }

    public function testGetContextsReturnsEmpty(): void
    {
        $sentinel = new SentinelContext(1);
        $this->assertSame([], iterator_to_array($sentinel->getContexts()));
    }

    public function testReleaseLinksDoesNothing(): void
    {
        $sentinel = new SentinelContext(1);
        $sentinel->releaseLinks();
        $this->assertSame(1, $sentinel->node_id);
    }
}
