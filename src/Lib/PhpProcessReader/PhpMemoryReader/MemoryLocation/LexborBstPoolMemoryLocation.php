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
 * The data buffer of a `lexbor_dobject_t.mem.chunk`. Used as the BST entry
 * pool inside `lexbor_mraw_t.cache`. Sized as
 * `chunk_size * sizeof(lexbor_bst_entry_t)` (default 512 * 48 = 24576 B).
 */
final class LexborBstPoolMemoryLocation extends MemoryLocation
{
}
