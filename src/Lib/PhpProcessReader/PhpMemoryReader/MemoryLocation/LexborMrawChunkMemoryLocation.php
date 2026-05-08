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
 * The data buffer behind a `lexbor_mraw_t.mem.chunk`. Allocated through
 * lexbor's `lexbor_mem_chunk_init`, which is pointed at emalloc by ext/uri's
 * MINIT, so it lives as an LRUN inside the ZendMM main chunk.
 */
final class LexborMrawChunkMemoryLocation extends MemoryLocation
{
}
