# reli Binary Trace Format (`.rbt`) Specification

**Version:** 1
**Status:** Draft

## Overview

The reli Binary Trace Format is an append-only binary stream format for efficient storage of PHP profiling traces. A `.rbt` file consists of one or more **self-contained segments**, each carrying its own header and definition events.

### Design Goals

- **Significantly smaller** than phpspy text format (2-5 bytes per sample)
- **Append-only** for safe incremental writes
- **Segment-based**: each segment is self-contained with its own header and definitions
- **Crash-recoverable**: discard incomplete trailing events or corrupt segments, recover the rest
- **Easy conversion** to pprof / speedscope / folded stacks / flamegraph
- **Timestamp-preserving**: optional per-sample timestamp deltas for time-series analysis

### Core Principles

- **Frames** (function name + file name + line number) are defined once per segment and assigned a `frame_id`
- **Stacks** (arrays of frame_ids) are defined once per segment and assigned a `stack_id`
- **Samples** reference only a `stack_id` (plus an optional timestamp delta)
- Avoiding repeated strings provides the compression benefit
- Each segment is independently decodable; frame/stack tables reset at segment boundaries

---

## File Structure

A `.rbt` file contains one or more segments concatenated sequentially:

```
[Segment_0] [Segment_1] ... [Segment_N]
```

Each segment:

```
[Header: 16 bytes] [Event_1] [Event_2] ... [Event_N]
```

### Header (fixed 16 bytes)

| Offset | Size | Field | Description |
|--------|------|-------|-------------|
| 0 | 4 | Magic | `"RELI"` (0x52 0x45 0x4C 0x49) |
| 4 | 1 | Version | `1` |
| 5 | 1 | Flags | bit 0: has_timestamps |
| 6 | 2 | Reserved | 0x0000 |
| 8 | 4 | Sampling Period | Sampling interval in microseconds, little-endian uint32 |
| 12 | 4 | Reserved | 0x00000000 |

### Flags

| Bit | Name | Description |
|-----|------|-------------|
| 0 | `has_timestamps` | When set, SAMPLE events include a timestamp delta |
| 1-7 | Reserved | Must be 0 |

---

## Segments

Each segment is **self-contained**: it includes its own header and all FRAME_DEF/STACK_DEF events needed to decode its SAMPLE events. This allows:

- Independent decoding of any segment
- Time-based rotation (e.g., 10s/30s/60s segments) for continuous profiling
- File rotation where each file contains one segment
- Concatenation of multiple segments into a single stream (e.g., stdout)

### Segment Boundaries

A segment ends when:
1. A **SEGMENT_END** event is encountered (clean shutdown), OR
2. A new **"RELI" magic** header is detected where an event type byte is expected, OR
3. The stream reaches **EOF**

**SEGMENT_END is not required.** It is a marker for clean shutdown. Its absence indicates the writer may have been interrupted (crash, kill signal, etc.), but all fully-written events before the interruption point are valid.

When a new segment starts, the reader **resets** its frame/stack tables. Frame IDs and stack IDs are scoped to their segment.

---

## Varint Encoding

IDs, lengths, depths, and timestamp deltas use **protobuf-compatible base-128 varints**.

- The lower 7 bits of each byte carry data
- MSB (bit 7) is the continuation flag: 1 = more bytes follow, 0 = final byte
- Little-endian byte order (least significant byte first)
- Unsigned integers only

| Value Range | Bytes |
|-------------|-------|
| 0 - 127 | 1 |
| 128 - 16,383 | 2 |
| 16,384 - 2,097,151 | 3 |

---

## Event Structure

All events are **length-delimited**:

```
[event_type: 1 byte] [payload_length: varint] [payload: payload_length bytes]
```

When an unknown `event_type` is encountered, skip `payload_length` bytes and proceed to the next event. This ensures forward compatibility with future event types.

**Reserved event type**: `0x52` ('R') is **reserved** and must never be used as an event type. It is the first byte of the "RELI" segment magic. The reader uses this byte to detect segment boundaries in a concatenated stream. If `0x52` is followed by `0x45 0x4C 0x49` ("ELI"), it is treated as a new segment header rather than an event.

