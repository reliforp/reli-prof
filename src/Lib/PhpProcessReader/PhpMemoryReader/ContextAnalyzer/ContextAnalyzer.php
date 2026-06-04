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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer;

use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\SentinelContext;

/**
 * The analyzer used to track visited contexts via a
 * `WeakMap<ReferenceContext, int>` parameter. On big captures the
 * WeakMap lookups showed up as a visible hot spot
 * (analyzer self-time ~4.7% of total analyze wall time), mostly in
 * the spl_object_id / bucket-walk path.
 *
 * The emit-state now lives as a `public ?int $memo_node_id` property
 * directly on the Context (see ReferenceContextDefault), so hot
 * lookups are a single property read. The encoding is the same the
 * WeakMap used to carry:
 *
 *   null    ... not yet visited
 *   >= 0    ... fully emitted, reuse as the node_id for reference edges
 *   < 0     ... reserved via assignNodeId() but not yet emitted,
 *               decoded as `-$memo_node_id - 1`
 *
 * The WeakMap parameter on the public methods is kept for
 * source-compat but ignored.
 */
final class ContextAnalyzer
{
    private int $node_id = 0;

    /** @var (\Closure(int, ReferenceContext): void)|null */
    private ?\Closure $on_node_assigned = null;

    /**
     * Register a callback fired the first time a node_id is assigned to a
     * Context (i.e. when {@see analyzeContext} promotes a "reserved" or
     * fresh Context to a fully-emitted one). The callback receives the
     * node_id and the Context itself, and is the natural seam for keeping
     * an external address→node_id map in sync with the analyzer's own
     * traversal — Context already carries its primary locations via
     * `getLocations()`, so callers no longer have to remember to write
     * an address_map entry by hand at every emission site.
     *
     * @param \Closure(int, ReferenceContext): void $on_node_assigned
     */
    public function setOnNodeAssigned(\Closure $on_node_assigned): void
    {
        $this->on_node_assigned = $on_node_assigned;
    }

    /**
     * The $memo parameter is the legacy WeakMap-based emit state.
     * Production callers (CollectorContext) pass null and the
     * analyzer uses the Context-embedded `$memo_node_id` property
     * instead. When a non-null WeakMap IS passed, it gets mirror-
     * populated alongside the property write so test fixtures and
     * any external caller using the old API continue to see the
     * emit state.
     *
     * @param \WeakMap<ReferenceContext, int>|null $memo optional
     *     WeakMap mirror; if non-null, the analyzer writes to it
     *     in addition to `$context->memo_node_id`.
     */
    public function analyze(
        ReferenceContext $reference_context,
        ContextTreeSink $sink,
        ?int $parent_node_id = null,
        ?\WeakMap $memo = null,
    ): void {
        foreach ($reference_context->getLinks() as $link_name => $linked_context) {
            /** @psalm-suppress RedundantCastGivenDocblockType -- int keys occur at runtime */
            $link_name = (string)$link_name;
            $edge_strength = $reference_context->getLinkStrength($link_name);
            $this->analyzeContext($linked_context, $link_name, $parent_node_id, $sink, $edge_strength, $memo);
        }

        if ($sink->allowsRelease()) {
            $reference_context->releaseLinks();
        }
    }

    /**
     * Pre-assign a node_id to a context without emitting it yet.
     * Used by the collector to register parent nodes before their
     * children are collected and emitted.
     *
     * The node_id is stored as a negative sentinel in the Context's
     * $memo_node_id so analyzeContext can tell "reserved but not yet
     * emitted" apart from "fully emitted".
     *
     * The $memo parameter is ignored; see class docblock.
     *
     * @param \WeakMap<ReferenceContext, int>|null $memo unused
     * @psalm-suppress PossiblyUnusedParam
     */
    public function assignNodeId(
        ReferenceContext $context,
        ?\WeakMap $memo = null,
    ): int {
        $existing = $context->getMemoNodeId();
        if ($existing !== null) {
            return $existing < 0 ? -$existing - 1 : $existing;
        }
        $node_id = $this->node_id++;
        // Store as negative-minus-1 to indicate "reserved but not emitted"
        $context->setMemoNodeId(-$node_id - 1);
        if ($memo !== null) {
            $memo[$context] = -$node_id - 1;
        }
        return $node_id;
    }

