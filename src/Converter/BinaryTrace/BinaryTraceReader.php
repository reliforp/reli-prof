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

namespace Reli\Converter\BinaryTrace;

use Reli\Converter\ParsedCallFrame;
use Reli\Converter\ParsedCallTrace;

final class BinaryTraceReader
{
    /** @var array<int, string> string_id => string */
    private array $strings = [];

    /** @var array<int, ParsedCallFrame> frame_id => frame */
    private array $frames = [];

    /** @var array<int, int[]> stack_id => frame_id[] */
    private array $stacks = [];

    /** @var array<string, string> key => value */
    private array $metadata = [];

    private int $sampling_period_us = 0;
    private int $flags = 0;
    private int $accumulated_timestamp_us = 0;
    private ?int $last_sample_stack_id = null;

    /** @var list<BinaryTraceSample> buffered repeat expansion */
    private array $repeat_buffer = [];
    private int $pending_repeat_count = 0;

    /**
     * Read header and yield BinaryTraceSample for each SAMPLE event.
     * Supports multiple concatenated segments in a single stream.
     *
     * @param resource $stream
     * @return iterable<BinaryTraceSample>
     */
    public function read($stream): iterable
    {
        if (!$this->tryReadHeader($stream)) {
            return;
        }
        $this->resetSegmentState();

        yield from $this->readEvents($stream);
    }

    /**
     * Read with crash recovery: scans for segment boundaries on error,
     * yields all samples that can be recovered.
     *
     * @param resource $stream
     * @return iterable<BinaryTraceSample>
     */
    public function readWithRecovery($stream): iterable
    {
        if (!$this->scanForMagic($stream)) {
            return;
        }
        $this->resetSegmentState();

        /** @var BinaryTraceSample|null $pending */
        $pending = null;
        /** @var BinaryTraceSample|null $last_completed */
        $last_completed = null;

        while (!feof($stream) || $this->repeat_buffer !== [] || $pending !== null) {
            if ($this->repeat_buffer !== []) {
                if ($pending !== null) {
                    $last_completed = $pending;
                    yield $pending;
                    $pending = null;
                }
                foreach ($this->repeat_buffer as $buffered) {
                    $last_completed = $buffered;
                    yield $buffered;
                }
                $this->repeat_buffer = [];
                continue;
            }
            try {
                $result = $this->readOneEvent($stream);
                if ($result === false) {
                    break; // EOF
                }
                if ($result instanceof BinaryTraceSample) {
                    if ($pending !== null) {
                        $last_completed = $pending;
                        yield $pending;
                    }
                    $pending = $result;
                } elseif (is_array($result)) {
                    if ($pending === null) {
                        throw new BinaryTraceException(
                            'SAMPLE_ANNOTATION without a preceding sample'
                        );
                    }
                    $pending = new BinaryTraceSample(
                        $pending->trace,
                        $pending->timestamp_delta_us,
                        $pending->accumulated_timestamp_us,
                        $pending->pid,
                        $result,
                    );
                } elseif ($result === 'repeat') {
                    if ($pending !== null) {
                        $last_completed = $pending;
                        yield $pending;
                        $pending = null;
                    }
                    if ($last_completed === null) {
                        throw new BinaryTraceException(
                            'REPEAT_SAMPLE without a preceding completed sample'
                        );
                    }
                    for ($i = 0; $i < $this->pending_repeat_count; $i++) {
                        $this->repeat_buffer[] = $last_completed;
                    }
                    $this->pending_repeat_count = 0;
                } elseif ($result === 'new_segment') {
                    if ($pending !== null) {
                        $last_completed = $pending;
                        yield $pending;
                        $pending = null;
                    }
                    $last_completed = null;
                    $this->resetSegmentState();
                    continue;
                }
            } catch (BinaryTraceException) {
                if ($pending !== null) {
                    yield $pending;
                    $pending = null;
                }
                $last_completed = null;
                if (!$this->scanForMagic($stream)) {
                    break;
                }
                $this->resetSegmentState();
            }
        }

        if ($pending !== null) {
            yield $pending;
        }
    }

