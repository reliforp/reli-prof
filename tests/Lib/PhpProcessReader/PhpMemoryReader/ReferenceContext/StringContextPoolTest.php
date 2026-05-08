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
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringSlotTailMemoryLocation;

class StringContextPoolTest extends BaseTestCase
{
    private function makeString(int $address = 0x1000): ZendStringMemoryLocation
    {
        return new ZendStringMemoryLocation(
            address: $address,
            size: 27,
            refcount: 1,
            type_info: 0,
            value: 'hi!',
        );
    }

    public function testGetContextForLocationInitialReturnIncludesSlotTail(): void
    {
        $pool = new StringContextPool();
        $string = $this->makeString();
        $slot_tail = new ZendStringSlotTailMemoryLocation(
            $string->address + $string->size,
            5,
            $string,
        );
        $context = $pool->getContextForLocation($string, $slot_tail);
        $this->assertSame($slot_tail, $context->slot_tail_location);
        $this->assertSame(
            [$string, $slot_tail],
            iterator_to_array($context->getLocations(), false),
        );
    }

    public function testGetContextForLocationBackfillsSlotTailOnCachedContext(): void
    {
        // Regression for the review note on PR #785: a caller that
        // populates the pool first without a slot_tail (e.g. an emit
        // site that hasn't yet been updated, or an early helper run
        // before the chunk index is consulted) must NOT permanently
        // strip the slot_tail attribution for a subsequent caller that
        // does have one — the second call should backfill the cached
        // context, otherwise StringContext::getLocations() will keep
        // emitting the bare string row to the sink and the slot tail
        // never reaches the rmem stream.
        $pool = new StringContextPool();
        $string = $this->makeString();
        $first = $pool->getContextForLocation($string);
        $this->assertNull($first->slot_tail_location);

        $slot_tail = new ZendStringSlotTailMemoryLocation(
            $string->address + $string->size,
            5,
            $string,
        );
        $second = $pool->getContextForLocation($string, $slot_tail);

        $this->assertSame($first, $second, 'pool must keep returning the same StringContext');
        $this->assertSame(
            $slot_tail,
            $second->slot_tail_location,
            'slot_tail must be backfilled onto the cached context',
        );
        $this->assertSame(
            [$string, $slot_tail],
            iterator_to_array($second->getLocations(), false),
        );
    }

    public function testBackfillDoesNotOverwriteAnAlreadyAttachedSlotTail(): void
    {
        // Defensive: once slot_tail is attached, a later call with a
        // different (or null) slot_tail must leave the original alone
        // — the slot the string lives in doesn't change between
        // emitters during a single walk, and overwriting risks
        // surprising whoever attached first.
        $pool = new StringContextPool();
        $string = $this->makeString();
        $slot_tail_a = new ZendStringSlotTailMemoryLocation(
            $string->address + $string->size,
            5,
            $string,
        );
        $first = $pool->getContextForLocation($string, $slot_tail_a);

        $slot_tail_b = new ZendStringSlotTailMemoryLocation(
            $string->address + $string->size,
            7,
            $string,
        );
        $second = $pool->getContextForLocation($string, $slot_tail_b);
        $this->assertSame($slot_tail_a, $second->slot_tail_location);

        $third = $pool->getContextForLocation($string, null);
        $this->assertSame($slot_tail_a, $third->slot_tail_location);
    }
}
