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
 * Represents the emalloc'd backing buffer of an FFI\CData object.
 *
 * When FFI::new() is called with persistent=false (the default), the
 * data buffer is allocated via emalloc and tracked by ZendMM. The
 * collector emits this location as a child of the FFI\CData object
 * so the buffer's size is accounted for in the heap analysis.
 */
final class FfiAllocationMemoryLocation extends MemoryLocation
{
    public function __construct(
        int $address,
        int $size,
        public readonly string $type_name,
    ) {
        parent::__construct($address, $size);
    }
}