    public function getSamplingPeriodUs(): int
    {
        return $this->sampling_period_us;
    }

    public function hasTimestamps(): bool
    {
        return ($this->flags & BinaryTraceWriter::FLAG_HAS_TIMESTAMPS) !== 0;
    }

    /**
     * Try to read a 16-byte header from current position.
     *
     * @param resource $stream
     */
    private function tryReadHeader($stream): bool
    {
        $magic = @fread($stream, 4);
        if ($magic === '' || $magic === false || strlen($magic) < 4) {
            return false;
        }
        if ($magic !== BinaryTraceWriter::MAGIC) {
            throw new BinaryTraceException(
                sprintf('Invalid magic: expected "RELI", got "%s"', $magic)
            );
        }
        $rest = $this->readExact($stream, 12);
        $this->parseHeaderBytes($magic . $rest);
        return true;
    }

    private function parseHeaderBytes(string $header): void
    {
        $version = ord($header[4]);
        if ($version !== BinaryTraceWriter::VERSION) {
            throw new BinaryTraceException(
                sprintf('Unsupported version: %d (expected %d)', $version, BinaryTraceWriter::VERSION)
            );
        }

        $this->flags = ord($header[5]);
        // bytes 6-7: reserved
        /** @var array<int, int> $unpacked */
        $unpacked = unpack('V', substr($header, 8, 4));
        $this->sampling_period_us = $unpacked[1];
        // bytes 12-15: reserved
    }

    /**
     * Get metadata from the current/last segment.
     *
     * @return array<string, string>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    private function resetSegmentState(): void
    {
        $this->strings = [];
        $this->frames = [];
        $this->stacks = [];
        $this->metadata = [];
        $this->accumulated_timestamp_us = 0;
        $this->last_sample_stack_id = null;
        $this->repeat_buffer = [];
    }

    /**
     * Scan forward for "RELI" magic and read the full header.
     *
     * @param resource $stream
     */
    private function scanForMagic($stream): bool
    {
        $buffer = '';
        while (!feof($stream)) {
            $byte = fread($stream, 1);
            if ($byte === '' || $byte === false) {
                return false;
            }
            $buffer .= $byte;
            if (strlen($buffer) > 4) {
                $buffer = substr($buffer, -4);
            }
            if ($buffer === BinaryTraceWriter::MAGIC) {
                try {
                    $remaining = $this->readExact($stream, 12);
                    $this->parseHeaderBytes(BinaryTraceWriter::MAGIC . $remaining);
                    return true;
                } catch (BinaryTraceException) {
                    $buffer = '';
                    continue;
                }
            }
        }
        return false;
    }

