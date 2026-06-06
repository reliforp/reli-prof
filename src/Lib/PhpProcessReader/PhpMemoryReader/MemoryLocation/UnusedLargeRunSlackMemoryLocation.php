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

use Reli\Lib\Process\MemoryLocation;

/**
 * Trailing page-rounding slack on a non-VM-stack ZendMM large run.
 *
 * ZendMM serves allocations bigger than its largest small bin (3 KB on
 * 64-bit PHP 8.x) from contiguous N-page runs, rounded UP to the next
 * 4 KB page boundary. A 5 KB request lands in an 8 KB run, leaving 3 KB
 * that the requester never sees and that the substrate's pointer-tracer
 * therefore never reaches — every byte past the last substrate-covered
 * address within the run is allocator-reserved padding.
 *
 * Mirrors {@see UnusedVmStackMemoryLocation} (which covers the same
 * `end - hwm` shape on VM stack arenas), so callers can rely on the
 * same partitioning invariant for both run kinds:
 *
 *   Σ tracked locations inside the run
 *     + Σ UnusedLargeRunSlackMemoryLocation for the run
 *     = ZendMM's actual run size (page-rounded).
 */
final class UnusedLargeRunSlackMemoryLocation extends MemoryLocation
{
    /**
     * Compute the slack location for a single large run.
     *
     * Returns the location covering `[max(addr + location_sizes[addr] for addr in run), run_end)`,
     * or `null` when the substrate's tracked locations already
     * cover (or overshoot) the run's page-aligned end.
     *
     * `$sorted_addrs` must be sorted ascending; the routine relies
     * on that for the binary-search probe into the tracked-address
     * set. `$location_sizes` is a best-effort `address -> size`
     * map; a missing entry is treated as zero-sized (so it
     * contributes only its address to the coverage scan, never
     * pushes `max_end` past `address`).
     *
     * @param list<int>           $sorted_addrs
     * @param array<int, int>     $location_sizes
     */
    public static function computeForRun(
        int $run_addr,
        int $run_size,
        array $sorted_addrs,
        array $location_sizes,
    ): ?self {
        if ($run_size <= 0) {
            return null;
        }
        $run_end = $run_addr + $run_size;
        $count = count($sorted_addrs);

        // Binary search for the first sorted address >= run_addr.
        $lo = 0;
        $hi = $count - 1;
        $start_idx = $count;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($sorted_addrs[$mid] >= $run_addr) {
                $start_idx = $mid;
                $hi = $mid - 1;
            } else {
                $lo = $mid + 1;
            }
        }

        $max_end = $run_addr;
        for ($i = $start_idx; $i < $count; $i++) {
            $addr = $sorted_addrs[$i];
            if ($addr >= $run_end) {
                break;
            }
            $size = $location_sizes[$addr] ?? 0;
            $end = $addr + $size;
            if ($end > $max_end) {
                $max_end = $end;
            }
        }

        // A stale size could push max_end past the run's end; treat
        // that as "fully covered" rather than synthesising a
        // negative-size slack location.
        if ($max_end >= $run_end) {
            return null;
        }

        return new self($max_end, $run_end - $max_end);
    }
}
