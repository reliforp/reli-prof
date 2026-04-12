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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\Job;

use Reli\Lib\PhpInternals\Types\Zend\ZendArray;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableOverheadMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ArrayElementsContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ArrayPossibleOverheadContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Emit a ZendArray header node and push an ArrayElementsIteratorJob for elements.
 * Replaces collectZendArrayPointer + collectZendArray.
 */
final class EmitArrayJob implements CollectorJob
{
    /** @param Pointer<ZendArray> $pointer */
    public function __construct(
        private Pointer $pointer,
        private ?int $parent_node_id,
        private string $link_name,
        private EdgeStrength $edge_strength = EdgeStrength::Strong,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        $address = $this->pointer->address;

        // Dedup
        if ($ctx->memory_locations->has($address)) {
            $existing_node_id = $ctx->address_map[$address] ?? null;
            if ($existing_node_id !== null) {
                if ($this->parent_node_id !== null) {
                    $ctx->sink->emitReference(
                        $existing_node_id,
                        $this->parent_node_id,
                        $this->link_name,
                        $this->edge_strength,
                    );
                }
                return;
            }
            $cached = $ctx->context_pools->array_context_pool->getContextByAddress($address);
            if ($cached !== null) {
                $node_id = $ctx->emitNode($cached, $this->parent_node_id, $this->link_name, $this->edge_strength);
                if ($node_id >= 0) {
                    $ctx->address_map[$address] = $node_id;
                }
                return;
            }
        }

        $array = $ctx->dereferencer->deref($this->pointer);
        $this->processArray($array, $ctx, $queue);
    }

    /**
     * Process a ZendArray that has already been dereferenced.
     * Used by both EmitArrayJob (from pointer) and EmitArrayDirectJob (from value).
     */
    public static function processZendArray(
        ZendArray $array,
        ?int $parent_node_id,
        string $link_name,
        EdgeStrength $edge_strength,
        CollectorContext $ctx,
        JobQueue $queue,
    ): void {
        $array_header_location = ZendArrayMemoryLocation::fromZendArray($array);
        $ctx->memory_locations->add($array_header_location);
        $array_header_context = $ctx->context_pools
            ->array_context_pool
            ->getContextForLocation($array_header_location);

        if (is_null($array->arData)) {
            $node_id = $ctx->emitNode($array_header_context, $parent_node_id, $link_name, $edge_strength);
            if ($node_id >= 0) {
                $ctx->address_map[$array_header_location->address] = $node_id;
            }
            return;
        }

        $array_table_location = ZendArrayTableMemoryLocation::fromZendArray($array);
        $array_table_overhead_location = ZendArrayTableOverheadMemoryLocation::fromZendArrayAndUsedLocation(
            $array,
            $array_table_location,
        );

        $ctx->memory_locations->add($array_table_location);
        $ctx->memory_locations->add($array_table_overhead_location);

        $array_elements_context = new ArrayElementsContext($array_table_location);
        $array_elements_context->setCount($array->nNumOfElements);
        $overhead_context = new ArrayPossibleOverheadContext($array_table_overhead_location);

        $array_header_context->add('possible_unused_area', $overhead_context);
        $array_header_context->add('array_elements', $array_elements_context);

        // Emit the array header node
        $header_node_id = $ctx->emitNode($array_header_context, $parent_node_id, $link_name, $edge_strength);
        if ($header_node_id >= 0) {
            $ctx->address_map[$array_header_location->address] = $header_node_id;
        }

        // Get the node_id for array_elements so children can be attached.
        // See EmitObjectJob for why this reads from getMemoNodeId() now
        // instead of $ctx->memo[$context].
        $raw_elements = $array_elements_context->getMemoNodeId();
        $elements_node_id = $raw_elements === null
            ? null
            : ($raw_elements < 0 ? -$raw_elements - 1 : $raw_elements);

        // Push the iterator job for array elements
        $queue->push(new ArrayElementsIteratorJob(
            $array,
            $elements_node_id,
        ));
    }

    private function processArray(ZendArray $array, CollectorContext $ctx, JobQueue $queue): void
    {
        self::processZendArray(
            $array,
            $this->parent_node_id,
            $this->link_name,
            $this->edge_strength,
            $ctx,
            $queue,
        );
    }
}
