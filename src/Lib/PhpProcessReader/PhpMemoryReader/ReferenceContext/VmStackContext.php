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
 * Root branch holding the linked list of VM stack arenas
 * (`_zend_vm_stack` chain reachable from `EG(vm_stack)`).
 *
 * Each child is a {@see VmStackArenaContext}. Frames in `call_frames`
 * / `memory_limit_call_frames` carry a weak (non-tree) edge into the
 * arena they physically reside in.
 */
final class VmStackContext implements ReferenceContext
{
    use ReferenceContextDefault;

    #[\Override]
    public function getContexts(): iterable
    {
        return [
            '#count' => count($this->referencing_contexts),
        ];
    }
}