### Event Types

| Type | Value | Description |
|------|-------|-------------|
| `FRAME_DEF` | 0x01 | Frame definition |
| `STACK_DEF` | 0x02 | Stack definition |
| `SAMPLE` | 0x03 | Sample event |
| `CHECKPOINT` | 0x04 | Checkpoint |
| `SEGMENT_END` | 0x05 | Segment terminator (optional) |

---

## Event Details

### FRAME_DEF (0x01)

Defines a new frame (function name + file name + line number tuple).

```
Payload:
  [frame_id: varint]
  [function_name_length: varint] [function_name: UTF-8 bytes]
  [file_name_length: varint]     [file_name: UTF-8 bytes]
  [lineno: varint]
```

- `frame_id` is assigned sequentially starting from 0 within each segment
- `function_name` uses `Class::method` format for class methods
- The same frame is never defined twice within a segment

### STACK_DEF (0x02)

Defines a stack as an array of frame IDs.

```
Payload:
  [stack_id: varint]
  [depth: varint]
  [frame_id_0: varint] [frame_id_1: varint] ... [frame_id_{depth-1}: varint]
```

- `stack_id` is assigned sequentially starting from 0 within each segment
- `frame_id_0` is the innermost frame (leaf function), `frame_id_{depth-1}` is the outermost (entry point)
- All referenced frame_ids must have been previously defined by FRAME_DEF events in the same segment

### SAMPLE (0x03)

Records a single sampling event.

```
Payload:
  [stack_id: varint]
  if flags.has_timestamps:
    [timestamp_delta_us: varint]
```

- `stack_id` must have been previously defined by a STACK_DEF event in the same segment
- `timestamp_delta_us` is the elapsed time in microseconds since the previous sample (0 for the first sample)

**Size**: When stack_id <= 127 and timestamps are disabled, each event is **3 bytes**.

### CHECKPOINT (0x04)

Periodically records the stream state for consistency verification during recovery.

```
Payload:
  [max_frame_id: varint]
  [max_stack_id: varint]
  [sample_count: varint]
```

### SEGMENT_END (0x05)

Indicates a clean end of a segment.

```
Payload: (empty, length = 0)
```

**This event is optional.** Its absence does not indicate data corruption; the writer may have been interrupted. All events written before the interruption point remain valid.

---

## Timestamps

When `flags.has_timestamps` is set:

- Each SAMPLE event includes a `timestamp_delta_us` field
- The delta is the elapsed microseconds since the previous sample
- For the first sample of a segment, delta is relative to the previous sample (even across segments in the segmented writer), or 0 if no previous sample exists
- The reader accumulates deltas to compute `accumulated_timestamp_us` per segment

Timestamps enable:
- Time-based segment rotation in `SegmentedBinaryTraceWriter`
- `duration_nanos` in pprof output
- Future time-series visualizations (speedscope timeline, Perfetto)

---

## Size Estimates

Assuming a typical workload of 100 samples/sec:

| Component | Size | Notes |
|-----------|------|-------|
| Header | 16 bytes | Once per segment |
| FRAME_DEF | ~40-80 bytes each | Depends on function/file name length |
| STACK_DEF | ~5-20 bytes each | Depends on stack depth |
| SAMPLE (repeated) | **3 bytes** | stack_id < 128, no timestamps |
| SAMPLE (with timestamp) | **5 bytes** | stack_id < 128, delta < 16384 |
| CHECKPOINT | ~5-10 bytes | Every 1000 samples |

Typical example (100 unique frames, 50 unique stacks):

```
Initial definitions: ~100 x 60 + 50 x 15 = ~6,750 bytes
1 hour of samples:   100 x 3600 x 3      = ~1,080,000 bytes = 1.03 MB
Total: ~1.04 MB/hour
```

Equivalent data in phpspy text format: ~50-100 MB/hour. **~40-100x compression**.

---

## Crash Recovery

The implementation provides two levels of recovery:

### Segment-Level Recovery (Primary)

