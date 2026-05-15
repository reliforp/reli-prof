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
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringSlotTailMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ResourceContext;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Emit a ZendResource node. Handles stream data collection inline.
 * Replaces collectResourcePointer.
 */
final class EmitResourceJob implements CollectorJob
{
    /**
     * Upper bound on `zend_string.len` for strings reached via stream
     * abstract data (php_stream_memory_data->data,
     * php_stdio_data->temp_name). Anything past this is treated as a
     * dangling pointer landing on garbage rather than a real string —
     * see {@see self::tryEmitStreamString()}.
     *
     * The bound is generous on purpose: real allocations sit well under
     * 1 GiB even for large in-memory buffers, while every pathological
     * shape reli has actually seen (multi-exabyte `len` from a stale
     * php_stream_memory_data->data) lands many orders of magnitude
     * above it.
     */
    private const MAX_PLAUSIBLE_STREAM_STRING_LEN = 1 << 30;

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
        // - For userspace streams the embedded zval is returned and
        //   dispatched after emitNode so the resulting node_id can parent
        //   the zval graph.
        // - $extra_addresses collects every heap address registered as a
        //   stream-related MemoryLocation (php_stream, abstract block,
        //   inner stream for TEMP, …). After emitNode we map each of
        //   those into address_map so external scanners (e.g. the
        //   ZendMM-bin pass that walks unaccounted allocations) can
        //   resolve the heap address back to this resource node.
        $extra_addresses = [];
        $deferred_userspace_zval = $this->tryCollectStreamData(
            $resource,
            $ctx,
            $resource_context,
            $extra_addresses,
        );

        $node_id = $ctx->emitNode(
            $resource_context,
            $this->parent_node_id,
            $this->link_name,
            $this->edge_strength,
        );
        if ($node_id >= 0) {
            $ctx->address_map[$address] = $node_id;
            foreach ($extra_addresses as $extra_address) {
                $ctx->address_map[$extra_address] = $node_id;
            }
        }

