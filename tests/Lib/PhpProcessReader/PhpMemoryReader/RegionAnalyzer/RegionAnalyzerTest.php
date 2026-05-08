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
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MemoryLocations;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringReservedCapacityMemoryLocation;

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
        $reserved = new ZendStringReservedCapacityMemoryLocation(
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
        $reserved = new ZendStringReservedCapacityMemoryLocation(
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
