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
use Reli\Lib\PhpInternals\Types\Php\PhpGlobStreamDataTail;
use Reli\Lib\PhpInternals\Types\Php\PhpNetstreamData;
use Reli\Lib\PhpInternals\Types\Php\PhpStdioStreamData;
use Reli\Lib\PhpInternals\Types\Php\PhpStream;
use Reli\Lib\PhpInternals\Types\Php\PhpStreamMemoryData;
use Reli\Lib\PhpInternals\Types\Php\PhpStreamOps;
use Reli\Lib\PhpInternals\Types\Php\PhpStreamTempData;
use Reli\Lib\PhpInternals\Types\Php\PhpUserstreamData;
use Reli\Lib\PhpInternals\Types\Zend\ZendResource;
use Reli\Lib\PhpInternals\Types\Zend\ZendString;
use Reli\Lib\PhpInternals\Types\Zend\Zval;
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
            $cached = $ctx->context_pools->resource_context_pool->getContextByAddress($address);
            if ($cached !== null) {
                $node_id = $ctx->emitNode($cached, $this->parent_node_id, $this->link_name, $this->edge_strength);
                if ($node_id >= 0) {
                    $ctx->address_map[$address] = $node_id;
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

        // Try to collect stream data (best effort).
        // For userspace streams the embedded zval is returned and dispatched
        // after emitNode so the resulting node_id can parent the zval graph.
        $deferred_userspace_zval = $this->tryCollectStreamData($resource, $ctx, $resource_context);

        $node_id = $ctx->emitNode(
            $resource_context,
            $this->parent_node_id,
            $this->link_name,
            $this->edge_strength,
        );
        if ($node_id >= 0) {
            $ctx->address_map[$address] = $node_id;
        }

        if ($deferred_userspace_zval !== null) {
            $queue->push(new ResolveZvalJob(
                $deferred_userspace_zval,
                $node_id >= 0 ? $node_id : null,
                'stream_userspace_object',
            ));
        }
    }

    private function tryCollectStreamData(
        ZendResource $resource,
        CollectorContext $ctx,
        ResourceContext $resource_context,
    ): ?Zval {
        $ptr_address = $resource->ptr;
        if ($ptr_address === 0) {
            return null;
        }

        try {
            $php_stream_pointer = new Pointer(
                PhpStream::class,
                $ptr_address,
                $ctx->zend_type_reader->sizeOf('php_stream'),
            );
            $php_stream = $ctx->dereferencer->deref($php_stream_pointer);

            if ($php_stream->res !== $this->pointer->address) {
                return null;
            }

            $ops_address = $php_stream->ops;
            if ($ops_address === 0) {
                return null;
            }
            $ops = $ctx->dereferencer->deref(new Pointer(
                PhpStreamOps::class,
                $ops_address,
                $ctx->zend_type_reader->sizeOf('php_stream_ops'),
            ));

            $label_address = $ops->label;
            if ($label_address === 0) {
                return null;
            }
            $label = (string)$ctx->dereferencer->deref(new Pointer(RawString::class, $label_address, 32));
            $resource_context->stream_type_label = $label;

            $orig_path_address = $php_stream->orig_path;
            if ($orig_path_address !== 0) {
                $resource_context->stream_orig_path = (string)$ctx->dereferencer->deref(
                    new Pointer(RawString::class, $orig_path_address, 256),
                );
            }

            if ($label === 'MEMORY') {
                $this->collectMemoryStreamData($php_stream, $ctx, $resource_context);
            } elseif ($label === 'TEMP') {
                $this->collectTempStreamData($php_stream, $ctx, $resource_context);
            } elseif ($label === 'STDIO') {
                $this->collectStdioStreamData($php_stream, $ctx, $resource_context);
            } elseif ($label === 'user-space') {
                return $this->extractUserspaceObjectZval($php_stream, $ctx);
            } elseif (
                $label === 'tcp_socket'
                || $label === 'udp_socket'
                || $label === 'unix_socket'
                || $label === 'udg_socket'
            ) {
                $this->collectSocketStreamData($php_stream, $ctx, $resource_context);
            } elseif ($label === 'glob') {
                $this->collectGlobStreamData($php_stream, $ctx, $resource_context);
            }
        } catch (\Throwable) {
            return null;
        }
        return null;
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

    private function collectStdioStreamData(
        PhpStream $php_stream,
        CollectorContext $ctx,
        ResourceContext $resource_context,
    ): void {
        $abstract_address = $php_stream->abstract;
        if ($abstract_address === 0) {
            return;
        }

        $stdio_data = $ctx->dereferencer->deref(new Pointer(
            PhpStdioStreamData::class,
            $abstract_address,
            $ctx->zend_type_reader->sizeOf('php_stdio_stream_data'),
        ));

        $resource_context->stream_fd = $stdio_data->fd;

        $temp_name_address = $stdio_data->temp_name;
        if ($temp_name_address === 0) {
            return;
        }

        $string_pointer = new Pointer(
            ZendString::class,
            $temp_name_address,
            $ctx->zend_type_reader->sizeOf('zend_string'),
        );

        if (!$ctx->memory_locations->has($temp_name_address)) {
            $str = $ctx->dereferencer->deref($string_pointer);
            $memory_location = ZendStringMemoryLocation::fromZendString($str, $ctx->dereferencer);
            $ctx->memory_locations->add($memory_location);
            $string_context = $ctx->context_pools->string_context_pool->getContextForLocation($memory_location);
            $resource_context->add('stream_temp_name', $string_context);
        } else {
            $cached = $ctx->context_pools->string_context_pool->getContextByAddress($temp_name_address);
            if ($cached !== null) {
                $resource_context->add('stream_temp_name', $cached);
            }
        }
    }

    private function collectSocketStreamData(
        PhpStream $php_stream,
        CollectorContext $ctx,
        ResourceContext $resource_context,
    ): void {
        $abstract_address = $php_stream->abstract;
        if ($abstract_address === 0) {
            return;
        }

        $netstream_data = $ctx->dereferencer->deref(new Pointer(
            PhpNetstreamData::class,
            $abstract_address,
            $ctx->zend_type_reader->sizeOf('php_netstream_data_t'),
        ));

        $resource_context->stream_fd = $netstream_data->socket;
    }

    /**
     * Decode the (path, pattern) pair stored at the tail of
     * php_glob_stream_data.
     *
     * The struct begins with `glob_t` (libc-internal, layout differs between
     * glibc and musl) followed by `size_t index; int flags;`. On 64-bit Linux
     * this puts (path, path_len, pattern, pattern_len) at offset 88, because
     * sizeof(glob_t) == 72 has been stable since glibc 2.10 (2009) and musl
     * 0.5 (2011). _php_stream_opendir does not populate stream->orig_path for
     * the glob wrapper, so this is the only place the pattern is recoverable.
     *
     * Each (ptr, len) pair is validated by checking strlen-vs-len consistency
     * against the bytes actually mapped at the pointer; on any mismatch
     * (other libc, future ABI break) the resource silently degrades to
     * label-only.
     */
    private function collectGlobStreamData(
        PhpStream $php_stream,
        CollectorContext $ctx,
        ResourceContext $resource_context,
    ): void {
        $abstract_address = $php_stream->abstract;
        if ($abstract_address === 0) {
            return;
        }

        $tail_size = $ctx->zend_type_reader->sizeOf('php_glob_stream_data_tail');
        $tail = $ctx->dereferencer->deref(new Pointer(
            PhpGlobStreamDataTail::class,
            $abstract_address + 88,
            $tail_size,
        ));

        $path = $this->validatedGlobString($ctx, $tail->path, $tail->path_len);
        $pattern = $this->validatedGlobString($ctx, $tail->pattern, $tail->pattern_len);
        if ($path === null || $pattern === null) {
            return;
        }

        $resource_context->stream_orig_path = 'glob://' . $path . '/' . $pattern;
    }

    /**
     * Read $len bytes at $address and return the string only when its
     * length matches $len exactly (i.e. the bytes are NUL-free up to
     * $len, with the byte at $len being NUL or end-of-buffer). Returns
     * null on any inconsistency.
     */
    private function validatedGlobString(
        CollectorContext $ctx,
        int $address,
        int $len,
    ): ?string {
        if ($address === 0 || $len <= 0 || $len > 4096) {
            return null;
        }
        try {
            $raw = (string)$ctx->dereferencer->deref(
                new Pointer(RawString::class, $address, $len + 1),
            );
        } catch (\Throwable) {
            return null;
        }
        if (strlen($raw) !== $len) {
            return null;
        }
        return $raw;
    }

    private function extractUserspaceObjectZval(
        PhpStream $php_stream,
        CollectorContext $ctx,
    ): ?Zval {
        $abstract_address = $php_stream->abstract;
        if ($abstract_address === 0) {
            return null;
        }

        $userstream_data = $ctx->dereferencer->deref(new Pointer(
            PhpUserstreamData::class,
            $abstract_address,
            $ctx->zend_type_reader->sizeOf('php_userstream_data_t'),
        ));

        return $userstream_data->object;
    }
}
