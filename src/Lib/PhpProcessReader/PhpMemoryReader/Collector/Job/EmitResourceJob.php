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
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\PhpGlobStreamDataMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\PhpNetstreamDataMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\PhpStdioStreamDataMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\PhpStreamMemoryDataMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\PhpStreamMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\PhpStreamTempDataMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\PhpUserstreamDataMemoryLocation;
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
            $php_stream_size = $ctx->zend_type_reader->sizeOf('php_stream');
            $php_stream = $ctx->dereferencer->deref(new Pointer(
                PhpStream::class,
                $ptr_address,
                $php_stream_size,
            ));

            if ($php_stream->res !== $this->pointer->address) {
                return null;
            }

            $stream_location = new PhpStreamMemoryLocation($ptr_address, $php_stream_size);
            $ctx->memory_locations->add($stream_location);
            $resource_context->addLocation($stream_location);

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
                    new Pointer(RawString::class, $orig_path_address, 4096),
                );
            }

            if ($label === 'MEMORY') {
                $this->collectMemoryStreamData($php_stream, $ctx, $resource_context);
            } elseif ($label === 'TEMP') {
                $this->collectTempStreamData($php_stream, $ctx, $resource_context);
            } elseif ($label === 'STDIO') {
                $this->collectStdioStreamData($php_stream, $ctx, $resource_context);
            } elseif ($label === 'user-space') {
                return $this->extractUserspaceObjectZval($php_stream, $ctx, $resource_context);
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

        $size = $ctx->zend_type_reader->sizeOf('php_stream_memory_data');
        $mem_data = $ctx->dereferencer->deref(new Pointer(
            PhpStreamMemoryData::class,
            $abstract_address,
            $size,
        ));
        $abstract_location = new PhpStreamMemoryDataMemoryLocation($abstract_address, $size);
        $ctx->memory_locations->add($abstract_location);
        $resource_context->addLocation($abstract_location);

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

        $temp_size = $ctx->zend_type_reader->sizeOf('php_stream_temp_data');
        $temp_data = $ctx->dereferencer->deref(new Pointer(
            PhpStreamTempData::class,
            $abstract_address,
            $temp_size,
        ));
        $temp_location = new PhpStreamTempDataMemoryLocation($abstract_address, $temp_size);
        $ctx->memory_locations->add($temp_location);
        $resource_context->addLocation($temp_location);

        $innerstream_address = $temp_data->innerstream;
        if ($innerstream_address === 0) {
            return;
        }

        $inner_stream_size = $ctx->zend_type_reader->sizeOf('php_stream');
        $inner_stream = $ctx->dereferencer->deref(new Pointer(
            PhpStream::class,
            $innerstream_address,
            $inner_stream_size,
        ));
        $inner_stream_location = new PhpStreamMemoryLocation($innerstream_address, $inner_stream_size);
        $ctx->memory_locations->add($inner_stream_location);
        $resource_context->addLocation($inner_stream_location);

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

        $size = $ctx->zend_type_reader->sizeOf('php_stdio_stream_data');
        $stdio_data = $ctx->dereferencer->deref(new Pointer(
            PhpStdioStreamData::class,
            $abstract_address,
            $size,
        ));
        $abstract_location = new PhpStdioStreamDataMemoryLocation($abstract_address, $size);
        $ctx->memory_locations->add($abstract_location);
        $resource_context->addLocation($abstract_location);

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

        $size = $ctx->zend_type_reader->sizeOf('php_netstream_data_t');
        $netstream_data = $ctx->dereferencer->deref(new Pointer(
            PhpNetstreamData::class,
            $abstract_address,
            $size,
        ));
        $abstract_location = new PhpNetstreamDataMemoryLocation($abstract_address, $size);
        $ctx->memory_locations->add($abstract_location);
        $resource_context->addLocation($abstract_location);

        $resource_context->stream_fd = $netstream_data->socket;
    }

    /**
     * Decode the (path, pattern) pair stored at the tail of the glob
     * stream's abstract data.
     *
     * The struct begins with a glob_t-shaped header followed by
     * `size_t index; int flags;` (+4 padding) and then the four
     * (path, path_len, pattern, pattern_len) fields we care about. The
     * header size depends on which glob implementation PHP was built
     * against, and we probe both known offsets:
     *
     *   - offset 88: PHP 7.0 .. 8.4 (and PHP 8.5 built with
     *     --with-system-glob), where the header is libc's `glob_t` and
     *     `sizeof(glob_t) == 72` on 64-bit Linux for both glibc 2.10+
     *     and musl 0.5+.
     *   - offset 112: PHP 8.5 default build, where the header is the
     *     bundled `php_glob_t` struct from main/php_glob.h
     *     (`sizeof(php_glob_t) == 96`, with extra `gl_matchc` and
     *     `gl_statv` fields plus an `gl_errfunc` pointer not present
     *     in libc's glob_t).
     *
     * _php_stream_opendir does not populate stream->orig_path for the
     * glob wrapper, so this is the only place the pattern is recoverable.
     *
     * Each (ptr, len) pair is validated by checking strlen-vs-len
     * consistency against the bytes actually mapped at the pointer; on
     * any mismatch the resource silently degrades to label-only — same
     * best-effort posture as the rest of tryCollectStreamData.
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

        foreach ([88, 112] as $tail_offset) {
            $synthetic_uri = $this->tryDecodeGlobTail(
                $ctx,
                $resource_context,
                $abstract_address + $tail_offset,
            );
            if ($synthetic_uri !== null) {
                $resource_context->stream_orig_path = $synthetic_uri;
                return;
            }
        }
    }

    private function tryDecodeGlobTail(
        CollectorContext $ctx,
        ResourceContext $resource_context,
        int $tail_address,
    ): ?string {
        try {
            $size = $ctx->zend_type_reader->sizeOf('php_glob_stream_data_tail');
            $tail = $ctx->dereferencer->deref(new Pointer(
                PhpGlobStreamDataTail::class,
                $tail_address,
                $size,
            ));
        } catch (\Throwable) {
            return null;
        }

        $path = $this->validatedGlobString($ctx, $tail->path, $tail->path_len);
        $pattern = $this->validatedGlobString($ctx, $tail->pattern, $tail->pattern_len);
        if ($path === null || $pattern === null) {
            return null;
        }

        // Only register the tail as accounted memory once both pairs
        // validate — otherwise we'd be charging the resource for bytes
        // we couldn't actually interpret.
        $tail_location = new PhpGlobStreamDataMemoryLocation($tail_address, $size);
        $ctx->memory_locations->add($tail_location);
        $resource_context->addLocation($tail_location);

        return 'glob://' . $path . '/' . $pattern;
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
        ResourceContext $resource_context,
    ): ?Zval {
        $abstract_address = $php_stream->abstract;
        if ($abstract_address === 0) {
            return null;
        }

        $size = $ctx->zend_type_reader->sizeOf('php_userstream_data_t');
        $userstream_data = $ctx->dereferencer->deref(new Pointer(
            PhpUserstreamData::class,
            $abstract_address,
            $size,
        ));
        $abstract_location = new PhpUserstreamDataMemoryLocation($abstract_address, $size);
        $ctx->memory_locations->add($abstract_location);
        $resource_context->addLocation($abstract_location);

        return $userstream_data->object;
    }
}
