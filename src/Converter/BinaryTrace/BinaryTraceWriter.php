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

final class BinaryTraceWriter
{
    public const MAGIC = "RELI";
    public const VERSION = 1;
    public const FLAG_HAS_TIMESTAMPS = 0x01;

    /** @var array<string, int> frame_key => frame_id */
    private array $frame_map = [];
    private int $next_frame_id = 0;

    /** @var array<string, int> stack_key => stack_id */
    private array $stack_map = [];
    private int $next_stack_id = 0;

    private int $sample_count = 0;
    private int $last_checkpoint_samples = 0;

    /** @var resource */
    private $stream;
    private int $flags;

    /**
     * @param resource $stream
     * @param int $sampling_period_us Sampling period in microseconds
     * @param bool $has_timestamps Whether samples include timestamp deltas
     */
    public function __construct(
        $stream,
        private int $sampling_period_us = 10000,
        bool $has_timestamps = false,
    ) {
        $this->stream = $stream;
        $this->flags = $has_timestamps ? self::FLAG_HAS_TIMESTAMPS : 0;
    }

    public function writeHeader(): void
    {
        fwrite($this->stream, self::MAGIC);
        fwrite($this->stream, chr(self::VERSION));
        fwrite($this->stream, chr($this->flags));
        fwrite($this->stream, pack('v', 0)); // reserved 2 bytes
        fwrite($this->stream, pack('V', $this->sampling_period_us));
        fwrite($this->stream, pack('V', 0)); // reserved 4 bytes
    }

    /**
     * Define frames and stack without emitting a SAMPLE event.
     * Useful for priming a new segment with known definitions.
     *
     * @return int The stack_id for this trace
     */
    public function defineTrace(ParsedCallTrace $trace): int
    {
        $frame_ids = [];
        foreach ($trace->call_frames as $frame) {
            $frame_ids[] = $this->ensureFrame($frame);
        }
        return $this->ensureStack($frame_ids);
    }

    /**
     * Write a trace sample, emitting FRAME_DEF and STACK_DEF events as needed.
     *
     * @return int The stack_id used for this sample
     */
    public function writeTrace(ParsedCallTrace $trace, int $timestamp_delta_us = 0): int
    {
        $stack_id = $this->defineTrace($trace);
        $this->writeSample($stack_id, $timestamp_delta_us);

        return $stack_id;
    }

    public function writeCheckpoint(): void
    {
        $payload = Varint::encode($this->next_frame_id)
            . Varint::encode($this->next_stack_id)
            . Varint::encode($this->sample_count);
        $this->writeEvent(EventType::CHECKPOINT, $payload);
        $this->last_checkpoint_samples = $this->sample_count;
    }

    public function writeSegmentEnd(): void
    {
        $this->writeEvent(EventType::SEGMENT_END, '');
    }

    /**
     * Write a METADATA event (key-value pair).
     */
    public function writeMetadata(string $key, string $value): void
    {
        $payload = Varint::encode(strlen($key)) . $key
            . Varint::encode(strlen($value)) . $value;
        $this->writeEvent(EventType::METADATA, $payload);
    }

    /**
     * Write a PID_SAMPLE event (sample with process ID).
     */
    public function writePidSample(int $stack_id, int $pid, int $timestamp_delta_us = 0): void
    {
        $payload = Varint::encode($stack_id)
            . Varint::encode($pid);
        if (($this->flags & self::FLAG_HAS_TIMESTAMPS) !== 0) {
            $payload .= Varint::encode($timestamp_delta_us);
        }
        $this->writeEvent(EventType::PID_SAMPLE, $payload);
        $this->sample_count++;
    }

    /**
     * Write a trace as a PID_SAMPLE, emitting FRAME_DEF and STACK_DEF as needed.
     *
     * @return int The stack_id used for this sample
     */
    public function writePidTrace(ParsedCallTrace $trace, int $pid, int $timestamp_delta_us = 0): int
    {
        $stack_id = $this->defineTrace($trace);
        $this->writePidSample($stack_id, $pid, $timestamp_delta_us);
        return $stack_id;
    }

    public function getSampleCount(): int
    {
        return $this->sample_count;
    }

    public function getSamplesSinceCheckpoint(): int
    {
        return $this->sample_count - $this->last_checkpoint_samples;
    }

    /**
     * Write a FRAME_DEF if this frame hasn't been seen before.
     */
    private function ensureFrame(ParsedCallFrame $frame): int
    {
        $key = $frame->function_name . "\0" . $frame->file_name . "\0" . $frame->lineno;
        if (isset($this->frame_map[$key])) {
            return $this->frame_map[$key];
        }

        $frame_id = $this->next_frame_id++;
        $this->frame_map[$key] = $frame_id;
        $this->writeFrameDef($frame_id, $frame);

        return $frame_id;
    }

    /**
     * Write a STACK_DEF if this stack hasn't been seen before.
     *
     * @param int[] $frame_ids
     */
    private function ensureStack(array $frame_ids): int
    {
        $key = implode(',', $frame_ids);
        if (isset($this->stack_map[$key])) {
            return $this->stack_map[$key];
        }

        $stack_id = $this->next_stack_id++;
        $this->stack_map[$key] = $stack_id;
        $this->writeStackDef($stack_id, $frame_ids);

        return $stack_id;
    }

    private function writeFrameDef(int $frame_id, ParsedCallFrame $frame): void
    {
        $func_bytes = $frame->function_name;
        $file_bytes = $frame->file_name;

        $payload = Varint::encode($frame_id)
            . Varint::encode(strlen($func_bytes)) . $func_bytes
            . Varint::encode(strlen($file_bytes)) . $file_bytes
            . Varint::encode($frame->lineno);

        $this->writeEvent(EventType::FRAME_DEF, $payload);
    }

    /**
     * @param int[] $frame_ids
     */
    private function writeStackDef(int $stack_id, array $frame_ids): void
    {
        $payload = Varint::encode($stack_id)
            . Varint::encode(count($frame_ids));
        foreach ($frame_ids as $fid) {
            $payload .= Varint::encode($fid);
        }
        $this->writeEvent(EventType::STACK_DEF, $payload);
    }

    private function writeSample(int $stack_id, int $timestamp_delta_us): void
    {
        if (($this->flags & self::FLAG_HAS_TIMESTAMPS) !== 0) {
            $payload = Varint::encode($stack_id)
                . Varint::encode($timestamp_delta_us);
            $this->writeEvent(EventType::SAMPLE, $payload);
        } else {
            // Compact sample: no payload_length, just event_type + stack_id varint
            fwrite($this->stream, chr(EventType::COMPACT_SAMPLE->value));
            fwrite($this->stream, Varint::encode($stack_id));
        }
        $this->sample_count++;
    }

    private function writeEvent(EventType $type, string $payload): void
    {
        fwrite($this->stream, chr($type->value));
        fwrite($this->stream, Varint::encode(strlen($payload)));
        if ($payload !== '') {
            fwrite($this->stream, $payload);
        }
    }
}
