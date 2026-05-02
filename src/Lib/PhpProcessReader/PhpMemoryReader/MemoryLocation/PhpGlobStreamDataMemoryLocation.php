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
 * The php_glob_stream_data (a.k.a. glob_s_t) allocation backing a
 * glob:// stream. The struct begins at php_stream->abstract and its
 * total size depends on which glob implementation PHP was built
 * against:
 *
 *   - 120 bytes for PHP 7.0..8.4 / system glob_t (header 72B,
 *     index/flags 16B, path/pattern tail 32B).
 *   - 168 bytes for PHP 8.5 default build / bundled php_glob_t
 *     (header 96B, index/flags 16B, path/pattern tail 32B,
 *     trailing open_basedir_* fields rounded up for struct
 *     alignment).
 *
 * The exact size is settled at decode time by which tail offset
 * (88 vs 112) passes strlen-vs-len validation; this MemoryLocation
 * is only registered once that probe succeeds.
 */
final class PhpGlobStreamDataMemoryLocation extends MemoryLocation
{
}
