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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\PhpStream;

use Reli\Lib\PhpInternals\Types\C\RawString;
use Reli\Lib\PhpInternals\Types\Php\PhpStdioStreamData;
use Reli\Lib\PhpInternals\Types\Php\PhpStream;
use Reli\Lib\PhpInternals\Types\Php\PhpStreamMemoryData;
use Reli\Lib\PhpInternals\Types\Php\PhpStreamOps;
use Reli\Lib\PhpInternals\Types\Php\PhpStreamTempData;
use Reli\Lib\PhpInternals\Types\Zend\ZendString;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\PhpStdioStreamDataMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\PhpStreamMemoryDataMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\PhpStreamMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\PhpStreamTempDataMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringSlotTailMemoryLocation;
use Reli\Lib\Process\MemoryLocation;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Walks the inline data hanging off a `php_stream*` and emits a
 * MemoryLocation per accounting unit (stream struct, abstract data,
 * stream-resident zend_strings). Caller decides what to do with each
 * via the `on_location` callback — `EmitResourceJob` attaches them
 * to its `ResourceContext`, the phar module-globals walker attaches
 * them to its per-archive host context, etc.
 *
 * Only the `MEMORY` / `TEMP` / `STDIO` stream labels are handled
 * here; sockets / glob / user-space all need additional walking that
 * is currently inlined in `EmitResourceJob`. Adding them later is
 * mechanical — the dispatcher in {@see walkStreamData} just needs
 * more branches and the methods can move in beside walkMemory /
 * walkTemp / walkStdio.
 *
 * The stream-resident zend_string emit guard (refcount + length
 * sanity, slot-tail attachment) matches the legacy
 * `EmitResourceJob::tryEmitStreamString` behaviour to keep the
 * outputs interchangeable across the two callers.
 */
final class PhpStreamWalker
{
    /** @see EmitResourceJob's notes for why this ceiling exists. */
    public const MAX_PLAUSIBLE_STREAM_STRING_LEN = 1 << 20;

    /** @param \Closure(MemoryLocation): void $on_location */
    public function __construct(
        private CollectorContext $ctx,
        private \Closure $on_location,
    ) {
    }

    /**
     * Read the php_stream at $stream_addr, register a
     * PhpStreamMemoryLocation for the struct itself, and return the
     * deref'd PhpStream so the caller can chain further work
     * (label-aware data walk via {@see walkStreamData}, ownership
     * checks, label-specific branches that aren't covered here, …).
     *
     * Returns null when the deref fails — caller treats that as
     * "this stream slot is unwalkable" and moves on.
     */
    public function readStream(int $stream_addr): ?PhpStream
    {
        if ($stream_addr === 0) {
            return null;
        }
        try {
            $php_stream_size = $this->ctx->zend_type_reader->sizeOf('php_stream');
            $php_stream = $this->ctx->dereferencer->deref(new Pointer(
                PhpStream::class,
                $stream_addr,
                $php_stream_size,
            ));
        } catch (\Throwable) {
            return null;
        }
        $stream_location = new PhpStreamMemoryLocation($stream_addr, $php_stream_size);
        $this->ctx->memory_locations->add($stream_location);
        ($this->on_location)($stream_location);
        return $php_stream;
    }

    /**
     * Read the stream's ops label, dispatch on it, and emit the
     * abstract data MemoryLocations for the supported stream types.
     * Returns the label string the dispatcher saw, or null when ops
     * couldn't be read.
     */
    public function walkStreamData(PhpStream $php_stream): ?string
    {
        $ops_address = $php_stream->ops;
        if ($ops_address === 0) {
            return null;
        }
        try {
            $ops = $this->ctx->dereferencer->deref(new Pointer(
                PhpStreamOps::class,
                $ops_address,
                $this->ctx->zend_type_reader->sizeOf('php_stream_ops'),
            ));
        } catch (\Throwable) {
            return null;
        }
        $label_address = $ops->label;
        if ($label_address === 0) {
            return null;
        }
        $label = (string)$this->ctx->dereferencer->deref(
            new Pointer(RawString::class, $label_address, 32)
        );
        try {
            if ($label === 'MEMORY') {
                $this->walkMemory($php_stream);
            } elseif ($label === 'TEMP') {
                $this->walkTemp($php_stream);
            } elseif ($label === 'STDIO') {
                $this->walkStdio($php_stream);
            }
            // Other labels (socket / glob / user-space) are handled
            // elsewhere today — see EmitResourceJob.
        } catch (\Throwable) {
            // partial / bogus data — bail without committing further locations.
        }
        return $label;
    }

