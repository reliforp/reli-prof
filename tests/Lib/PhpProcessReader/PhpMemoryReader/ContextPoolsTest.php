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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader;

use Reli\BaseTestCase;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;

class ContextPoolsTest extends BaseTestCase
{
    public function testDrainToAddressMapKeepsNodeIdsStableAcrossAddresses(): void
    {
        $pools = ContextPools::createDefault();

        $context_a = $pools->string_context_pool->getContextForLocation(
            new ZendStringMemoryLocation(0x1000, 16, 1, 0, 'a'),
        );
        $context_b = $pools->string_context_pool->getContextForLocation(
            new ZendStringMemoryLocation(0x2000, 16, 1, 0, 'b'),
        );

        // Emit-state memo now lives on the Context as memo_node_id
        // (see ReferenceContextDefault), not in an external WeakMap.
        $context_a->memo_node_id = 10;
        $context_b->memo_node_id = 20;

        $address_map = [];
        $pools->drainToAddressMap($address_map);

        $this->assertSame(10, $address_map[0x1000]);
        $this->assertSame(20, $address_map[0x2000]);
    }
}
