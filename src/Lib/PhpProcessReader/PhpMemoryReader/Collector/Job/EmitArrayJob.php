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

use Reli\Inspector\MemoryDump\FastPath\FastPathReader;
use Reli\Lib\PhpInternals\Types\Zend\Bucket;
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
use Reli\Lib\Process\MemoryLocation;
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

        $fp = $ctx->fast_path;
        if ($fp !== null) {
            $refcount = $fp->arrayRefcount($address);
            if ($refcount !== null) {
                $this->processArrayFastPath($fp, $address, $ctx, $queue);
                return;
            }
        }

        $array = $ctx->dereferencer->deref($this->pointer);
        $this->processArray($array, $ctx, $queue);
    }

    private function processArrayFastPath(
        FastPathReader $fp,
        int $address,
        CollectorContext $ctx,
        JobQueue $queue,
    ): void {
        $refcount = $fp->arrayRefcount($address) ?? 0;
        $type_info = $fp->arrayTypeInfo($address) ?? 0;
        $flags = $fp->arrayFlags($address) ?? 0;
        $ar_data = $fp->arrayArData($address) ?? 0;
        $n_table_mask = $fp->arrayNTableMask($address) ?? 0;
        $n_num_used = $fp->arrayNNumUsed($address) ?? 0;
        $n_num_of_elements = $fp->arrayNNumOfElements($address) ?? 0;
        $n_table_size = $fp->arrayNTableSize($address) ?? 0;

        $array_size = $fp->arraySize();
        $is_packed = (bool)($flags & (1 << 2));
        $is_uninitialized = (bool)($flags & (1 << 3));

        $array_header_location = new ZendArrayMemoryLocation(
            $address,
            $array_size,
            $refcount,
            $type_info,
        );
        $ctx->memory_locations->add($array_header_location);
        $array_header_context = $ctx->context_pools
            ->array_context_pool
            ->getContextForLocation($array_header_location);

        if ($ar_data === 0 || $is_uninitialized) {
            $node_id = $ctx->emitNode(
                $array_header_context,
                $this->parent_node_id,
                $this->link_name,
                $this->edge_strength,
            );
            if ($node_id >= 0) {
                $ctx->address_map[$address] = $node_id;
            }
            return;
        }

        // Compute table addresses and sizes
        $bucket_size = $fp->bucketSize();
        $zval_size = $fp->zvalSize();
        $packed_elem_size = $fp->packedElementSize();
        // nTableMask is stored as a negative uint32 (e.g. 0xFFFFFFF8 for -8).
        // Convert to signed int32 for the hash size calculation.
        if ($n_table_mask > 0x7FFFFFFF) {
            $n_table_mask_signed = $n_table_mask - 0x100000000;
        } else {
            $n_table_mask_signed = $n_table_mask;
        }
        $hash_size = -$n_table_mask_signed * 4; // uint32_t[-nTableMask] before arData
        if ($hash_size < 0) {
            $hash_size = 0;
        }
        $real_table_address = $ar_data - $hash_size;
        $used_data_size = $is_packed
            ? $n_num_used * $packed_elem_size
            : $n_num_used * $bucket_size;
        $used_table_size = $hash_size + $used_data_size;
        $total_data_size = $is_packed
            ? $n_table_size * $packed_elem_size
            : $n_table_size * $bucket_size;
        $total_table_size = $hash_size + $total_data_size;

        $array_table_location = new ZendArrayTableMemoryLocation(
            $real_table_address,
            $used_table_size,
            $is_packed,
        );
        $overhead_size = $total_table_size - $used_table_size;
        $array_table_overhead_location = new ZendArrayTableOverheadMemoryLocation(
            $real_table_address + $used_table_size,
            max(0, $overhead_size),
            $array_table_location,
        );

        $ctx->memory_locations->add($array_table_location);
        $ctx->memory_locations->add($array_table_overhead_location);

        $array_elements_context = new ArrayElementsContext($array_table_location);
        $array_elements_context->setCount($n_num_of_elements);
        $overhead_context = new ArrayPossibleOverheadContext($array_table_overhead_location);

        $array_header_context->add('possible_unused_area', $overhead_context);
        $array_header_context->add('array_elements', $array_elements_context);

        $header_node_id = $ctx->emitNode(
            $array_header_context,
            $this->parent_node_id,
            $this->link_name,
            $this->edge_strength,
        );
        if ($header_node_id >= 0) {
            $ctx->address_map[$address] = $header_node_id;
        }

        $raw_elements = $array_elements_context->getMemoNodeId();
        $elements_node_id = $raw_elements === null
            ? null
            : ($raw_elements < 0 ? -$raw_elements - 1 : $raw_elements);

        $queue->push(new ArrayElementsIteratorFastPathJob(
            $fp,
            $ar_data,
            $n_num_used,
            $is_packed,
            $elements_node_id,
        ));
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
