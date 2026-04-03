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

use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\SentinelContext;
use WeakMap;

final class ContextAnalyzer
{
    private int $node_id = 0;

    /**
     * @param WeakMap<ReferenceContext, int>|null $memo
     */
    public function analyze(
        ReferenceContext $reference_context,
        ContextTreeSink $sink,
        ?int $parent_node_id = null,
        ?WeakMap $memo = null,
    ): void {
        if ($memo === null) {
            /** @var WeakMap<ReferenceContext, int> $memo */
            $memo = new WeakMap();
        }

        foreach ($reference_context->getLinks() as $link_name => $linked_context) {
            /** @psalm-suppress RedundantCastGivenDocblockType -- int keys occur at runtime */
            $link_name = (string)$link_name;
            $this->analyzeContext($linked_context, $link_name, $parent_node_id, $sink, $memo);
        }

        if ($sink->allowsRelease()) {
            $reference_context->releaseLinks();
        }
    }

    /**
     * Pre-assign a node_id to a context and register it in the memo,
     * without emitting it yet. Used by the collector to register parent
     * nodes before their children are collected and emitted.
     *
     * The node_id is stored as negative in the memo to distinguish it
     * from fully-emitted nodes. analyzeContext will detect this and
     * emit the node properly.
     *
     * @param WeakMap<ReferenceContext, int> $memo
     */
    public function assignNodeId(
        ReferenceContext $context,
        WeakMap $memo,
    ): int {
        $existing = $memo[$context] ?? null;
        if ($existing !== null) {
            return $existing < 0 ? -$existing - 1 : $existing;
        }
        $node_id = $this->node_id++;
        // Store as negative-minus-1 to indicate "reserved but not emitted"
        $memo[$context] = -$node_id - 1;
        return $node_id;
    }

    /**
     * Emit a single named context and its subtree to the sink.
     * Useful for streaming branches independently while sharing memo across them.
     *
     * @param WeakMap<ReferenceContext, int> $memo
     */
    public function analyzeSingleLink(
        string $link_name,
        ReferenceContext $context,
        ContextTreeSink $sink,
        ?int $parent_node_id = null,
        ?WeakMap $memo = null,
    ): void {
        if ($memo === null) {
            /** @var WeakMap<ReferenceContext, int> $memo */
            $memo = new WeakMap();
        }
        $this->analyzeContext($context, $link_name, $parent_node_id, $sink, $memo);
    }

    /**
     * @param WeakMap<ReferenceContext, int> $memo
     */
    private function analyzeContext(
        ReferenceContext $linked_context,
        string $link_name,
        ?int $parent_node_id,
        ContextTreeSink $sink,
        WeakMap $memo,
    ): void {
        // SentinelContext: already emitted to DB in a previous branch,
        // just record the reference edge.
        if ($linked_context instanceof SentinelContext) {
            $sink->emitReference($linked_context->node_id, $parent_node_id, $link_name);
            return;
        }

        $existing_node_id = $memo[$linked_context] ?? null;
        if ($existing_node_id !== null && $existing_node_id >= 0) {
            // Fully emitted — just add a reference edge
            $sink->emitReference($existing_node_id, $parent_node_id, $link_name);
            return;
        }

        if ($existing_node_id !== null && $existing_node_id < 0) {
            // Reserved by assignNodeId but not yet emitted — use the reserved id
            $current_node_id = -$existing_node_id - 1;
        } else {
            $current_node_id = $this->node_id++;
        }
        $memo[$linked_context] = $current_node_id;

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
        );

        $this->analyze($linked_context, $sink, $current_node_id, $memo);
    }
}
