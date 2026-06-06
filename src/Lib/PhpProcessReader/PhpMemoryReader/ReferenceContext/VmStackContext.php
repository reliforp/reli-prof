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
 *
 * The `arena_count` is passed in by the collector rather than read
 * from `$referencing_contexts`: the children are wired up via
 * `$ctx->emitNode($arena_context, $vm_stack_parent, ...)` instead of
 * `$vm_stack_context->add(...)`, so the trait-backed slot stays
 * empty and a `count($this->referencing_contexts)` here would always
 * read zero.
 */
final class VmStackContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public function __construct(
        public readonly int $arena_count = 0,
    ) {
    }

    #[\Override]
    public function getContexts(): iterable
    {
        return [
            '#count' => $this->arena_count,
        ];
    }
}
