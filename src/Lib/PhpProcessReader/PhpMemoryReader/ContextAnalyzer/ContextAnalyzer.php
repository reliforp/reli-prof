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
use WeakMap;

final class ContextAnalyzer
{
    /** Counter for contexts that don't have a stable address. */
    private int $seq_node_id;

    /**
     * @param int $start_node_id       Base for sequential IDs (contexts without an address).
     * @param bool $address_based_ids  When true, use the first MemoryLocation address as node_id
     *                                 for contexts that have locations.  This makes IDs deterministic
     *                                 across parallel workers for the same memory location.
     */
    public function __construct(
        int $start_node_id = 0,
        private bool $address_based_ids = false,
    ) {
        $this->seq_node_id = $start_node_id;
    }

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
            $existing_node_id = $memo[$linked_context] ?? null;
            if ($existing_node_id !== null) {
                $sink->emitReference($existing_node_id, $parent_node_id, $link_name);
                continue;
            }

            $current_node_id = $this->assignNodeId($linked_context);
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

        if ($sink->allowsRelease()) {
            $reference_context->releaseLinks();
        }
    }

    private function assignNodeId(ReferenceContext $context): int
    {
        if ($this->address_based_ids) {
            $locations = $context->getLocations();
            // Use the first location's address as a stable, deterministic ID.
            foreach ($locations as $location) {
                return $location->address;
            }
        }
        // No locations or address_based_ids disabled: fall back to sequential.
        return $this->seq_node_id++;
    }
}
