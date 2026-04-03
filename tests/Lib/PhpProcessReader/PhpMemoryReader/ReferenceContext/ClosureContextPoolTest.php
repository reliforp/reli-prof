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

class ClosureContextPoolTest extends BaseTestCase
{
    public function testGetContextForUnknownAddressReturnsNull(): void
    {
        $pool = new ClosureContextPool();
        $this->assertNull($pool->getContextForAddress(0x1000));
    }

    public function testRegisterAndRetrieve(): void
    {
        $pool = new ClosureContextPool();
        $context = new ClosureContext();

        $pool->register(0x1000, $context);

        $this->assertSame($context, $pool->getContextForAddress(0x1000));
        $this->assertNull($pool->getContextForAddress(0x2000));
    }

    public function testClearRemovesAllEntries(): void
    {
        $pool = new ClosureContextPool();
        $pool->register(0x1000, new ClosureContext());
        $pool->register(0x2000, new ClosureContext());

        $pool->clear();

        $this->assertNull($pool->getContextForAddress(0x1000));
        $this->assertNull($pool->getContextForAddress(0x2000));
    }

    public function testDrainWithAddressesYieldsAllAndClears(): void
    {
        $pool = new ClosureContextPool();
        $ctx1 = new ClosureContext();
        $ctx2 = new ClosureContext();

        $pool->register(0x1000, $ctx1);
        $pool->register(0x2000, $ctx2);

        $drained = iterator_to_array($pool->drainWithAddresses());

        $this->assertCount(2, $drained);
        $this->assertSame($ctx1, $drained[0x1000]);
        $this->assertSame($ctx2, $drained[0x2000]);

        // Pool should be empty after drain
        $this->assertNull($pool->getContextForAddress(0x1000));
        $this->assertNull($pool->getContextForAddress(0x2000));
    }
}
