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

use Reli\Converter\ParsedCallTrace;

/**
 * Time-based segment rotation writer.
 *
 * Produces self-contained segments, each with its own header and
 * FRAME_DEF/STACK_DEF events. Segments are concatenated in a single stream
 * or rotated to separate files via a stream factory callback.
 *
 * With compress_completed_segments=true, each completed segment is
 * gzip-compressed before being written to the output. The resulting
 * file contains concatenated gzip members (RFC 1952 compliant) and
 * can be decompressed with gzopen/gzread or zcat.
 */
final class SegmentedBinaryTraceWriter
{
    private ?BinaryTraceWriter $current_writer = null;

    /** @var array<string, ParsedCallTrace> stack_key => trace prototype */
    private array $known_stacks = [];

    private ?int $segment_start_us = null;
    private ?int $last_timestamp_us = null;
    private int $segment_index = 0;

    /** @var resource|null current stream from stream_factory */
    private $current_stream = null;

    /** @var resource|null temp buffer for segment when compressing */
    private $segment_buffer = null;

    /**
     * @param resource|null $stream Single stream for concatenated segments (stdout mode).
     *                              Mutually exclusive with $stream_factory.
     * @param (\Closure(int): resource)|null $stream_factory Called with segment index to get a
     *                              new stream for each segment (file rotation mode).
     * @param bool $compress_completed_segments When true, each completed segment is
     *             gzip-compressed before writing to the output stream. The output is
     *             concatenated gzip members (readable with gzopen/zcat).
     */
    public function __construct(
        private $stream,
        private int $sampling_period_us = 10000,
        private int $segment_duration_us = 10_000_000,
        private int $checkpoint_interval = 1000,
        private ?\Closure $stream_factory = null,
        private bool $compress_completed_segments = false,
    ) {
    }

    /**
     * Write a trace sample with an absolute timestamp.
     * Handles segment rotation, definition re-emission, and timestamp deltas.
     */
    public function writeTrace(ParsedCallTrace $trace, int $timestamp_us): void
    {
        if ($this->current_writer === null) {
            $this->startSegment($timestamp_us);
        }

        // Check if segment duration exceeded
        assert($this->segment_start_us !== null);
        if ($timestamp_us - $this->segment_start_us >= $this->segment_duration_us) {
            $this->rotateSegment($timestamp_us);
        }

        $delta = $this->last_timestamp_us !== null
            ? max(0, $timestamp_us - $this->last_timestamp_us)
            : 0;
        $this->last_timestamp_us = $timestamp_us;

        $this->trackTrace($trace);
        assert($this->current_writer !== null);
        $this->current_writer->writeTrace($trace, $delta);

        if ($this->current_writer->getSamplesSinceCheckpoint() >= $this->checkpoint_interval) {
            $this->current_writer->writeCheckpoint();
        }
    }

    /**
     * Finish the current segment cleanly.
     * Closes stream_factory-created streams if any; the shared $stream
     * passed to the constructor is not closed (caller-owned).
     */
    public function finish(): void
    {
        if ($this->current_writer !== null) {
            $this->current_writer->writeCheckpoint();
            $this->current_writer->writeSegmentEnd();
            $this->current_writer = null;
            $this->flushCompressedSegment();
            if ($this->stream_factory !== null && $this->current_stream !== null) {
                $s = $this->current_stream;
                $this->current_stream = null;
                fclose($s);
            }
        }
    }

    public function getSegmentIndex(): int
    {
        return $this->segment_index;
    }

    private function startSegment(int $timestamp_us): void
    {
        $this->segment_start_us = $timestamp_us;

        if ($this->compress_completed_segments) {
            // Write segment to a temp buffer; flush to output on completion
            $buf = fopen('php://temp', 'r+');
            assert($buf !== false);
            $this->segment_buffer = $buf;
            $write_stream = $this->segment_buffer;
        } else {
            $write_stream = $this->resolveStream();
        }

        $this->current_writer = new BinaryTraceWriter(
            $write_stream,
            $this->sampling_period_us,
            has_timestamps: true,
        );
        $this->current_writer->writeHeader();

        // Prime the new writer with all known definitions
        foreach ($this->known_stacks as $trace) {
            $this->current_writer->defineTrace($trace);
        }
    }

    private function rotateSegment(int $timestamp_us): void
    {
        // Close current segment
        if ($this->current_writer !== null) {
            $this->current_writer->writeCheckpoint();
            $this->current_writer->writeSegmentEnd();
            $this->current_writer = null;
            $this->flushCompressedSegment();
        }

        // Close and reset stream for file rotation mode
        if ($this->stream_factory !== null && $this->current_stream !== null) {
            $s = $this->current_stream;
            $this->current_stream = null;
            fclose($s);
        } else {
            $this->current_stream = null;
        }
        $this->segment_index++;
        $this->startSegment($timestamp_us);
    }

    /**
     * If compressing, gzencode the segment buffer and append to the output stream.
     */
    private function flushCompressedSegment(): void
    {
        if (!$this->compress_completed_segments || $this->segment_buffer === null) {
            return;
        }

        $buf = $this->segment_buffer;
        $this->segment_buffer = null;
        rewind($buf);
        $raw = stream_get_contents($buf);
        fclose($buf);

        if ($raw === false || $raw === '') {
            return;
        }

        $compressed = gzencode($raw);
        if ($compressed === false) {
            throw new BinaryTraceException('Failed to gzip-compress segment');
        }

        // In file rotation mode, get a fresh stream for this segment.
        // In single-stream mode, append to the shared stream.
        if ($this->stream_factory !== null) {
            $this->current_stream = ($this->stream_factory)($this->segment_index);
            fwrite($this->current_stream, $compressed);
        } else {
            assert($this->stream !== null);
            fwrite($this->stream, $compressed);
        }
    }

    /**
     * @return resource
     */
    private function resolveStream()
    {
        if ($this->stream_factory !== null) {
            $this->current_stream = ($this->stream_factory)($this->segment_index);
            return $this->current_stream;
        }
        assert($this->stream !== null);
        return $this->stream;
    }

    private function trackTrace(ParsedCallTrace $trace): void
    {
        $frame_keys = [];
        foreach ($trace->call_frames as $frame) {
            $frame_keys[] = $frame->function_name . "\0" . $frame->file_name . "\0" . $frame->lineno;
        }
        $key = implode("\1", $frame_keys);
        if (!isset($this->known_stacks[$key])) {
            $this->known_stacks[$key] = $trace;
        }
    }
}