1. Scan forward byte-by-byte for the "RELI" magic (4 bytes) to find segment starts
2. Read the 12-byte header remainder and validate version
3. Try to read events from this segment
4. If any error occurs (truncated payload, invalid reference, corrupt varint), discard the rest of this segment and scan for the next "RELI" magic
5. All samples successfully yielded before the error are retained

### Event-Level Recovery (Within a Segment)

- If `payload_length` exceeds 16 MB, the event is considered corrupt
- Unknown event types are skipped using `payload_length`
- Undefined `frame_id` or `stack_id` references cause the current segment to be abandoned
- Truncated trailing events (incomplete reads) cause the segment to end; all prior samples are valid

### Using the Recovery Command

```bash
# Recover to a clean single-segment .rbt
reli converter:binary-trace-recover < corrupted.rbt > recovered.rbt

# Recover directly to phpspy text
reli converter:binary-trace-recover -f phpspy < corrupted.rbt > recovered.txt
```

**Note:** The `-f rbt` output is a **re-encoded** file, not a byte-preserving repair of the original. The recovery command reads all recoverable samples from the input, then writes them into a clean single-segment `.rbt` file with fresh frame/stack IDs. The sampling period is preserved from the input header.

### CHECKPOINT Verification

CHECKPOINT events record `max_frame_id`, `max_stack_id`, and `sample_count`. These can be used to verify that the reader's internal state is consistent at known points in the stream.

---

## Segmented Writing

The `SegmentedBinaryTraceWriter` provides time-based segment rotation for continuous profiling scenarios (e.g., Pyroscope integration).

### Single Stream Mode (stdout)

Segments are concatenated in a single stream:

```php
$writer = new SegmentedBinaryTraceWriter(
    stream: STDOUT,
    sampling_period_us: 10000,
    segment_duration_us: 10_000_000, // 10 seconds
);

$writer->writeTrace($trace, $timestamp_us);
// ... more traces ...
$writer->finish();
```

### File Rotation Mode

Each segment is written to a separate file:

```php
$writer = new SegmentedBinaryTraceWriter(
    stream: null,
    sampling_period_us: 10000,
    segment_duration_us: 30_000_000, // 30 seconds
    stream_factory: function (int $segment_index) {
        return fopen("trace_{$segment_index}.rbt", 'wb');
    },
);
```

### Stream Ownership

The `SegmentedBinaryTraceWriter` **does not close** any stream — neither the `$stream` passed directly nor streams returned by `$stream_factory`. The **caller is responsible for closing** all streams after calling `finish()`. This avoids resource lifecycle ambiguity in long-running processes.

In file rotation mode, the caller should track the streams returned by the factory and close them when appropriate. A typical pattern:

```php
$streams = [];
$writer = new SegmentedBinaryTraceWriter(
    stream: null,
    stream_factory: function (int $i) use (&$streams) {
        $s = fopen("trace_{$i}.rbt", 'wb');
        $streams[$i] = $s;
        return $s;
    },
);
// ... write traces ...
$writer->finish();
foreach ($streams as $s) { fclose($s); }
```

### Segment Self-Containment

When a segment boundary is reached:
1. CHECKPOINT + SEGMENT_END are written to close the current segment
2. A new header is written
3. All previously seen FRAME_DEF and STACK_DEF events are re-emitted
4. New samples continue with the primed definition tables

This ensures each segment can be decoded independently.

---

## Future Extensions

The following are not implemented in v1 but can be added while maintaining backward compatibility:

- **New event types**: Unknown events are safely skipped via payload_length
- **Derived STACK_DEF**: Differential stack definitions based on an existing stack_id with 1-2 frames changed
- **THREAD_SAMPLE**: Sample event that includes a thread_id
- **METADATA**: Arbitrary key-value metadata events (e.g., segment start wall-clock time)
- **Compression**: Stream-level or segment-level zstd/gzip compression
- **Flag extensions**: Reserved bits are available for future use

---

## Conversion Pipeline

