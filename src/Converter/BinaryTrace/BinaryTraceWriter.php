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

    // FRAME_DEF flags
    public const FRAME_FLAG_NATIVE = 0x01;
    public const FRAME_FLAG_HAS_OPCODE = 0x02;

    /** @var array<string, int> string => string_id */
    private array $string_map = [];
    private int $next_string_id = 0;

    /** @var array<string, int> frame_key => frame_id */
    private array $frame_map = [];
    private int $next_frame_id = 0;

    /** @var array<string, int> stack_key => stack_id */
    private array $stack_map = [];
    private int $next_stack_id = 0;

    private int $sample_count = 0;
    private int $last_checkpoint_samples = 0;

    // Run-length state for compact (no-timestamp) samples.
    // Tracks the completed sample (stack_id + annotations).
    private ?int $pending_compact_stack_id = null;
    /** @var array<string, string>|null */
    private ?array $pending_annotations = null;
    private int $pending_run_count = 0;

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

    public function __destruct()
    {
        /** @psalm-suppress RedundantConditionGivenDocblockType stream may be closed */
        if (is_resource($this->stream)) {
            $this->flushPendingRun();
        }
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
     * Write a trace sample, emitting STRING_DEF, FRAME_DEF and STACK_DEF events as needed.
     *
     * @param array<string, string>|null $annotations optional per-sample
     *     key/value metadata. When non-empty, a SAMPLE_ANNOTATION event is
     *     emitted directly after the SAMPLE / COMPACT_SAMPLE; annotation
     *     strings are interned via STRING_DEF, so repeated values cost
     *     only one varint pair per sample after the first.
     * @return int The stack_id used for this sample
     */
    public function writeTrace(
        ParsedCallTrace $trace,
        int $timestamp_delta_us = 0,
        ?array $annotations = null,
    ): int {
        return $this->writeAnnotatedTrace($trace, $timestamp_delta_us, $annotations);
    }

    /**
     * Write a trace sample with optional annotations.
     * In compact (no-timestamp) mode, consecutive identical samples
     * (same stack + same annotations) are run-length encoded.
     *
     * @param array<string, string>|null $annotations
     * @return int The stack_id used for this sample
     */
    public function writeAnnotatedTrace(
        ParsedCallTrace $trace,
        int $timestamp_delta_us = 0,
        ?array $annotations = null,
    ): int {
        $stack_id = $this->defineTrace($trace);
        $this->writeSample($stack_id, $timestamp_delta_us, $annotations);

        return $stack_id;
    }

    public function writeCheckpoint(): void
    {
        $this->flushPendingRun();
        $payload = Varint::encode($this->next_frame_id)
            . Varint::encode($this->next_stack_id)
            . Varint::encode($this->sample_count);
        $this->writeEvent(EventType::CHECKPOINT, $payload);
        $this->last_checkpoint_samples = $this->sample_count;
    }

    public function writeSegmentEnd(): void
    {
        $this->flushPendingRun();
        $this->writeEvent(EventType::SEGMENT_END, '');
    }

    /**
     * Write a METADATA event (key-value pair).
     */
    public function writeMetadata(string $key, string $value): void
    {
        $this->flushPendingRun();
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
     * Emit a SAMPLE_ANNOTATION event directly.
     * Internal use only — callers should use writeAnnotatedTrace().
     *
     * @param array<string, string> $annotations
     */
    private function emitAnnotation(array $annotations): void
    {
        $payload = Varint::encode(count($annotations));
        foreach ($annotations as $key => $value) {
            $payload .= Varint::encode($this->internString($key));
            $payload .= Varint::encode($this->internString($value));
        }
        $this->writeEvent(EventType::SAMPLE_ANNOTATION, $payload);
    }

    /**
     * Write a trace as a PID_SAMPLE, emitting FRAME_DEF and STACK_DEF as needed.
     *
     * When `$annotations` is non-empty, a SAMPLE_ANNOTATION event is emitted
     * directly after the PID_SAMPLE (the rbt spec allows SAMPLE_ANNOTATION to
     * follow PID_SAMPLE just like SAMPLE / COMPACT_SAMPLE). Annotation keys
     * and values are interned via STRING_DEF. The PID_SAMPLE path does not
     * participate in run-length encoding — interleaved per-sample PIDs break
     * runs by construction — so the annotation append is straight-line with
     * no pending-run bookkeeping.
     *
     * @param array<string, string>|null $annotations
     * @return int The stack_id used for this sample
     */
    public function writePidTrace(
        ParsedCallTrace $trace,
        int $pid,
        int $timestamp_delta_us = 0,
        ?array $annotations = null,
    ): int {
        $stack_id = $this->defineTrace($trace);
        $this->writePidSample($stack_id, $pid, $timestamp_delta_us);
        if ($annotations !== null && $annotations !== []) {
            $this->emitAnnotation($annotations);
        }
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
     * Intern a string and emit STRING_DEF if new.
     */
    private function internString(string $value): int
    {
        if (isset($this->string_map[$value])) {
            return $this->string_map[$value];
        }

        $string_id = $this->next_string_id++;
        $this->string_map[$value] = $string_id;

        $payload = Varint::encode($string_id) . $value;
        $this->writeEvent(EventType::STRING_DEF, $payload);

        return $string_id;
    }

    /**
     * Decompose a function_name into (namespace, class, method).
     *
     * Examples:
     *   "App\Http\Controllers\UserController::index"
     *     → ("App\Http\Controllers", "UserController", "index")
     *   "App\Helpers\format_date"
     *     → ("App\Helpers", "", "format_date")
     *   "array_map"
     *     → ("", "", "array_map")
     *
     * @return array{string, string, string} [namespace, class, method]
     */
    public static function decomposeFunctionName(string $function_name): array
    {
        // Split class::method first
        $class_part = '';
        $method = $function_name;
        $double_colon = strrpos($function_name, '::');
        if ($double_colon !== false) {
            $class_part = substr($function_name, 0, $double_colon);
            $method = substr($function_name, $double_colon + 2);
        }

        // Split namespace from class (or from function if no class)
        $subject = $class_part !== '' ? $class_part : $method;
        $last_ns_sep = strrpos($subject, '\\');
        if ($last_ns_sep !== false) {
            $namespace = substr($subject, 0, $last_ns_sep);
            $short = substr($subject, $last_ns_sep + 1);
            if ($class_part !== '') {
                return [$namespace, $short, $method];
            }
            // Namespaced function (no class)
            return [$namespace, '', $short];
        }

        // No namespace
        if ($class_part !== '') {
            return ['', $class_part, $method];
        }
        return ['', '', $method];
    }

    /**
     * Write STRING_DEFs and FRAME_DEF if this frame hasn't been seen before.
     */
    private function ensureFrame(ParsedCallFrame $frame): int
    {
        $key = $frame->function_name . "\0" . $frame->file_name . "\0"
            . $frame->lineno . "\0" . $frame->frame_type
            . "\0" . ($frame->opcode_name ?? '')
            . "\0" . ($frame->symbol_offset ?? '');
        if (isset($this->frame_map[$key])) {
            return $this->frame_map[$key];
        }

        $frame_id = $this->next_frame_id++;
        $this->frame_map[$key] = $frame_id;

        if ($frame->isNative()) {
            $this->writeNativeFrameDef($frame_id, $frame);
        } else {
            $this->writePhpFrameDef($frame_id, $frame);
        }

        return $frame_id;
    }

    private function writePhpFrameDef(int $frame_id, ParsedCallFrame $frame): void
    {
        $flags = 0;
        if ($frame->opcode_name !== null) {
            $flags |= self::FRAME_FLAG_HAS_OPCODE;
        }

        [$namespace, $class, $method] = self::decomposeFunctionName($frame->function_name);
        $ns_sid = $this->internString($namespace);
        $class_sid = $this->internString($class);
        $method_sid = $this->internString($method);
        $file_sid = $this->internString($frame->file_name);

        $payload = Varint::encode($frame_id)
            . Varint::encode($flags)
            . Varint::encode($ns_sid)
            . Varint::encode($class_sid)
            . Varint::encode($method_sid)
            . Varint::encode($file_sid)
            . Varint::encode($frame->lineno);

        if ($frame->opcode_name !== null) {
            $opcode_sid = $this->internString($frame->opcode_name);
            $payload .= Varint::encode($opcode_sid);
        }

        $this->writeEvent(EventType::FRAME_DEF, $payload);
    }

    private function writeNativeFrameDef(int $frame_id, ParsedCallFrame $frame): void
    {
        $symbol_sid = $this->internString($frame->function_name);
        $module_sid = $this->internString($frame->module_name ?? '');

        $payload = Varint::encode($frame_id)
            . Varint::encode(self::FRAME_FLAG_NATIVE)
            . Varint::encode($symbol_sid)
            . Varint::encode($module_sid)
            . Varint::encode($frame->symbol_offset ?? 0);

        $this->writeEvent(EventType::FRAME_DEF, $payload);
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

    /**
     * @param array<string, string>|null $annotations
     */
    private function writeSample(
        int $stack_id,
        int $timestamp_delta_us,
        ?array $annotations = null,
    ): void {
        if (($this->flags & self::FLAG_HAS_TIMESTAMPS) !== 0) {
            // Timestamped: no RLE, write immediately
            $payload = Varint::encode($stack_id)
                . Varint::encode($timestamp_delta_us);
            $this->writeEvent(EventType::SAMPLE, $payload);
            if ($annotations !== null && $annotations !== []) {
                $this->emitAnnotation($annotations);
            }
            $this->sample_count++;
            return;
        }

        // Compact (no-timestamp) path with run-length encoding.
        // The run key is (stack_id, annotations) — the completed sample.
        if (
            $this->pending_compact_stack_id === $stack_id
            && $this->pending_annotations === $annotations
        ) {
            $this->pending_run_count++;
        } else {
            $this->flushPendingRun();
            $this->pending_compact_stack_id = $stack_id;
            $this->pending_annotations = $annotations;
            $this->pending_run_count = 1;
        }
        $this->sample_count++;
    }

    /**
     * Flush any pending run-length encoded compact samples.
     */
    public function flushPendingRun(): void
    {
        if ($this->pending_compact_stack_id === null || $this->pending_run_count === 0) {
            return;
        }

        $stack_id = $this->pending_compact_stack_id;
        $annotations = $this->pending_annotations;
        $count = $this->pending_run_count;

        // Emit the first completed sample: COMPACT_SAMPLE + optional ANNOTATION
        fwrite($this->stream, chr(EventType::COMPACT_SAMPLE->value));
        fwrite($this->stream, Varint::encode($stack_id));
        if ($annotations !== null && $annotations !== []) {
            $this->emitAnnotation($annotations);
        }

        // REPEAT for runs of 3+ (repeats the completed sample including annotations)
        $remaining = $count - 1;
        if ($remaining >= 2) {
            fwrite($this->stream, chr(EventType::REPEAT_SAMPLE->value));
            fwrite($this->stream, Varint::encode($remaining));
        } elseif ($remaining === 1) {
            // Run of 2: emit a second completed sample
            fwrite($this->stream, chr(EventType::COMPACT_SAMPLE->value));
            fwrite($this->stream, Varint::encode($stack_id));
            if ($annotations !== null && $annotations !== []) {
                $this->emitAnnotation($annotations);
            }
        }

        $this->pending_compact_stack_id = null;
        $this->pending_annotations = null;
        $this->pending_run_count = 0;
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
