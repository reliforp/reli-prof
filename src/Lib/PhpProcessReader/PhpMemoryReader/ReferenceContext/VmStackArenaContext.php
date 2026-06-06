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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext;

/**
 * One `_zend_vm_stack` arena (an emalloc'd block of
 * ZEND_VM_STACK_PAGE_SIZE bytes that holds zero or more
 * zend_execute_data frames).
 *
 * Frames live in the {@see CallFramesContext} branch — each frame
 * gets a CallFrameHeaderMemoryLocation sized to the bytes that
 * specific frame occupies. The arena itself carries only the
 * leftover slack (`UnusedVmStackMemoryLocation`, sized
 * `end - top`), so per-frame and per-arena sizes partition the
 * arena cleanly with no double counting.
 *
 * The link from a frame to its arena is a weak edge labelled
 * `vm_stack_arena`, emitted by EmitCallFrameJob once the arena
 * tree is in place.
 */
final class VmStackArenaContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public function __construct(
        public readonly int $address,
        public readonly int $size,
        public readonly int $used,
        public readonly int $slack,
    ) {
    }

    #[\Override]
    public function getContexts(): iterable
    {
        return [
            'address' => $this->address,
            'size' => $this->size,
            'used' => $this->used,
            'slack' => $this->slack,
        ];
    }
}
