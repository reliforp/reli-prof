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

use Reli\Lib\PhpInternals\Types\C\RawString;
use Reli\Lib\PhpInternals\Types\Php\PhpStream;
use Reli\Lib\PhpInternals\Types\Php\PhpStreamMemoryData;
use Reli\Lib\PhpInternals\Types\Php\PhpStreamOps;
use Reli\Lib\PhpInternals\Types\Php\PhpStreamTempData;
use Reli\Lib\PhpInternals\Types\Zend\ZendResource;
use Reli\Lib\PhpInternals\Types\Zend\ZendString;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendResourceMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ResourceContext;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Emit a ZendResource node. Handles stream data collection inline.
 * Replaces collectResourcePointer.
 */
final class EmitResourceJob implements CollectorJob
{
    /** @param Pointer<ZendResource> $pointer */
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
            $existing_node_id = $ctx->address_map->get($address);
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
            $cached = $ctx->context_pools->resource_context_pool->getContextByAddress($address);
            if ($cached !== null) {
                $node_id = $ctx->emitNode($cached, $this->parent_node_id, $this->link_name, $this->edge_strength);
                if ($node_id >= 0) {
                    $ctx->address_map->set($address, $node_id);
                }
                return;
            }
        }

        $resource = $ctx->dereferencer->deref($this->pointer);
        $memory_location = ZendResourceMemoryLocation::fromZendReference($resource);
        $ctx->memory_locations->add($memory_location);
        $resource_context = $ctx->context_pools
            ->resource_context_pool
            ->getContextForLocation($memory_location);

        // Try to collect stream data (best effort)
        $this->tryCollectStreamData($resource, $ctx, $resource_context);

        $node_id = $ctx->emitNode(
            $resource_context,
            $this->parent_node_id,
            $this->link_name,
            $this->edge_strength,
        );
        if ($node_id >= 0) {
            $ctx->address_map->set($address, $node_id);
        }
    }

    private function tryCollectStreamData(
        ZendResource $resource,
        CollectorContext $ctx,
        ResourceContext $resource_context,
    ): void {
        $ptr_address = $resource->ptr;
        if ($ptr_address === 0) {
            return;
        }

        try {
            $php_stream_pointer = new Pointer(
                PhpStream::class,
                $ptr_address,
                $ctx->zend_type_reader->sizeOf('php_stream'),
            );
            $php_stream = $ctx->dereferencer->deref($php_stream_pointer);

            if ($php_stream->res !== $this->pointer->address) {
                return;
            }

            $ops_address = $php_stream->ops;
            if ($ops_address === 0) {
                return;
            }
            $ops = $ctx->dereferencer->deref(new Pointer(
                PhpStreamOps::class,
                $ops_address,
                $ctx->zend_type_reader->sizeOf('php_stream_ops'),
            ));

            $label_address = $ops->label;
            if ($label_address === 0) {
                return;
            }
            $label = (string)$ctx->dereferencer->deref(new Pointer(RawString::class, $label_address, 32));
            $resource_context->stream_type_label = $label;

            if ($label === 'MEMORY') {
                $this->collectMemoryStreamData($php_stream, $ctx, $resource_context);
            } elseif ($label === 'TEMP') {
                $this->collectTempStreamData($php_stream, $ctx, $resource_context);
            }
        } catch (\Throwable) {
            return;
        }
    }

    private function collectMemoryStreamData(
        PhpStream $php_stream,
        CollectorContext $ctx,
        ResourceContext $resource_context,
    ): void {
        $abstract_address = $php_stream->abstract;
        if ($abstract_address === 0) {
            return;
        }

        $mem_data = $ctx->dereferencer->deref(new Pointer(
            PhpStreamMemoryData::class,
            $abstract_address,
            $ctx->zend_type_reader->sizeOf('php_stream_memory_data'),
        ));

        $data_address = $mem_data->data;
        if ($data_address === 0) {
            return;
        }

        $string_pointer = new Pointer(
            ZendString::class,
            $data_address,
            $ctx->zend_type_reader->sizeOf('zend_string'),
        );

        // Collect the string inline (same as EmitStringJob but adds to resource context)
        if (!$ctx->memory_locations->has($data_address)) {
            $str = $ctx->dereferencer->deref($string_pointer);
            $memory_location = ZendStringMemoryLocation::fromZendString($str, $ctx->dereferencer);
            $ctx->memory_locations->add($memory_location);
            $string_context = $ctx->context_pools->string_context_pool->getContextForLocation($memory_location);
            $resource_context->add('stream_memory_data', $string_context);
        } else {
            $cached = $ctx->context_pools->string_context_pool->getContextByAddress($data_address);
            if ($cached !== null) {
                $resource_context->add('stream_memory_data', $cached);
            }
        }
    }

    private function collectTempStreamData(
        PhpStream $php_stream,
        CollectorContext $ctx,
        ResourceContext $resource_context,
    ): void {
        $abstract_address = $php_stream->abstract;
        if ($abstract_address === 0) {
            return;
        }

        $temp_data = $ctx->dereferencer->deref(new Pointer(
            PhpStreamTempData::class,
            $abstract_address,
            $ctx->zend_type_reader->sizeOf('php_stream_temp_data'),
        ));

        $innerstream_address = $temp_data->innerstream;
        if ($innerstream_address === 0) {
            return;
        }

        $inner_stream = $ctx->dereferencer->deref(new Pointer(
            PhpStream::class,
            $innerstream_address,
            $ctx->zend_type_reader->sizeOf('php_stream'),
        ));

        $inner_ops_address = $inner_stream->ops;
        if ($inner_ops_address === 0) {
            return;
        }
        $inner_ops = $ctx->dereferencer->deref(new Pointer(
            PhpStreamOps::class,
            $inner_ops_address,
            $ctx->zend_type_reader->sizeOf('php_stream_ops'),
        ));

        $inner_label_address = $inner_ops->label;
        if ($inner_label_address === 0) {
            return;
        }
        $inner_label = (string)$ctx->dereferencer->deref(new Pointer(RawString::class, $inner_label_address, 32));

        if ($inner_label === 'MEMORY') {
            $this->collectMemoryStreamData($inner_stream, $ctx, $resource_context);
        }
    }
}