    /**
     * @param resource $stream
     * @return iterable<BinaryTraceSample>
     */
    private function readEvents($stream): iterable
    {
        /** @var BinaryTraceSample|null $pending — sample awaiting possible annotation */
        $pending = null;
        /** @var BinaryTraceSample|null $last_completed — last yielded completed sample (for REPEAT) */
        $last_completed = null;

        while (!feof($stream) || $this->repeat_buffer !== [] || $pending !== null) {
            // Drain any buffered repeat expansion first
            if ($this->repeat_buffer !== []) {
                if ($pending !== null) {
                    $last_completed = $pending;
                    yield $pending;
                    $pending = null;
                }
                foreach ($this->repeat_buffer as $buffered) {
                    $last_completed = $buffered;
                    yield $buffered;
                }
                $this->repeat_buffer = [];
                continue;
            }
            $result = $this->readOneEvent($stream);
            if ($result === false) {
                break; // EOF
            }
            if ($result instanceof BinaryTraceSample) {
                // Yield the previous pending sample as completed
                if ($pending !== null) {
                    $last_completed = $pending;
                    yield $pending;
                }
                $pending = $result;
            } elseif (is_array($result)) {
                // SAMPLE_ANNOTATION: must follow a pending sample
                if ($pending === null) {
                    throw new BinaryTraceException(
                        'SAMPLE_ANNOTATION without a preceding sample'
                    );
                }
                $pending = new BinaryTraceSample(
                    $pending->trace,
                    $pending->timestamp_delta_us,
                    $pending->accumulated_timestamp_us,
                    $pending->pid,
                    $result,
                );
            } elseif ($result === 'repeat') {
                // REPEAT_SAMPLE: pending becomes completed, then repeat it
                if ($pending !== null) {
                    $last_completed = $pending;
                    yield $pending;
                    $pending = null;
                }
                if ($last_completed === null) {
                    throw new BinaryTraceException(
                        'REPEAT_SAMPLE without a preceding completed sample'
                    );
                }
                for ($i = 0; $i < $this->pending_repeat_count; $i++) {
                    $this->repeat_buffer[] = $last_completed;
                }
                $this->pending_repeat_count = 0;
            } elseif ($result === 'new_segment') {
                if ($pending !== null) {
                    $last_completed = $pending;
                    yield $pending;
                    $pending = null;
                }
                $last_completed = null;
                $this->resetSegmentState();
            }
            // null (non-sample events like FRAME_DEF, CHECKPOINT) just continue
        }

        // Yield final pending sample
        if ($pending !== null) {
            yield $pending;
        }
    }

    /**
     * Read and handle a single event.
     *
     * @param resource $stream
     * @return BinaryTraceSample|array<string, string>|string|null|false
     *         BinaryTraceSample for SAMPLE events,
     *         array for SAMPLE_ANNOTATION (key-value pairs),
     *         'new_segment' when a new segment header is detected,
     *         null for non-sample events (FRAME_DEF, STACK_DEF, CHECKPOINT),
     *         false for EOF
     */
    private function readOneEvent($stream): BinaryTraceSample|array|string|null|false
    {
        $type_byte = fread($stream, 1);
        if ($type_byte === '' || $type_byte === false) {
            return false;
        }

        $type_int = ord($type_byte);

        // 0x52 ('R') is reserved — it is the first byte of the "RELI" magic.
        // When encountered as an event type, probe for a segment header.
        // If the next 3 bytes complete the magic, this is a new segment.
        // Otherwise it is an error (0x52 must not be used as an event type).
        if ($type_int === 0x52) {
            $rest = fread($stream, 3);
            if ($rest !== false && strlen($rest) === 3 && $type_byte . $rest === BinaryTraceWriter::MAGIC) {
                $remaining = $this->readExact($stream, 12);
                $this->parseHeaderBytes(BinaryTraceWriter::MAGIC . $remaining);
                // Don't reset here — readEvents flushes pending sample first
                return 'new_segment';
            }
            throw new BinaryTraceException(
                'Reserved event type 0x52 encountered without valid segment header'
            );
        }

        // COMPACT_SAMPLE has no payload_length — just [event_type][stack_id: varint]
        if ($type_int === EventType::COMPACT_SAMPLE->value) {
            $stack_id = Varint::decodeFromStream($stream);
            $this->last_sample_stack_id = $stack_id;
            return $this->resolveStack($stack_id);
        }

        // REPEAT_SAMPLE has no payload_length — just [event_type][count: varint]
        // Returns 'repeat'; readEvents populates repeat_buffer from last_completed.
        if ($type_int === EventType::REPEAT_SAMPLE->value) {
            $this->pending_repeat_count = Varint::decodeFromStream($stream);
            return 'repeat';
        }

        $payload_length = Varint::decodeFromStream($stream);
        // Sanity check: reject absurdly large payloads (max 16 MB)
        if ($payload_length > 16 * 1024 * 1024) {
            throw new BinaryTraceException(
                sprintf('Payload length too large: %d bytes', $payload_length)
            );
        }
        $payload = $payload_length > 0 ? $this->readExact($stream, $payload_length) : '';

        $type = EventType::tryFrom($type_int);
        if ($type === null) {
            return null; // Unknown event, skip
        }

        switch ($type) {
            case EventType::STRING_DEF:
                $this->handleStringDef($payload);
                return null;
            case EventType::FRAME_DEF:
                $this->handleFrameDef($payload);
                return null;
            case EventType::STACK_DEF:
                $this->handleStackDef($payload);
                return null;
            case EventType::SAMPLE:
                return $this->handleSample($payload);
            case EventType::PID_SAMPLE:
                return $this->handlePidSample($payload);
            case EventType::SAMPLE_ANNOTATION:
                return $this->handleSampleAnnotation($payload);
            case EventType::METADATA:
                $this->handleMetadata($payload);
                return null;
            case EventType::CHECKPOINT:
                return null;
            case EventType::SEGMENT_END:
                // Don't reset here — readEvents flushes pending sample first,
                // then resets state when it processes 'new_segment'.
                $has_next = $this->tryReadHeader($stream);
                return $has_next ? 'new_segment' : null;
        }
    }

