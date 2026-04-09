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
 */
final class SegmentedBinaryTraceWriter
{
    private ?BinaryTraceWriter $current_writer = null;

    /** @var array<string, ParsedCallTrace> stack_key => trace prototype */
    private array $known_stacks = [];

    private ?int $segment_start_us = null;
    private ?int $last_timestamp_us = null;
    private int $segment_index = 0;

    /** @var resource|null current stream (for file rotation cleanup) */
    private $current_stream = null;

    /**
     * @param resource|null $stream Single stream for concatenated segments (stdout mode).
     *                              Mutually exclusive with $stream_factory.
     * @param (\Closure(int): resource)|null $stream_factory Called with segment index to get a
     *                              new stream for each segment (file rotation mode).
     */
    public function __construct(
        private $stream,
        private int $sampling_period_us = 10000,
        private int $segment_duration_us = 10_000_000,
        private int $checkpoint_interval = 1000,
        private ?\Closure $stream_factory = null,
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
        if ($timestamp_us - $this->segment_start_us >= $this->segment_duration_us) {
            $this->rotateSegment($timestamp_us);
        }

        $delta = $this->last_timestamp_us !== null
            ? max(0, $timestamp_us - $this->last_timestamp_us)
            : 0;
        $this->last_timestamp_us = $timestamp_us;

        $this->trackTrace($trace);
        $this->current_writer->writeTrace($trace, $delta);

        if ($this->current_writer->getSamplesSinceCheckpoint() >= $this->checkpoint_interval) {
            $this->current_writer->writeCheckpoint();
        }
    }

    /**
     * Finish the current segment cleanly.
     * Note: does not close streams created by the stream factory;
     * the caller is responsible for closing them.
     */
    public function finish(): void
    {
        if ($this->current_writer !== null) {
            $this->current_writer->writeCheckpoint();
            $this->current_writer->writeSegmentEnd();
            $this->current_writer = null;
        }
    }

    public function getSegmentIndex(): int
    {
        return $this->segment_index;
    }

    private function startSegment(int $timestamp_us): void
    {
        $this->segment_start_us = $timestamp_us;

        $stream = $this->resolveStream();
        $this->current_writer = new BinaryTraceWriter(
            $stream,
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
        }

        $this->segment_index++;
        $this->current_writer = null;
        $this->startSegment($timestamp_us);
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
