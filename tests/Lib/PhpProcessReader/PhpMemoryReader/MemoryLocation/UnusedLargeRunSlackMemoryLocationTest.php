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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation;

use PHPUnit\Framework\TestCase;

/**
 * Covers {@see UnusedLargeRunSlackMemoryLocation::computeForRun()}.
 *
 * The routine is the per-run kernel of the post-DFS slack pass that
 * closes a substantial fraction of the previously-unattributed bytes
 * on the api-platform/core#8036 dump. It must:
 *
 *   - return `null` for any run already fully covered by substrate
 *     locations (avoid synthesising zero / negative-size locations);
 *   - return a single location for the trailing region of a
 *     partially-covered run;
 *   - return a location covering the entire run when no substrate
 *     address falls inside (the fully-orphan case the
 *     ObjectsStore tail and the 64 KB pre-allocated zend_string
 *     surface as);
 *   - ignore substrate addresses that lie outside the run (the
 *     binary-search prefix scan must not bleed across runs);
 *   - tolerate stale `location_sizes` entries that overshoot the
 *     run's end without producing a negative-size slack.
 */
class UnusedLargeRunSlackMemoryLocationTest extends TestCase
{
    public function testEmptyRunReturnsNull(): void
    {
        $this->assertNull(
            UnusedLargeRunSlackMemoryLocation::computeForRun(0x1000, 0, [], []),
        );
        $this->assertNull(
            UnusedLargeRunSlackMemoryLocation::computeForRun(0x1000, -1, [], []),
        );
    }

    public function testFullyOrphanRunGetsWholeRangeAsSlack(): void
    {
        $slack = UnusedLargeRunSlackMemoryLocation::computeForRun(
            run_addr: 0x10000,
            run_size: 4096,
            sorted_addrs: [],
            location_sizes: [],
        );
        $this->assertNotNull($slack);
        $this->assertSame(0x10000, $slack->address);
        $this->assertSame(4096, $slack->size);
    }

    public function testTrailingSlackAfterPartialCoverage(): void
    {
        // Substrate covers [run, run + 3000); the run is 4 KB, so
        // 1096 B of trailing slack should land just past the
        // covered end.
        $slack = UnusedLargeRunSlackMemoryLocation::computeForRun(
            run_addr: 0x10000,
            run_size: 4096,
            sorted_addrs: [0x10000],
            location_sizes: [0x10000 => 3000],
        );
        $this->assertNotNull($slack);
        $this->assertSame(0x10000 + 3000, $slack->address);
        $this->assertSame(4096 - 3000, $slack->size);
    }

    public function testMultipleCoveredAddressesUseFurthestEnd(): void
    {
        // Two overlapping-or-adjacent locations within the run:
        // the slack must start at the FURTHER end, not the last
        // one's begin.
        $slack = UnusedLargeRunSlackMemoryLocation::computeForRun(
            run_addr: 0x10000,
            run_size: 8192,
            sorted_addrs: [0x10000, 0x11000, 0x11800],
            location_sizes: [
                0x10000 => 256,    // ends at 0x10100
                0x11000 => 100,    // ends at 0x11064
                0x11800 => 1024,   // ends at 0x11c00 ← max
            ],
        );
        $this->assertNotNull($slack);
        $this->assertSame(0x11c00, $slack->address);
        $this->assertSame(0x12000 - 0x11c00, $slack->size);
    }

    public function testFullyCoveredRunReturnsNull(): void
    {
        // Substrate covers exactly [run, run_end).
        $this->assertNull(
            UnusedLargeRunSlackMemoryLocation::computeForRun(
                run_addr: 0x10000,
                run_size: 4096,
                sorted_addrs: [0x10000],
                location_sizes: [0x10000 => 4096],
            ),
        );
    }

    public function testOvershootingSizeStillReturnsNull(): void
    {
        // A stale size pushes max_end past the run's end; the
        // clamp must turn that into "fully covered" rather than
        // emitting a negative-size slack.
        $this->assertNull(
            UnusedLargeRunSlackMemoryLocation::computeForRun(
                run_addr: 0x10000,
                run_size: 4096,
                sorted_addrs: [0x10000],
                location_sizes: [0x10000 => 1 << 30],
            ),
        );
    }

    public function testAddressesOutsideRunAreIgnored(): void
    {
        // Addresses well below and well above the run must not
        // confuse the scan — the slack should be the full run.
        $slack = UnusedLargeRunSlackMemoryLocation::computeForRun(
            run_addr: 0x10000,
            run_size: 4096,
            sorted_addrs: [0x100, 0x500, 0x20000, 0x30000],
            location_sizes: [
                0x100 => 100,
                0x500 => 100,
                0x20000 => 100,
                0x30000 => 100,
            ],
        );
        $this->assertNotNull($slack);
        $this->assertSame(0x10000, $slack->address);
        $this->assertSame(4096, $slack->size);
    }

    public function testMissingSizeEntryTreatedAsZero(): void
    {
        // An address that's in the sorted list but missing from
        // `location_sizes` (e.g. an alias `address_map` write that
        // bypassed `setOnNodeAssigned`) contributes only its
        // address to the scan, never pushes `max_end` past it.
        $slack = UnusedLargeRunSlackMemoryLocation::computeForRun(
            run_addr: 0x10000,
            run_size: 4096,
            sorted_addrs: [0x10000, 0x10800],
            location_sizes: [
                // 0x10000 missing — treated as size 0 → end at 0x10000.
                0x10800 => 200,
            ],
        );
        $this->assertNotNull($slack);
        $this->assertSame(0x10800 + 200, $slack->address);
        $this->assertSame(4096 - (0x10800 - 0x10000) - 200, $slack->size);
    }

    public function testLocationStraddlingRunEndIsCappedByClamp(): void
    {
        // A covered location that extends past the run's end is
        // also a stale-size case (a single allocation cannot span
        // two ZendMM runs). The clamp swallows it.
        $this->assertNull(
            UnusedLargeRunSlackMemoryLocation::computeForRun(
                run_addr: 0x10000,
                run_size: 4096,
                sorted_addrs: [0x10800],
                location_sizes: [0x10800 => 8192],
            ),
        );
    }
}