        if ($deferred_userspace_zval !== null) {
            $queue->push(new ResolveZvalJob(
                $deferred_userspace_zval,
                $node_id >= 0 ? $node_id : null,
                'stream_userspace_object',
            ));
        }
    }

    /** @param list<int> $extra_addresses */
    private function tryCollectStreamData(
        ZendResource $resource,
        CollectorContext $ctx,
        ResourceContext $resource_context,
        array &$extra_addresses,
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
            $extra_addresses[] = $ptr_address;

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
                $this->collectMemoryStreamData($php_stream, $ctx, $resource_context, $extra_addresses);
            } elseif ($label === 'TEMP') {
                $this->collectTempStreamData($php_stream, $ctx, $resource_context, $extra_addresses);
            } elseif ($label === 'STDIO') {
                $this->collectStdioStreamData($php_stream, $ctx, $resource_context, $extra_addresses);
            } elseif ($label === 'user-space') {
                return $this->extractUserspaceObjectZval($php_stream, $ctx, $resource_context, $extra_addresses);
            } elseif (
                $label === 'tcp_socket'
                || $label === 'udp_socket'
                || $label === 'unix_socket'
                || $label === 'udg_socket'
            ) {
                $this->collectSocketStreamData($php_stream, $ctx, $resource_context, $extra_addresses);
            } elseif ($label === 'glob') {
                $this->collectGlobStreamData($php_stream, $ctx, $resource_context, $extra_addresses);
            }
        } catch (\Throwable) {
            return null;
        }
        return null;
    }

    /** @param list<int> $extra_addresses */
    private function collectMemoryStreamData(
        PhpStream $php_stream,
        CollectorContext $ctx,
        ResourceContext $resource_context,
        array &$extra_addresses,
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
        $extra_addresses[] = $abstract_address;

        $this->tryEmitStreamString(
            $mem_data->data,
            'stream_memory_data',
            $ctx,
            $resource_context,
        );
    }

    /** @param list<int> $extra_addresses */
    private function collectTempStreamData(
        PhpStream $php_stream,
        CollectorContext $ctx,
        ResourceContext $resource_context,
        array &$extra_addresses,
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
        $extra_addresses[] = $abstract_address;

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
        $extra_addresses[] = $innerstream_address;

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
            $this->collectMemoryStreamData($inner_stream, $ctx, $resource_context, $extra_addresses);
        }
    }

    /** @param list<int> $extra_addresses */
    private function collectStdioStreamData(
        PhpStream $php_stream,
        CollectorContext $ctx,
        ResourceContext $resource_context,
        array &$extra_addresses,
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
        // The reli partial declaration only covers the leading 32 bytes
        // (file/fd/bitfield/lock_flag/temp_name); register the full
        // allocation as it exists on a typical Linux x86_64 build:
        //   leading 32 + HAVE_MMAP last_mapped_addr/len 16 + struct stat 144
        //   = 192 bytes
        // glibc Debian images leave HAVE_FLUSHIO undefined, so the `last_op`
        // byte is absent. Alpine (musl) defines HAVE_FLUSHIO and ends up at
        // 200 bytes; we keep the registration at 192 so we never overclaim
        // past the real allocation, accepting an 8-byte undercount on musl.
        // Other libcs / non-mmap builds fall back to the same conservative
        // value.
        $abstract_location = new PhpStdioStreamDataMemoryLocation($abstract_address, 192);
        $ctx->memory_locations->add($abstract_location);
        $resource_context->addLocation($abstract_location);
        $extra_addresses[] = $abstract_address;

        $resource_context->stream_fd = $stdio_data->fd;

        $this->tryEmitStreamString(
            $stdio_data->temp_name,
            'stream_temp_name',
            $ctx,
            $resource_context,
        );
    }

    /**
     * Treat `$address` as a `zend_string *` reachable through a stream's
     * abstract data and emit a {@see ZendStringMemoryLocation} for it
     * under `$context_key`. Bails out silently when:
     *   - the pointer is null;
     *   - the dereferenced `len` exceeds
     *     {@see self::MAX_PLAUSIBLE_STREAM_STRING_LEN}
     *     (the sentinel for "the slot was recycled and now holds
     *     garbage"; see commit history for the stale
     *     `php_stream_memory_data->data` failure mode that this guard
     *     exists for).
     *
     * Truly wild pointers (e.g. addresses outside any mapped page)
     * surface as a `MemoryReaderException` from `deref` and are caught
     * by the outer `tryCollectStreamData` try/catch, which lets the
     * resource walk continue without the stream child. We don't
     * pre-screen by chunk membership: persistent stream buffers and
     * legitimate-but-not-zend-mm allocations on some PHP versions can
     * still have a sane `len`, and rejecting them silently masked a
     * real `php://memory` link in `MemoryLocationsCollectorTest::testStreamResourceTracking`
     * on the PHP 8.0 target. The size-attribution side of the bug is
     * already handled at write time by
     * {@see \Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\BinaryContextTreeSink::emitNode()}
     * (which gates per-node accumulators on `RegionFilter::isRelevant`)
     * and at read time by {@see \Reli\Inspector\Output\MemoryOutput\RegionFilter}.
     *
     * The cached path mirrors the previous inline block: if the
     * address has already been visited, just reattach the existing
     * string context to the resource.
     *
     * @param non-empty-string $context_key
     */
    private function tryEmitStreamString(
        int $address,
        string $context_key,
        CollectorContext $ctx,
        ResourceContext $resource_context,
    ): void {
        if ($address === 0) {
            return;
        }

        if ($ctx->memory_locations->has($address)) {
            $cached = $ctx->context_pools->string_context_pool->getContextByAddress($address);
            if ($cached !== null) {
                $resource_context->add($context_key, $cached);
            }
            return;
        }

        $str = $ctx->dereferencer->deref(new Pointer(
            ZendString::class,
            $address,
            $ctx->zend_type_reader->sizeOf('zend_string'),
        ));

        // Slot recycled into garbage: `len` reads as a multi-exabyte
        // value. Skip rather than emit a bogus row that downstream
        // size aggregation has to filter out.
        if ($str->len < 0 || $str->len > self::MAX_PLAUSIBLE_STREAM_STRING_LEN) {
            return;
        }

        $memory_location = ZendStringMemoryLocation::fromZendString($str, $ctx->dereferencer);
        $ctx->memory_locations->add($memory_location);
        $slot_tail = ZendStringSlotTailMemoryLocation::tryFromStringInChunks(
            $memory_location,
            $ctx->chunk_memory_locations,
        );
        if ($slot_tail !== null) {
            $ctx->memory_locations->add($slot_tail);
        }
        $string_context = $ctx->context_pools->string_context_pool->getContextForLocation(
            $memory_location,
            $slot_tail,
        );
        $resource_context->add($context_key, $string_context);
    }

    /** @param list<int> $extra_addresses */
    private function collectSocketStreamData(
        PhpStream $php_stream,
        CollectorContext $ctx,
        ResourceContext $resource_context,
        array &$extra_addresses,
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
        // The reli partial declaration only covers the leading
        // php_socket_t fd. The full allocation on a typical Linux
        // x86_64 build is:
        //   socket 4 + addrlen 4 + sockaddr_storage 128 + is_blocked 4
        //   + (4 pad) + timeval 16 + timeout_event 1 + (3 pad)
        //   + ownership-enum 4 = 168 bytes
        // sockaddr_storage and timeval are the same size on glibc and
        // musl on x86_64, so 168 holds for both libcs.
        $abstract_location = new PhpNetstreamDataMemoryLocation($abstract_address, 168);
        $ctx->memory_locations->add($abstract_location);
        $resource_context->addLocation($abstract_location);
        $extra_addresses[] = $abstract_address;

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
    /** @param list<int> $extra_addresses */
    private function collectGlobStreamData(
        PhpStream $php_stream,
        CollectorContext $ctx,
        ResourceContext $resource_context,
        array &$extra_addresses,
    ): void {
        $abstract_address = $php_stream->abstract;
        if ($abstract_address === 0) {
            return;
        }

        foreach (
            [
                // tail offset => full sizeof(glob_s_t)
                //   88 / 120: PHP 7.0..8.4 (libc glob_t header, 72B; tail
                //             ends after pattern_len at offset 120).
                //   112 / 168: PHP 8.5 default build (bundled php_glob_t
                //             header, 96B; tail extended with
                //             open_basedir_indexmap{,_size,_used}, struct
                //             padded up to 168 bytes).
                [88, 120],
                [112, 168],
            ] as [$tail_offset, $struct_size]
        ) {
            $synthetic_uri = $this->tryDecodeGlobTail(
                $ctx,
                $resource_context,
                $abstract_address,
                $tail_offset,
                $struct_size,
                $extra_addresses,
            );
            if ($synthetic_uri !== null) {
                $resource_context->stream_orig_path = $synthetic_uri;
                return;
            }
        }
    }

    /** @param list<int> $extra_addresses */
    private function tryDecodeGlobTail(
        CollectorContext $ctx,
        ResourceContext $resource_context,
        int $abstract_address,
        int $tail_offset,
        int $struct_size,
        array &$extra_addresses,
    ): ?string {
        try {
            $tail = $ctx->dereferencer->deref(new Pointer(
                PhpGlobStreamDataTail::class,
                $abstract_address + $tail_offset,
                $ctx->zend_type_reader->sizeOf('php_glob_stream_data_tail'),
            ));
        } catch (\Throwable) {
            return null;
        }

        $path = $this->validatedGlobString($ctx, $tail->path, $tail->path_len);
        $pattern = $this->validatedGlobString($ctx, $tail->pattern, $tail->pattern_len);
        if ($path === null || $pattern === null) {
            return null;
        }

        // The successful tail offset disambiguates which glob_s_t variant
        // PHP was built against, so we know the size of the *whole*
        // allocation pointed to by php_stream->abstract — not just the
        // bytes we deref'd. Register the full struct so the analyzer
        // attributes the libc/php_glob_t header and any trailing
        // open_basedir_* fields to the resource as well.
        $location = new PhpGlobStreamDataMemoryLocation($abstract_address, $struct_size);
        $ctx->memory_locations->add($location);
        $resource_context->addLocation($location);
        $extra_addresses[] = $abstract_address;

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

    /** @param list<int> $extra_addresses */
    private function extractUserspaceObjectZval(
        PhpStream $php_stream,
        CollectorContext $ctx,
        ResourceContext $resource_context,
        array &$extra_addresses,
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
        $extra_addresses[] = $abstract_address;

        return $userstream_data->object;
    }
}