    private function walkMemory(PhpStream $php_stream): void
    {
        $abstract_address = $php_stream->abstract;
        if ($abstract_address === 0) {
            return;
        }
        $size = $this->ctx->zend_type_reader->sizeOf('php_stream_memory_data');
        $mem_data = $this->ctx->dereferencer->deref(new Pointer(
            PhpStreamMemoryData::class,
            $abstract_address,
            $size,
        ));
        $abstract_location = new PhpStreamMemoryDataMemoryLocation($abstract_address, $size);
        $this->ctx->memory_locations->add($abstract_location);
        ($this->on_location)($abstract_location);
        $this->tryEmitStreamString($mem_data->data);
    }

    private function walkTemp(PhpStream $php_stream): void
    {
        $abstract_address = $php_stream->abstract;
        if ($abstract_address === 0) {
            return;
        }
        $temp_size = $this->ctx->zend_type_reader->sizeOf('php_stream_temp_data');
        $temp_data = $this->ctx->dereferencer->deref(new Pointer(
            PhpStreamTempData::class,
            $abstract_address,
            $temp_size,
        ));
        $temp_location = new PhpStreamTempDataMemoryLocation($abstract_address, $temp_size);
        $this->ctx->memory_locations->add($temp_location);
        ($this->on_location)($temp_location);

        $innerstream_address = $temp_data->innerstream;
        if ($innerstream_address === 0) {
            return;
        }
        // The inner stream is itself a php_stream (typically MEMORY)
        // backing the temp buffer — recurse via readStream so its
        // own abstract data gets accounted too.
        $inner = $this->readStream($innerstream_address);
        if ($inner !== null) {
            $this->walkStreamData($inner);
        }
    }

    private function walkStdio(PhpStream $php_stream): void
    {
        $abstract_address = $php_stream->abstract;
        if ($abstract_address === 0) {
            return;
        }
        $stdio_data = $this->ctx->dereferencer->deref(new Pointer(
            PhpStdioStreamData::class,
            $abstract_address,
            $this->ctx->zend_type_reader->sizeOf('php_stdio_stream_data'),
        ));
        // See EmitResourceJob::collectStdioStreamData for the size
        // rationale (leading 32 + HAVE_MMAP 16 + struct stat 144 = 192).
        $abstract_location = new PhpStdioStreamDataMemoryLocation($abstract_address, 192);
        $this->ctx->memory_locations->add($abstract_location);
        ($this->on_location)($abstract_location);
        $this->tryEmitStreamString($stdio_data->temp_name);
    }

    /**
     * Treat $address as a zend_string* reachable through a stream's
     * abstract data and emit a ZendStringMemoryLocation for it.
     * Bails out silently on null pointer, slot-recycled garbage
     * (length past MAX_PLAUSIBLE_STREAM_STRING_LEN), or duplicate
     * registration.
     */
    private function tryEmitStreamString(int $address): void
    {
        if ($address === 0) {
            return;
        }
        if ($this->ctx->memory_locations->has($address)) {
            return;
        }
        try {
            $str = $this->ctx->dereferencer->deref(new Pointer(
                ZendString::class,
                $address,
                $this->ctx->zend_type_reader->sizeOf('zend_string'),
            ));
        } catch (\Throwable) {
            return;
        }
        if ($str->len < 0 || $str->len > self::MAX_PLAUSIBLE_STREAM_STRING_LEN) {
            return;
        }
        $memory_location = ZendStringMemoryLocation::fromZendString($str, $this->ctx->dereferencer);
        $this->ctx->memory_locations->add($memory_location);
        ($this->on_location)($memory_location);
        $slot_tail = ZendStringSlotTailMemoryLocation::tryFromStringInChunks(
            $memory_location,
            $this->ctx->chunk_memory_locations,
        );
        if ($slot_tail !== null) {
            $this->ctx->memory_locations->add($slot_tail);
            ($this->on_location)($slot_tail);
        }
    }
}