```
                                      +-> speedscope JSON
                                      |
phpspy text --> binary-trace-encode --+-> pprof protobuf (gzip)
                                      |
CallTrace --> BinaryTraceOutput      -+-> folded stacks -> flamegraph.pl
            | SegmentedBinaryTrace    |
              Output                  +-> phpspy text (decode)
                                      |
                                      +-> recovered .rbt (recover)
```

### CLI Commands

```bash
# phpspy text -> binary trace
reli converter:binary-trace-encode < trace.txt > trace.rbt

# binary trace -> phpspy text
reli converter:binary-trace-decode < trace.rbt

# binary trace -> speedscope JSON
reli converter:binary-trace-to-speedscope < trace.rbt > profile.json

# binary trace -> folded stacks (for flamegraph)
reli converter:binary-trace-to-folded < trace.rbt | flamegraph.pl > graph.svg

# binary trace -> pprof protobuf
reli converter:binary-trace-to-pprof < trace.rbt > profile.pb.gz

# recover corrupted/truncated binary trace
reli converter:binary-trace-recover < corrupted.rbt > recovered.rbt
reli converter:binary-trace-recover -f phpspy < corrupted.rbt > recovered.txt
```

### Live Capture Usage

Single-segment output:

```php
use Reli\Converter\BinaryTrace\BinaryTraceWriter;
use Reli\Inspector\Output\TraceOutput\BinaryTraceOutput;

$stream = fopen('trace.rbt', 'wb');
$writer = new BinaryTraceWriter($stream, sampling_period_us: 10000, has_timestamps: true);
$output = new BinaryTraceOutput($writer, checkpoint_interval: 1000);

// Implements TraceOutput, timestamps generated automatically via hrtime()
$output->output($call_trace);
```

Segmented output with time-based rotation:

```php
use Reli\Converter\BinaryTrace\SegmentedBinaryTraceWriter;
use Reli\Inspector\Output\TraceOutput\SegmentedBinaryTraceOutput;

$writer = new SegmentedBinaryTraceWriter(
    stream: fopen('trace.rbt', 'wb'),
    sampling_period_us: 10000,
    segment_duration_us: 10_000_000, // 10 seconds
);
$output = new SegmentedBinaryTraceOutput($writer);

// Implements TraceOutput, handles segment rotation automatically
$output->output($call_trace);
// ...
$output->finish();
```

---

## Implementation Files

| File | Description |
|------|-------------|
| `src/Converter/BinaryTrace/Varint.php` | Varint encode/decode |
| `src/Converter/BinaryTrace/EventType.php` | Event type enum |
| `src/Converter/BinaryTrace/BinaryTraceSample.php` | Rich sample type (trace + timestamps) |
| `src/Converter/BinaryTrace/BinaryTraceWriter.php` | Encoder with frame/stack deduplication |
| `src/Converter/BinaryTrace/BinaryTraceReader.php` | Decoder: multi-segment, recovery mode |
| `src/Converter/BinaryTrace/SegmentedBinaryTraceWriter.php` | Time-based segment rotation writer |
| `src/Converter/BinaryTrace/BinaryTraceException.php` | Exception class |
| `src/Converter/BinaryTrace/FoldedStacksFormatter.php` | Folded stacks formatter |
| `src/Converter/BinaryTrace/PprofEncoder.php` | pprof protobuf encoder |
| `src/Inspector/Output/TraceOutput/BinaryTraceOutput.php` | TraceOutput adapter (single segment) |
| `src/Inspector/Output/TraceOutput/SegmentedBinaryTraceOutput.php` | TraceOutput adapter (segmented) |
| `src/Command/Converter/BinaryTraceEncodeCommand.php` | phpspy -> binary CLI |
| `src/Command/Converter/BinaryTraceDecodeCommand.php` | binary -> phpspy CLI |
| `src/Command/Converter/BinaryTraceSpeedscopeCommand.php` | binary -> speedscope CLI |
| `src/Command/Converter/BinaryTraceFoldedCommand.php` | binary -> folded stacks CLI |
| `src/Command/Converter/BinaryTracePprofCommand.php` | binary -> pprof CLI |
| `src/Command/Converter/BinaryTraceRecoverCommand.php` | Recovery CLI |
