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

use PHPUnit\Framework\Attributes\DataProvider;
use Reli\BaseTestCase;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MemoryLocations;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\UnusedLargeRunSlackMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\UnusedVmStackMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\VmStackArenaHeaderMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendMmChunkMemoryLocation;
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

    /**
     * Regression for the review note on PR #785: the slot-tail
     * placeholder must be skipped from
     * {@see \Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendMmChunkMemoryLocation::getOverhead}
     * during {@see RegionAnalyzer::analyze}, otherwise the placeholder's
     * mid-slot address violates getOverhead's "address is at the start
     * of an allocation slot" precondition and contributes a garbage
     * `bin_size − slot_tail.size` overhead booked against the next
     * slot.
     *
     * The skip rule lives in
     * {@see RegionAnalyzer::shouldComputeGenericChunkOverhead} so this
     * test pins it directly. The chunk is left `final` in production
     * code; pinning the rule by stubbing the chunk's getOverhead would
     * have required de-final'ing the production class for test
     * convenience, which is the wrong trade.
     *
     * @return list<array{0: \Reli\Lib\Process\MemoryLocation, 1: bool, 2: string}>
     */
    public static function shouldComputeGenericChunkOverheadCases(): array
    {
        $string = new ZendStringMemoryLocation(
            address: 0x1000,
            size: 27,
            refcount: 1,
            type_info: 0,
            value: 'hi!',
        );
        $object = new ZendObjectMemoryLocation(
            address: 0x2000,
            size: 56,
            refcount: 1,
            type_info: 0,
            class_name: 'stdClass',
        );
        return [
            'ZendString skipped (publishes its own slot tail)' => [
                $string,
                false,
                'string already publishes a ZendStringSlotTail covering the slot beyond len+24',
            ],
            'ZendStringSlotTail skipped (would be mid-slot for getOverhead)' => [
                new ZendStringSlotTailMemoryLocation(
                    address: 0x1000 + 27,
                    size: 5,
                    used_location: $string,
                ),
                false,
                'placeholder address is mid-slot; getOverhead would attribute garbage to the next slot',
            ],
            'ZendArrayTable skipped (parallel to the string case)' => [
                new ZendArrayTableMemoryLocation(0x3000, 64, false),
                false,
                'array publishes its own ZendArrayTableOverhead; getOverhead is special-cased there',
            ],
            'ZendObject computed (regular type, no dedicated tail location)' => [
                $object,
                true,
                'plain heap-resident object goes through generic slot-rounding overhead',
            ],
            'VmStackArenaHeader skipped (mid-slot for getOverhead)' => [
                new VmStackArenaHeaderMemoryLocation(0x4000, 32),
                false,
                'header is a 32 B marker at the start of a 256 KB arena slot;'
                . ' getOverhead would attribute ~256 KB per arena as slot rounding',
            ],
            'UnusedVmStack skipped (already represents slack itself)' => [
                new UnusedVmStackMemoryLocation(0x5000, 1024),
                false,
                'the location IS the unused tail; a second getOverhead pass'
                . ' relative to a mid-arena address is garbage',
            ],
            'UnusedLargeRunSlack skipped (same shape as VmStack tail)' => [
                new UnusedLargeRunSlackMemoryLocation(0x6000, 1024),
                false,
                'the location IS the slack; getOverhead would compute slot'
                . ' rounding relative to a mid-run address',
            ],
        ];
    }

    #[DataProvider('shouldComputeGenericChunkOverheadCases')]
    public function testShouldComputeGenericChunkOverhead(
        \Reli\Lib\Process\MemoryLocation $location,
        bool $expected,
        string $why,
    ): void {
        $this->assertSame(
            $expected,
            RegionAnalyzer::shouldComputeGenericChunkOverhead($location),
            $why,
        );
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
