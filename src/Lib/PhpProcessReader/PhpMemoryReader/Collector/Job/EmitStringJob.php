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

use Reli\Lib\PhpInternals\Types\Zend\ZendString;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringSlotTailMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Emit a ZendString node. Leaf job with no children.
 * Replaces collectZendStringPointer.
 */
final class EmitStringJob implements CollectorJob
{
    /** @param Pointer<ZendString> $pointer */
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

        // Dedup: check if already visited
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
            $cached = $ctx->context_pools->string_context_pool->getContextByAddress($address);
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
            $len = $fp->stringLen($address);
            if ($len !== null) {
                $refcount = $fp->stringRefcount($address) ?? 0;
                $type_info = $fp->stringTypeInfo($address) ?? 0;
                $h = $fp->stringH($address) ?? 0;
                $val = $fp->stringVal($address, $len) ?? '';
                $memory_location = new ZendStringMemoryLocation(
                    $address,
                    $fp->stringHeaderSize() + $len,
                    $refcount,
                    $type_info,
                    $val,
                    $h,
                );
                $ctx->memory_locations->add($memory_location);
                $slot_tail = ZendStringSlotTailMemoryLocation::tryFromStringInChunks(
                    $memory_location,
                    $ctx->chunk_memory_locations,
                );
                if ($slot_tail !== null) {
                    $ctx->memory_locations->add($slot_tail);
                }
                $string_context = $ctx->context_pools
                    ->string_context_pool
                    ->getContextForLocation($memory_location, $slot_tail);
                $node_id = $ctx->emitNode($string_context, $this->parent_node_id, $this->link_name, $this->edge_strength);
                if ($node_id >= 0) {
                    $ctx->address_map[$address] = $node_id;
                }
                return;
            }
        }

        $str = $ctx->dereferencer->deref($this->pointer);
        $memory_location = ZendStringMemoryLocation::fromZendString(
            $str,
            $ctx->dereferencer,
        );
        $ctx->memory_locations->add($memory_location);
        $slot_tail = ZendStringSlotTailMemoryLocation::tryFromStringInChunks(
            $memory_location,
            $ctx->chunk_memory_locations,
        );
        if ($slot_tail !== null) {
            $ctx->memory_locations->add($slot_tail);
        }
        $string_context = $ctx->context_pools
            ->string_context_pool
            ->getContextForLocation($memory_location, $slot_tail);

        $node_id = $ctx->emitNode($string_context, $this->parent_node_id, $this->link_name, $this->edge_strength);
        if ($node_id >= 0) {
            $ctx->address_map[$address] = $node_id;
        }
    }
}