    private function handleStringDef(string $payload): void
    {
        $offset = 0;
        [$string_id, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;
        $this->strings[$string_id] = substr($payload, $offset);
    }

    private function resolveString(int $string_id): string
    {
        if (!isset($this->strings[$string_id])) {
            throw new BinaryTraceException("Reference to undefined string_id: {$string_id}");
        }
        return $this->strings[$string_id];
    }

    /**
     * FRAME_DEF payload:
     *   [frame_id][flags]
     *   if PHP:    [ns_sid][class_sid][method_sid][file_sid][lineno][opcode_sid if HAS_OPCODE]
     *   if native: [symbol_sid][module_sid][offset]
     */
    private function handleFrameDef(string $payload): void
    {
        $offset = 0;
        [$frame_id, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;
        [$flags, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;

        if (($flags & BinaryTraceWriter::FRAME_FLAG_NATIVE) !== 0) {
            $this->handleNativeFrameDef($frame_id, $payload, $offset);
        } else {
            $this->handlePhpFrameDef($frame_id, $flags, $payload, $offset);
        }
    }

    private function handlePhpFrameDef(
        int $frame_id,
        int $flags,
        string $payload,
        int $offset,
    ): void {
        [$ns_sid, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;
        [$class_sid, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;
        [$method_sid, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;
        [$file_sid, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;
        [$lineno, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;

        $opcode_name = null;
        if (($flags & BinaryTraceWriter::FRAME_FLAG_HAS_OPCODE) !== 0 && $offset < strlen($payload)) {
            [$opcode_sid, $consumed] = Varint::decode($payload, $offset);
            $opcode_name = $this->resolveString($opcode_sid);
        }

        $namespace = $this->resolveString($ns_sid);
        $class = $this->resolveString($class_sid);
        $method = $this->resolveString($method_sid);
        $file_name = $this->resolveString($file_sid);

        // Reconstruct function_name: "Namespace\Class::method"
        $function_name = $method;
        if ($class !== '') {
            $fqcn = $namespace !== '' ? $namespace . '\\' . $class : $class;
            $function_name = $fqcn . '::' . $method;
        } elseif ($namespace !== '') {
            $function_name = $namespace . '\\' . $method;
        }

        $this->frames[$frame_id] = new ParsedCallFrame(
            $function_name,
            $file_name,
            $lineno,
            opcode_name: $opcode_name,
        );
    }

    private function handleNativeFrameDef(int $frame_id, string $payload, int $offset): void
    {
        [$symbol_sid, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;
        [$module_sid, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;
        [$sym_offset, $consumed] = Varint::decode($payload, $offset);

        $symbol_name = $this->resolveString($symbol_sid);
        $module_name = $this->resolveString($module_sid);

        $this->frames[$frame_id] = new ParsedCallFrame(
            $symbol_name,
            $module_name,
            0,
            frame_type: ParsedCallFrame::TYPE_NATIVE,
            module_name: $module_name,
            symbol_offset: $sym_offset,
        );
    }

    private function handleStackDef(string $payload): void
    {
        $offset = 0;
        [$stack_id, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;

        [$depth, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;

        $frame_ids = [];
        for ($i = 0; $i < $depth; $i++) {
            [$fid, $consumed] = Varint::decode($payload, $offset);
            $offset += $consumed;
            $frame_ids[] = $fid;
        }

        $this->stacks[$stack_id] = $frame_ids;
    }

    private function handleSample(string $payload): BinaryTraceSample
    {
        $offset = 0;
        [$stack_id, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;

        $timestamp_delta_us = null;
        if (($this->flags & BinaryTraceWriter::FLAG_HAS_TIMESTAMPS) !== 0 && $offset < strlen($payload)) {
            [$timestamp_delta_us, $consumed] = Varint::decode($payload, $offset);
            $this->accumulated_timestamp_us += $timestamp_delta_us;
        }

        $this->last_sample_stack_id = $stack_id;
        return $this->resolveStack($stack_id, $timestamp_delta_us);
    }

    /**
     * Build a BinaryTraceSample from a stack_id with optional timestamp.
     */
    private function resolveStack(int $stack_id, ?int $timestamp_delta_us = null, ?int $pid = null): BinaryTraceSample
    {
        if (!isset($this->stacks[$stack_id])) {
            throw new BinaryTraceException("Reference to undefined stack_id: {$stack_id}");
        }

        $frame_ids = $this->stacks[$stack_id];
        $frames = [];
        foreach ($frame_ids as $fid) {
            if (!isset($this->frames[$fid])) {
                throw new BinaryTraceException("Reference to undefined frame_id: {$fid}");
            }
            $frames[] = $this->frames[$fid];
        }

        return new BinaryTraceSample(
            new ParsedCallTrace(...$frames),
            $timestamp_delta_us,
            $timestamp_delta_us !== null ? $this->accumulated_timestamp_us : null,
            $pid,
        );
    }

    private function handlePidSample(string $payload): BinaryTraceSample
    {
        $offset = 0;
        [$stack_id, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;

        [$pid, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;

        $timestamp_delta_us = null;
        if (($this->flags & BinaryTraceWriter::FLAG_HAS_TIMESTAMPS) !== 0 && $offset < strlen($payload)) {
            [$timestamp_delta_us, $consumed] = Varint::decode($payload, $offset);
            $this->accumulated_timestamp_us += $timestamp_delta_us;
        }

        return $this->resolveStack($stack_id, $timestamp_delta_us, $pid);
    }

    /**
     * @return array<string, string>
     */
    private function handleSampleAnnotation(string $payload): array
    {
        $offset = 0;
        [$count, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;

        $annotations = [];
        for ($i = 0; $i < $count; $i++) {
            [$key_sid, $consumed] = Varint::decode($payload, $offset);
            $offset += $consumed;
            [$value_sid, $consumed] = Varint::decode($payload, $offset);
            $offset += $consumed;
            $annotations[$this->resolveString($key_sid)] = $this->resolveString($value_sid);
        }
        return $annotations;
    }

    private function handleMetadata(string $payload): void
    {
        $offset = 0;
        [$key_len, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;
        $key = substr($payload, $offset, $key_len);
        $offset += $key_len;

        [$value_len, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;
        $value = substr($payload, $offset, $value_len);

        $this->metadata[$key] = $value;
    }

    /**
     * @param resource $stream
     */
    private function readExact($stream, int $length): string
    {
        $data = '';
        $remaining = $length;
        while ($remaining > 0) {
            $chunk = fread($stream, $remaining);
            if ($chunk === '' || $chunk === false) {
                throw new BinaryTraceException(
                    sprintf('Unexpected end of stream: expected %d bytes, got %d', $length, $length - $remaining)
                );
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }
        return $data;
    }
}
