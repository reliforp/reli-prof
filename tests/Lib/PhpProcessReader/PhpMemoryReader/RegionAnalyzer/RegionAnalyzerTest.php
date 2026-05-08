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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer;

use Reli\BaseTestCase;
use Reli\Lib\Process\MemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MemoryLocations;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendMmChunkMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendMmOverheadMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringSlotTailMemoryLocation;

class RegionAnalyzerTest extends BaseTestCase
{
    private function newAnalyzer(): RegionAnalyzer
    {
        return new RegionAnalyzer(
            new MemoryLocations(),
            new MemoryLocations(),
            new MemoryLocations(),
            new MemoryLocations(),
        );
    }

    public function testReservedCapacityCounterIsAccumulated(): void
    {
        $string = new ZendStringMemoryLocation(
            address: 0x1000,
            size: 27,
            refcount: 1,
            type_info: 0,
            value: 'hi!',
        );
        $reserved = new ZendStringSlotTailMemoryLocation(
            address: 0x1000 + 27,
            size: 5,
            used_location: $string,
        );
        $locations = new MemoryLocations();
        $locations->add($string);
        $locations->add($reserved);

        $result = $this->newAnalyzer()->analyze($locations);

        $arr = $result->summary->toArray();
        $this->assertSame(5, $arr['possible_string_overhead_total']);
    }

    public function testSlotTailPlaceholderDoesNotInflateAllocationOverhead(): void
    {
        // Regression for the review note on PR #785: without the
        // skip-list entry for ZendStringSlotTailMemoryLocation, the
        // generic ZendMmChunkMemoryLocation::getOverhead path treats
        // the placeholder's address as the start of an allocation slot
        // and returns garbage `bin_size − slot_tail.size` bytes
        // attributed to the next slot — i.e. the placeholder INFLATES
        // possible_allocation_overhead_total instead of attributing
        // those bytes to the string. Drive the chunk dispatch with a
        // chunk-shaped test double that always reports a fixed
        // overhead so we can prove the placeholder's bytes never reach
        // the counter.
        $string = new ZendStringMemoryLocation(
            address: 0x1000,
            size: 27,
            refcount: 1,
            type_info: 0,
            value: 'hi!',
        );
        $slot_tail = new ZendStringSlotTailMemoryLocation(
            address: 0x1000 + 27,
            size: 5,
            used_location: $string,
        );
        $chunk = new class (0x0000, 0x4000) extends ZendMmChunkMemoryLocation {
            public int $get_overhead_calls = 0;
            #[\Override]
            public function getOverhead(MemoryLocation $memory_location): ?ZendMmOverheadMemoryLocation
            {
                $this->get_overhead_calls++;
                // Pretend any location lives in a 32-byte bin slot
                // ending at base+32 — same as what a real chunk would
                // return for an overlapping slot.
                return new ZendMmOverheadMemoryLocation(
                    $memory_location->address + $memory_location->size,
                    32 - $memory_location->size,
                );
            }
            #[\Override]
            public function contains(MemoryLocation $memory_location): bool
            {
                return $memory_location->address >= $this->address
                    && $memory_location->address < $this->address + $this->size;
            }
        };
        $chunks = new MemoryLocations();
        $chunks->add($chunk);

        $locations = new MemoryLocations();
        $locations->add($string);
        $locations->add($slot_tail);

        $analyzer = new RegionAnalyzer(
            $chunks,
            new MemoryLocations(),
            new MemoryLocations(),
            new MemoryLocations(),
        );
        $result = $analyzer->analyze($locations);
        $arr = $result->summary->toArray();

        // The string is in the skip-list, so no getOverhead call for
        // it. The slot-tail must also be skipped, so the only
        // surviving contribution to allocation_overhead is zero — the
        // counter must NOT include the bogus `32 − 5 = 27` bytes that
        // the test-double getOverhead would have returned.
        $this->assertSame(
            0,
            $arr['possible_allocation_overhead_total'],
            'slot-tail placeholder must be skipped from generic getOverhead — review of PR #785',
        );
        // The slot-tail is still counted in possible_string_overhead_total.
        $this->assertSame(5, $arr['possible_string_overhead_total']);
    }

    public function testFilterDropsReservedCapacityWhenConcreteOverlaps(): void
    {
        // The string occupies [0x1000, 0x1000+27); its placeholder claims
        // [0x101B, 0x101B+5). An opcache-trim case writes a real object
        // header at 0x101B that overlaps the placeholder. The placeholder
        // must yield to the concrete location, so the string-overhead
        // counter must NOT include the placeholder's bytes.
        //
        // We force the address of the concrete entry to coincide with
        // the placeholder's via a hand-built MemoryLocations bypassing
        // add()'s collision handling — that way we exercise the
        // filterOverlappingLocations path directly.
        $string = new ZendStringMemoryLocation(
            address: 0x1000,
            size: 27,
            refcount: 1,
            type_info: 0,
            value: 'hi!',
        );
        $reserved = new ZendStringSlotTailMemoryLocation(
            address: 0x101B,
            size: 5,
            used_location: $string,
        );
        $object = new ZendObjectMemoryLocation(
            address: 0x101B,
            size: 56,
            refcount: 1,
            type_info: 0,
            class_name: 'stdClass',
        );
        $locations = new MemoryLocations([
            $string,
            $reserved,
            $object,
        ]);

        $result = $this->newAnalyzer()->analyze($locations);

        $arr = $result->summary->toArray();
        $this->assertSame(
            0,
            $arr['possible_string_overhead_total'],
            'reserved-capacity placeholder yielded to overlapping object so its bytes must not be counted',
        );
    }
}
