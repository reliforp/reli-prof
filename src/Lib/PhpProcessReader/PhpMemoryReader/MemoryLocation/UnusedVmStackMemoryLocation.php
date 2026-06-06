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
 * The unused tail of a VM stack arena — the bytes between the arena's
 * current `top` and `end`. The arena was emalloc'd as a whole 256 KB
 * (or whatever ZEND_VM_STACK_PAGE_SIZE evaluates to) block, but only
 * the `arena_base .. top` range is occupied by frames; the rest is
 * reserved allocator space that no zend_execute_data owns.
 *
 * Sized so that
 *   Σ CallFrameHeaderMemoryLocation + Σ UnusedVmStackMemoryLocation
 *   = Σ arena_size
 * across all live VM stack arenas, with no overlap between any pair
 * of locations.
 */
final class UnusedVmStackMemoryLocation extends MemoryLocation
{
}
