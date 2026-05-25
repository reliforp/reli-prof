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
 * Generic LocationRow for an opaque char*-style buffer of a known
 * length. Used for fields that aren't a zend_string but still
 * allocate `len`-bytes of zend_mm-managed memory — e.g.
 * `phar_archive_data.signature`, the various `char *` paths in
 * phar's module globals, etc. The buffer's `tag` is recorded as the
 * MemoryLocation's debug/source label so it shows up distinctly in
 * the report's per-type breakdown.
 */
final class MallocBufferMemoryLocation extends MemoryLocation
{
    public function __construct(
        int $address,
        int $size,
        /** Free-form label identifying which subsystem owns this
         *  allocation, e.g. `phar.signature`. Surfaces in reports. */
        public readonly string $tag,
    ) {
        parent::__construct($address, $size);
    }
}
