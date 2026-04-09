# reli Binary Trace Format (`.rbt`) Specification

**Version:** 1
**Status:** Draft

## Overview

The reli Binary Trace Format is an append-only binary stream format for efficient storage of PHP profiling traces.

### Design Goals

- **Significantly smaller** than phpspy text format (2-5 bytes per sample)
- **Append-only** for safe incremental writes
- **Crash-recoverable**: discard incomplete trailing events and recover the rest
- **Easy conversion** to pprof / speedscope / folded stacks / flamegraph

### Core Principles

- **Frames** (function name + file name + line number) are defined once and assigned a `frame_id`
- **Stacks** (arrays of frame_ids) are defined once and assigned a `stack_id`
- **Samples** reference only a `stack_id` (plus an optional timestamp delta)
- Avoiding repeated strings provides the compression benefit

---

## File Structure

```
[Header: 16 bytes] [Event1] [Event2] ... [EventN]
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

When an unknown `event_type` is encountered, skip `payload_length` bytes and proceed to the next event.

### Event Types

| Type | Value | Description |
|------|-------|-------------|
| `FRAME_DEF` | 0x01 | Frame definition |
| `STACK_DEF` | 0x02 | Stack definition |
| `SAMPLE` | 0x03 | Sample event |
| `CHECKPOINT` | 0x04 | Checkpoint |
| `SEGMENT_END` | 0x05 | Segment terminator |

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

- `frame_id` is assigned sequentially starting from 0
- `function_name` uses `Class::method` format for class methods
- The same frame is never defined twice

### STACK_DEF (0x02)

Defines a stack as an array of frame IDs.

```
Payload:
  [stack_id: varint]
  [depth: varint]
  [frame_id_0: varint] [frame_id_1: varint] ... [frame_id_{depth-1}: varint]
```

- `stack_id` is assigned sequentially starting from 0
- `frame_id_0` is the innermost frame (leaf function), `frame_id_{depth-1}` is the outermost (entry point)
- All referenced frame_ids must have been previously defined by FRAME_DEF events

### SAMPLE (0x03)

Records a single sampling event.

```
Payload:
  [stack_id: varint]
  if flags.has_timestamps:
    [timestamp_delta_us: varint]
```

- `stack_id` must have been previously defined by a STACK_DEF event
- `timestamp_delta_us` is the elapsed time in microseconds since the previous sample

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

---

## Size Estimates

Assuming a typical workload of 100 samples/sec:

| Component | Size | Notes |
|-----------|------|-------|
| Header | 16 bytes | Once per file |
| FRAME_DEF | ~40-80 bytes each | Depends on function/file name length |
| STACK_DEF | ~5-20 bytes each | Depends on stack depth |
| SAMPLE (repeated) | **3 bytes** | stack_id < 128, no timestamps |
| CHECKPOINT | ~5-10 bytes | Every 1000 samples |

Typical example (100 unique frames, 50 unique stacks):

```
Initial definitions: ~100 x 60 + 50 x 15 = ~6,750 bytes
1 hour of samples:   100 x 3600 x 3      = ~1,080,000 bytes = 1.03 MB
Total: ~1.04 MB/hour
```

Equivalent data in phpspy text format: ~50-100 MB/hour. **~40-100x compression**.

---

## Crash Recovery Procedure

1. Read the header (16 bytes)
2. Parse events sequentially from the start
3. If an `event_type` is invalid, or `payload_length` bytes cannot be read:
   - Retain the definition state up to the last complete event
   - Scan forward byte-by-byte looking for a valid `event_type` (0x01-0x05)
   - When a candidate is found, verify that the following `payload_length` varint and payload are well-formed
   - Resume reading from the position where a valid event sequence restarts
4. Use CHECKPOINT events to verify consistency of `max_frame_id`, `max_stack_id`, and `sample_count`

---

## Future Extensions

The following are not implemented in v1 but can be added while maintaining backward compatibility:

- **New event types**: Unknown events are safely skipped via payload_length
- **Derived STACK_DEF**: Differential stack definitions based on an existing stack_id with 1-2 frames changed
- **THREAD_SAMPLE**: Sample event that includes a thread_id
- **METADATA**: Arbitrary key-value metadata events
- **Compression**: Stream-level or segment-level zstd/gzip compression
- **Flag extensions**: Reserved bits are available for future use

---

## Conversion Pipeline

```
                                    +-> speedscope JSON
                                    |
phpspy text -> binary-trace-encode -+-> pprof protobuf (gzip)
                                    |
CallTrace --> BinaryTraceOutput    -+-> folded stacks -> flamegraph.pl
                                    |
                                    +-> phpspy text (decode)
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
```

### Live Capture Usage

```php
use Reli\Converter\BinaryTrace\BinaryTraceWriter;
use Reli\Inspector\Output\TraceOutput\BinaryTraceOutput;

$stream = fopen('trace.rbt', 'wb');
$writer = new BinaryTraceWriter($stream, sampling_period_us: 10000);
$output = new BinaryTraceOutput($writer, checkpoint_interval: 1000);

// Implements the TraceOutput interface, so it can be used
// directly in existing profiling loops
$output->output($call_trace);
```

---

## Implementation Files

| File | Description |
|------|-------------|
| `src/Converter/BinaryTrace/Varint.php` | Varint encode/decode |
| `src/Converter/BinaryTrace/EventType.php` | Event type enum |
| `src/Converter/BinaryTrace/BinaryTraceWriter.php` | Encoder with frame/stack deduplication |
| `src/Converter/BinaryTrace/BinaryTraceReader.php` | Decoder yielding ParsedCallTrace |
| `src/Converter/BinaryTrace/BinaryTraceException.php` | Exception class |
| `src/Converter/BinaryTrace/FoldedStacksFormatter.php` | Folded stacks formatter |
| `src/Converter/BinaryTrace/PprofEncoder.php` | pprof protobuf encoder |
| `src/Inspector/Output/TraceOutput/BinaryTraceOutput.php` | TraceOutput adapter for live capture |
| `src/Command/Converter/BinaryTraceEncodeCommand.php` | phpspy -> binary CLI |
| `src/Command/Converter/BinaryTraceDecodeCommand.php` | binary -> phpspy CLI |
| `src/Command/Converter/BinaryTraceSpeedscopeCommand.php` | binary -> speedscope CLI |
| `src/Command/Converter/BinaryTraceFoldedCommand.php` | binary -> folded stacks CLI |
| `src/Command/Converter/BinaryTracePprofCommand.php` | binary -> pprof CLI |
