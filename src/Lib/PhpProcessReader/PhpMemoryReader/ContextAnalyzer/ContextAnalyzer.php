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

    /**
     * The $memo parameter is kept for source compatibility with
     * callers that still pass a WeakMap; it's ignored at runtime
     * since emit state lives on the Context objects themselves now.
     *
     * @param \WeakMap<ReferenceContext, int>|null $memo unused; see class docblock
     * @psalm-suppress PossiblyUnusedParam
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
            $this->analyzeContext($linked_context, $link_name, $parent_node_id, $sink, $edge_strength);
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
        /** @psalm-suppress UndefinedPropertyFetch */
        $existing = isset($context->memo_node_id) ? $context->memo_node_id : null;
        if ($existing !== null) {
            return $existing < 0 ? -$existing - 1 : $existing;
        }
        $node_id = $this->node_id++;
        // Store as negative-minus-1 to indicate "reserved but not emitted"
        /** @psalm-suppress UndefinedPropertyAssignment */
        $context->memo_node_id = -$node_id - 1;
        return $node_id;
    }

    /**
     * Emit a single named context and its subtree to the sink.
     * Useful for streaming branches independently while sharing
     * the Context-embedded memo across them.
     *
     * The $memo parameter is ignored; see class docblock.
     *
     * @param \WeakMap<ReferenceContext, int>|null $memo unused
     * @psalm-suppress PossiblyUnusedParam
     */
    public function analyzeSingleLink(
        string $link_name,
        ReferenceContext $context,
        ContextTreeSink $sink,
        ?int $parent_node_id = null,
        ?\WeakMap $memo = null,
        EdgeStrength $edge_strength = EdgeStrength::Strong,
    ): void {
        $this->analyzeContext($context, $link_name, $parent_node_id, $sink, $edge_strength);
    }

    /**
     * @psalm-suppress UndefinedPropertyFetch, UndefinedPropertyAssignment
     */
    private function analyzeContext(
        ReferenceContext|int $linked_context,
        string $link_name,
        ?int $parent_node_id,
        ContextTreeSink $sink,
        EdgeStrength $edge_strength = EdgeStrength::Strong,
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
        // non-hot paths. It doesn't have a memo_node_id because it's
        // never looked up — the streaming collector uses the int
        // placeholder form above for that role.
        if ($linked_context instanceof SentinelContext) {
            if ($linked_context->emit_reference_edge) {
                $sink->emitReference($linked_context->node_id, $parent_node_id, $link_name, $edge_strength);
            }
            return;
        }

        // `isset` safely returns false for unvisited (null value) AND
        // for mock Contexts that don't declare the property at all,
        // without tripping the PHP 8+ "undefined property" warning
        // that `?? null` does.
        $existing_node_id = isset($linked_context->memo_node_id)
            ? $linked_context->memo_node_id
            : null;
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
        $linked_context->memo_node_id = $current_node_id;

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

        $this->analyze($linked_context, $sink, $current_node_id);
    }
}
