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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector;

use Reli\Inspector\MemoryDump\FastPath\FastPathReader;
use Reli\Inspector\Settings\MemoryProfilerSettings\MemoryLimitErrorDetails;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ContextAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextPools;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MemoryLocations;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\UserFunctionDefinitionContext;
use Reli\Lib\Process\Pointer\Dereferencer;

final class CollectorContext
{
    /** @var array<int, int> address -> node_id */
    public array $address_map = [];

    /** Tracks the function context matching a memory limit error location */
    public ?UserFunctionDefinitionContext $memory_limit_error_function_context = null;

    /**
     * Address-range lookup for VM stack arenas, used by EmitCallFrameJob
     * to attach a weak `vm_stack_arena` edge from each frame to the
     * arena it physically resides in. Populated by collectAll() before
     * any EmitCallFrameJob runs; sorted by `begin` so a linear scan
     * is fine for the (tens to a few hundred) arenas a typical
     * process keeps live.
     *
     * @var list<array{int, int, int}> list of [arena_begin, arena_end, arena_node_id]
     */
    public array $vm_stack_arena_lookup = [];

    /**
     * Address -> size of every MemoryLocation written through a
     * `setOnNodeAssigned` callback. Used by the post-DFS large-run
     * slack pass to compute, per ZendMM large run, the highest
     * substrate-covered end byte; the slack location attached to
     * {@see LargeRunSlackContext} covers everything past that.
     *
     * The map intentionally lives alongside `address_map`: not every
     * direct `address_map[$addr] = $node_id` write has a known
     * location size at the call site (aliases, deferred placements,
     * sub-allocations), so this map is "best effort, monotonic" —
     * a missing entry just means the slack pass treats that address
     * as zero-sized and the slack region grows downward to cover it.
     *
     * @var array<int, int>
     */
    public array $location_sizes = [];

    public function __construct(
        public Dereferencer $dereferencer,
        public ZendTypeReader $zend_type_reader,
        public ContextTreeSink $sink,
        public ContextAnalyzer $analyzer,
        public MemoryLocations $memory_locations,
        public ContextPools $context_pools,
        public int $map_ptr_base,
        public ?MemoryLimitErrorDetails $memory_limit_error_details,
        public ?MemoryLocations $fiber_vm_stack_memory_locations = null,
        public ?MemoryLocations $chunk_memory_locations = null,
        public ?FastPathReader $fast_path = null,
    ) {
    }

    public function isVisited(int $address): bool
    {
        return isset($this->address_map[$address]);
    }

    public function getNodeId(int $address): ?int
    {
        return $this->address_map[$address] ?? null;
    }

    public function recordNode(int $address, int $node_id): void
    {
        $this->address_map[$address] = $node_id;
    }

    /**
     * Emit a node to the sink and record its address->node_id mapping.
     * Returns the assigned node_id, or -1 if assignment failed.
     */
    public function emitNode(
        ReferenceContext $context,
        ?int $parent_node_id,
        string $link_name,
        EdgeStrength $edge_strength = EdgeStrength::Strong,
    ): int {
        $this->analyzer->analyzeSingleLink(
            $link_name,
            $context,
            $this->sink,
            $parent_node_id,
            null,
            $edge_strength,
        );
        $node_id = $context->getMemoNodeId();
        if ($node_id !== null) {
            return $node_id < 0 ? -$node_id - 1 : $node_id;
        }
        return -1;
    }

    /**
     * Check if an address has been visited (in memory_locations or address_map).
     * If visited, emit a reference edge and return true.
     * Otherwise return false.
     */
    public function checkVisitedAndEmitReference(
        int $address,
        ?int $parent_node_id,
        string $link_name,
        EdgeStrength $edge_strength = EdgeStrength::Strong,
    ): bool {
        if (!$this->memory_locations->has($address)) {
            return false;
        }
        $existing_node_id = $this->address_map[$address] ?? null;
        if ($existing_node_id !== null) {
            if ($parent_node_id !== null) {
                $this->sink->emitReference($existing_node_id, $parent_node_id, $link_name, $edge_strength);
            }
            return true;
        }
        // Address is in memory_locations but not yet in address_map:
        // still "visited" for dedup via context pools, but we can't emit a reference
        // edge yet. For the iterative design, we treat this as visited.
        return false;
    }

    /**
     * Register a context's node_id in the address_map after it has been emitted.
     */
    public function recordContextAddress(int $address, ReferenceContext $context): void
    {
        $node_id = $context->getMemoNodeId();
        if ($node_id !== null) {
            $this->address_map[$address] = $node_id < 0 ? -$node_id - 1 : $node_id;
        }
    }
}
