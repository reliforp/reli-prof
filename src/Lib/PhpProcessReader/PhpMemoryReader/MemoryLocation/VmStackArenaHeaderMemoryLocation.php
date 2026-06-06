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
 * The `_zend_vm_stack` struct header at the start of every VM stack
 * arena. Sized at runtime via
 * `ZendTypeReader::sizeOf('struct _zend_vm_stack')` so any future
 * field changes track automatically.
 *
 * With this location attached, an arena's 256 KB partitions as
 *   ArenaHeader (struct) + Σ CallFrameHeader + UnusedVmStack
 * with no overlap.
 */
final class VmStackArenaHeaderMemoryLocation extends MemoryLocation
{
}