    /**
     * Emit a single named context and its subtree to the sink.
     * Useful for streaming branches independently while sharing
     * the Context-embedded memo across them.
     *
     * @param \WeakMap<ReferenceContext, int>|null $memo optional
     *     legacy WeakMap mirror; see analyze() for the contract.
     */
    public function analyzeSingleLink(
        string $link_name,
        ReferenceContext $context,
        ContextTreeSink $sink,
        ?int $parent_node_id = null,
        ?\WeakMap $memo = null,
        EdgeStrength $edge_strength = EdgeStrength::Strong,
    ): void {
        $this->analyzeContext($context, $link_name, $parent_node_id, $sink, $edge_strength, $memo);
    }

    /**
     * @param \WeakMap<ReferenceContext, int>|null $memo optional
     *     legacy WeakMap mirror; see analyze() for the contract.
     */
    private function analyzeContext(
        ReferenceContext|int $linked_context,
        string $link_name,
        ?int $parent_node_id,
        ContextTreeSink $sink,
        EdgeStrength $edge_strength = EdgeStrength::Strong,
        ?\WeakMap $memo = null,
    ): void {
        if (is_int($linked_context)) {
            // Encoded node_id placeholder:
            //   >= 0 ... node already emitted elsewhere, emit a reference edge
            //   < 0  ... node and incoming edge already emitted, skip
            if ($linked_context >= 0) {
                $sink->emitReference($linked_context, $parent_node_id, $link_name, $edge_strength);
            }
            return;
        }

        // SentinelContext is retained for compatibility in tests and
        // non-hot paths. It carries its own node_id directly and
        // never participates in the memo lookup below — getMemoNodeId
        // on a sentinel returns null, but the analyzer short-circuits
        // here before asking, so the sentinel branch is the
        // canonical path for already-emitted nodes during streaming.
        if ($linked_context instanceof SentinelContext) {
            if ($linked_context->emit_reference_edge) {
                $sink->emitReference($linked_context->node_id, $parent_node_id, $link_name, $edge_strength);
            }
            return;
        }

        $existing_node_id = $linked_context->getMemoNodeId();
        // If the test/legacy caller passed a WeakMap and the memo
        // hasn't been populated on the Context itself, fall back to
        // the WeakMap. This makes the analyzer tolerant of mock
        // Contexts that don't track memo state.
        if ($existing_node_id === null && $memo !== null && isset($memo[$linked_context])) {
            $existing_node_id = $memo[$linked_context];
        }
        if ($existing_node_id !== null && $existing_node_id >= 0) {
            // Fully emitted — just add a reference edge
            $sink->emitReference($existing_node_id, $parent_node_id, $link_name, $edge_strength);
            return;
        }

        if ($existing_node_id !== null && $existing_node_id < 0) {
            // Reserved by assignNodeId but not yet emitted — use the reserved id
            $current_node_id = -$existing_node_id - 1;
        } else {
            $current_node_id = $this->node_id++;
        }
        $linked_context->setMemoNodeId($current_node_id);
        if ($this->on_node_assigned !== null) {
            ($this->on_node_assigned)($current_node_id, $linked_context);
        }
        if ($memo !== null) {
            $memo[$linked_context] = $current_node_id;
        }

        $contexts = $linked_context->getContexts();
        if (!is_array($contexts)) {
            $contexts = iterator_to_array($contexts);
        }
        /** @var array<string, mixed> $contexts */

        $sink->emitNode(
            $current_node_id,
            $parent_node_id,
            $link_name,
            $linked_context->getName(),
            $linked_context->getLocations(),
            $contexts,
            $edge_strength,
        );

        $this->analyze($linked_context, $sink, $current_node_id, $memo);
    }
}
