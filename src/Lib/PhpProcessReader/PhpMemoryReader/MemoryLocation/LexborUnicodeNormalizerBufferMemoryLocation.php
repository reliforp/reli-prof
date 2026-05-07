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
 * The `lxb_unicode_normalizer_t.buf` array allocated by
 * `lxb_unicode_normalizer_init`. Sized as
 * `4096 * sizeof(lxb_unicode_buffer_t)` = `4096 * 8` = 32768 B exactly,
 * landing as an 8-page LRUN inside the ZendMM main chunk.
 */
final class LexborUnicodeNormalizerBufferMemoryLocation extends MemoryLocation
{
}
