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
 * The (path, path_len, pattern, pattern_len) tail of php_glob_stream_data
 * — the only portion of that struct reli decodes. The leading glob_t /
 * php_glob_t header (72 or 96 bytes depending on whether PHP was built
 * --with-system-glob) is not accounted for here; the registered range
 * starts at the tail offset that successfully validated.
 */
final class PhpGlobStreamDataMemoryLocation extends MemoryLocation
{
}
