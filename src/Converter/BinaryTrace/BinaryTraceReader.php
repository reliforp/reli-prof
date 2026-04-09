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
    /** @var array<int, ParsedCallFrame> frame_id => frame */
    private array $frames = [];

    /** @var array<int, int[]> stack_id => frame_id[] */
    private array $stacks = [];

    /** @var array<string, string> key => value */
    private array $metadata = [];

    private int $sampling_period_us = 0;
    private int $flags = 0;
    private int $accumulated_timestamp_us = 0;

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

        while (!feof($stream)) {
            try {
                $result = $this->readOneEvent($stream);
                if ($result === false) {
                    break; // EOF
                }
                if ($result instanceof BinaryTraceSample) {
                    yield $result;
                }
                if ($result === 'new_segment') {
                    // Already handled in readOneEvent
                    continue;
                }
            } catch (BinaryTraceException) {
                // Error in current segment - scan for next segment
                if (!$this->scanForMagic($stream)) {
                    break;
                }
                $this->resetSegmentState();
            }
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
        $this->frames = [];
        $this->stacks = [];
        $this->metadata = [];
        $this->accumulated_timestamp_us = 0;
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
        while (!feof($stream)) {
            $result = $this->readOneEvent($stream);
            if ($result === false) {
                break; // EOF
            }
            if ($result instanceof BinaryTraceSample) {
                yield $result;
            }
            // 'new_segment' and null (non-sample events) just continue
        }
    }

    /**
     * Read and handle a single event.
     *
     * @param resource $stream
     * @return BinaryTraceSample|string|null|false
     *         BinaryTraceSample for SAMPLE events,
     *         'new_segment' when a new segment header is detected,
     *         null for non-sample events (FRAME_DEF, STACK_DEF, CHECKPOINT),
     *         false for EOF
     */
    private function readOneEvent($stream): BinaryTraceSample|string|null|false
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
                $this->resetSegmentState();
                return 'new_segment';
            }
            throw new BinaryTraceException(
                'Reserved event type 0x52 encountered without valid segment header'
            );
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
            case EventType::METADATA:
                $this->handleMetadata($payload);
                return null;
            case EventType::CHECKPOINT:
                return null;
            case EventType::SEGMENT_END:
                // Try to read next segment header; reset state only
                // when a new segment actually starts. This preserves
                // metadata/state for callers inspecting the last segment.
                if ($this->tryReadHeader($stream)) {
                    $this->resetSegmentState();
                }
                return null;
        }
    }

    private function handleFrameDef(string $payload): void
    {
        $offset = 0;
        [$frame_id, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;

        [$func_len, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;
        $function_name = substr($payload, $offset, $func_len);
        $offset += $func_len;

        [$file_len, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;
        $file_name = substr($payload, $offset, $file_len);
        $offset += $file_len;

        [$lineno, $consumed] = Varint::decode($payload, $offset);

        $this->frames[$frame_id] = new ParsedCallFrame($function_name, $file_name, $lineno);
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
