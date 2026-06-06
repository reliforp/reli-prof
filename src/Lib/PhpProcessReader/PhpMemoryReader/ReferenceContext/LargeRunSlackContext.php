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
 * Root branch collecting per-run trailing page-rounding slack on
 * non-VM-stack ZendMM large runs.
 *
 * VM-stack arenas already get an `UnusedVmStackMemoryLocation` under
 * the `vm_stack` branch, and chunk header pages are allocator
 * bookkeeping (PHP marks them as `LRUN(1)` from `map[0]` rather than
 * user data). Everything else that lands in a large run — big
 * HashTables, opcodes, oversized strings — gets a single
 * `UnusedLargeRunSlackMemoryLocation` per run attached here covering
 * the bytes between the substrate's last tracked location and the
 * page-aligned run boundary.
 *
 * Frames in `call_frames` / `memory_limit_call_frames` do NOT point
 * here — they keep their existing weak `vm_stack_arena` edges into
 * {@see VmStackArenaContext}.
 *
 * The `run_count` is passed in by the collector rather than read
 * from `$referencing_contexts`: the slack locations are attached
 * directly via `$ctx->memory_locations->add(...)` and the
 * `addLocation()` helper, not via `add()`, so the trait-backed slot
 * stays empty and a `count($this->referencing_contexts)` here would
 * always read zero (same reasoning as
 * {@see VmStackContext::__construct()}).
 */
final class LargeRunSlackContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public function __construct(
        public readonly int $run_count = 0,
    ) {
    }

    #[\Override]
    public function getContexts(): iterable
    {
        return [
            '#count' => $this->run_count,
        ];
    }
}
