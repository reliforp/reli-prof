# reli Binary Trace Format (`.rbt`) Specification

**Version:** 1
**Status:** Draft

> [!NOTE]
> **Most users do not need this format specification.**
> To capture and read traces, start with
> [capturing-traces.md](capturing-traces.md) and
> [rbt-analyze-and-explore.md](rbt-analyze-and-explore.md). Read this
> page if you are writing a converter, debugging a corrupted file with
> `rbt:recover`, or integrating with the binary format from another
> language.

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

- **Strings** (namespace, class, method, file path) are interned via `STRING_DEF` — each unique string is stored once per segment
- **Frames** reference interned strings by ID and are defined once per segment via `FRAME_DEF`
- **Stacks** (arrays of frame_ids) are defined once per segment and assigned a `stack_id`
- **Samples** reference only a `stack_id`; in compact mode (`timestamps=none`), consecutive identical stacks are run-length encoded
- Each segment is independently decodable; all tables reset at segment boundaries

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
- Values are 64-bit signed integers; non-negatives take 1–9 bytes. Negative
  values follow protobuf's `int64` rules — encoded as their 64-bit
  two's-complement bit pattern and always take 10 bytes. The writer emits
  `lineno = -1` for internal-call / unknown-line PHP frames; other fields
  (IDs, lengths, deltas) are unsigned in practice and stay within 1–9 bytes.

| Value Range | Bytes |
|-------------|-------|
| 0 - 127 | 1 |
| 128 - 16,383 | 2 |
| 16,384 - 2,097,151 | 3 |
| any negative `int64` | 10 |

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
| `METADATA` | 0x06 | Key-value metadata |
| `PID_SAMPLE` | 0x07 | Sample with process ID |
| `COMPACT_SAMPLE` | 0x08 | Compact sample (no payload_length) |
| `REPEAT_SAMPLE` | 0x09 | Repeat last sample N times (no payload_length) |
| `STRING_DEF` | 0x0A | String definition for the intern table |
| `SAMPLE_ANNOTATION` | 0x0B | Key-value annotations for preceding sample |

---

## Event Details

### STRING_DEF (0x0A)

Defines a string in the per-segment intern table. Referenced by FRAME_DEF via string_id.

```
Payload:
  [string_id: varint] [string: remaining bytes, UTF-8]
```

- `string_id` is assigned sequentially starting from 0 within each segment
- Strings are interned for: namespace, class name, method name, file path
- Shared strings (e.g., the same namespace across many frames) are defined once and referenced by ID
- All STRING_DEFs referenced by a FRAME_DEF must appear before that FRAME_DEF

### FRAME_DEF (0x01)

Defines a new frame. The `flags` field determines whether this is a PHP frame or a native (C-level) frame.

```
Payload:
  [frame_id: varint]
  [flags: varint]

  if PHP frame (flags & 0x01 == 0):
    [namespace_string_id: varint]
    [class_string_id: varint]
    [method_string_id: varint]
    [file_string_id: varint]
    [lineno: varint]
    if flags & 0x02 (HAS_OPCODE):
      [opcode_string_id: varint]

  if native frame (flags & 0x01 == 1):
    [symbol_string_id: varint]
    [module_string_id: varint]
    [offset: varint]
```

#### Flags

| Bit | Name | Description |
|-----|------|-------------|
| 0 | `NATIVE` | 1 = native (C-level) frame, 0 = PHP frame |
| 1 | `HAS_OPCODE` | PHP frame includes an opcode name (e.g., `ZEND_DO_FCALL`) |

#### PHP Frames

- The reader reconstructs the function name as `Namespace\Class::method` (or subsets)
- Opcode name is optional; when present, it identifies the specific Zend VM instruction
- Opcode names are interned via STRING_DEF (~200 unique opcodes, high dedup)

#### Native Frames

- `symbol_string_id` — function symbol name (e.g., `zend_execute_scripts`)
- `module_string_id` — shared library name (e.g., `libphp.so`, `libc.so.6`)
- `offset` — offset from symbol start (unsigned)
- Module names are interned, so frames from the same library share one STRING_DEF
- Native frames appear in merged PHP + C-level traces (via `--with-native-trace`)

#### General

- `frame_id` is assigned sequentially starting from 0 within each segment
- The same frame is never defined twice within a segment
- String deduplication reduces definition size significantly when many frames share the same namespace, file path, or module

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

**Size**: When stack_id <= 127 and timestamps are enabled, each event is **5 bytes** (type + length + stack_id + delta). When timestamps are disabled, the writer uses COMPACT_SAMPLE (2 bytes) instead.

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

### METADATA (0x06)

Records a key-value metadata pair for the current segment. Typically written after the header, before sample events.

```
Payload:
  [key_length: varint]   [key: UTF-8 bytes]
  [value_length: varint] [value: UTF-8 bytes]
```

Common keys:
- `pid` — process ID being profiled (written per-segment by the daemon worker)
- `host` — hostname of the profiled machine

Metadata is scoped to the current segment and resets on segment boundaries.

### PID_SAMPLE (0x07)

Records a sample with an explicit process ID. Used in bundled daemon output where traces from multiple processes are interleaved in a single stream.

```
Payload:
  [stack_id: varint]
  [pid: varint]
  if flags.has_timestamps:
    [timestamp_delta_us: varint]
```

Semantically identical to SAMPLE except for the additional `pid` field. The reader exposes the PID via `BinaryTraceSample::$pid`.

### COMPACT_SAMPLE (0x08)

A minimal sample event used when timestamps are disabled (`has_timestamps=0`). Unlike all other events, COMPACT_SAMPLE has **no payload_length** — the varint-encoded stack_id is self-delimiting.

```
[0x08] [stack_id: varint]
```

**Size**: 2 bytes when stack_id <= 127.

This is the most compact representation of a sample. It is used automatically when the header's `has_timestamps` flag is 0. The reader detects event type 0x08 and reads the stack_id varint directly without a preceding payload_length.

**Note**: Because COMPACT_SAMPLE is not length-delimited, readers that do not recognize event type 0x08 cannot skip it safely. All readers at version >= 1 must handle this event type.

### REPEAT_SAMPLE (0x09)

Repeats the most recent **completed sample** a given number of times. A completed sample includes its stack and any annotations. Like COMPACT_SAMPLE, this event has **no payload_length**.

```
[0x09] [count: varint]
```

- `count` is the number of additional occurrences (e.g., count=4 means 4 more copies)
- The "completed sample" being repeated includes both the trace/stack and any `SAMPLE_ANNOTATION` that followed it
- A preceding completed sample must exist in the current segment; if not, the stream is corrupt
- `SAMPLE_ANNOTATION` **must not** follow `REPEAT_SAMPLE` — annotations attach only to the original sample event, and REPEAT copies the completed result

**Run key**: The writer considers two consecutive samples identical only if both their `stack_id` **and** their annotations match. If the annotation changes (e.g., different query string), the run is broken and a new completed sample is emitted.

**When used**: In `timestamps=none` mode, for runs of 3+ identical completed samples. Runs of 1-2 use individual events.

**Example**: PDO::execute with same query sampled 5 times:
```
COMPACT_SAMPLE(stack_id=3)
SAMPLE_ANNOTATION(query="SELECT * FROM users WHERE id = ?")
REPEAT_SAMPLE(4)
// Total: ~10 bytes instead of 5 × (COMPACT + ANNOTATION)
```

**Example**: Query changes mid-run:
```
COMPACT_SAMPLE(3)
SAMPLE_ANNOTATION(query="SELECT 1")
REPEAT_SAMPLE(2)              // 3 copies of "SELECT 1"
COMPACT_SAMPLE(3)
SAMPLE_ANNOTATION(query="SELECT 2")
REPEAT_SAMPLE(1)              // 2 copies of "SELECT 2"
```

**Segment boundaries**: The writer's run-length state is flushed on CHECKPOINT, SEGMENT_END, or writer destruction. The reader's completed sample state resets on each new segment.

### SAMPLE_ANNOTATION (0x0B)

Attaches key-value metadata to the immediately preceding sample event. Must appear directly after a SAMPLE, COMPACT_SAMPLE, or PID_SAMPLE event. **Must not** appear after REPEAT_SAMPLE.

```
Payload:
  [count: varint]
  [key_string_id: varint] [value_string_id: varint]  × count
```

- Keys and values reference the STRING_DEF intern table
- Multiple annotations per sample are supported (count > 1)
- Annotations are optional — samples without a following SAMPLE_ANNOTATION have `null` annotations
- The reader buffers each sample and attaches annotations before yielding, so consumers see the annotations on `BinaryTraceSample::$annotations`

**Use cases**:
- `query`: SQL query text from PDO::execute
- `http.url`: Request URL from HTTP client
- `http.method`: Request method
- Custom application-level metadata

**Example**:
```
COMPACT_SAMPLE(stack_id=3)
SAMPLE_ANNOTATION(count=2, "query"→"SELECT * FROM users", "db.system"→"mysql")
```

**Note**: When using COMPACT_SAMPLE with RLE, the writer must flush pending runs before writing annotations (so the COMPACT_SAMPLE event precedes the annotation in the stream).

---

## Timestamp Modes

The `--rbt-timestamps` option controls whether samples carry timestamp deltas:

| Mode | Default | Description |
|------|---------|-------------|
| `none` | yes | No timestamps. Uses COMPACT_SAMPLE (2 bytes) + REPEAT_SAMPLE RLE. Best for phpspy/pprof/folded. |
| `delta` | | Per-sample timestamp delta in µs. Uses length-delimited SAMPLE (5 bytes/sample). Needed for timeline/Perfetto. |

The mode is recorded in the segment header's `has_timestamps` flag (bit 0 of Flags byte).

---

## Timestamps (delta mode)

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

### Per-Event Sizes

| Component | Size | Notes |
|-----------|------|-------|
| Header | 16 bytes | Once per segment |
| STRING_DEF | varies | Each unique string once (namespace, class, method, file) |
| FRAME_DEF | ~6 bytes | 6 varints (frame_id + 4 string_ids + lineno) |
| STACK_DEF | ~5-20 bytes | Depends on stack depth |
| COMPACT_SAMPLE | **2 bytes** | `timestamps=none`, stack_id < 128 |
| REPEAT_SAMPLE | **2 bytes** | Run of N identical stacks compressed to 1 event |
| SAMPLE (with timestamp) | **5 bytes** | `timestamps=delta`, stack_id < 128 |
| CHECKPOINT | ~5-10 bytes | Every 1000 samples |

### Real-World Measurements

Measured on a ~9 minute PHP trace (~54,000 samples):

| Format | Size | Compression vs phpspy |
|--------|------|----------------------|
| phpspy text | 70 MB | 1x |
| speedscope JSON | 2 MB | 35x |
| **.rbt** (`timestamps=none`) | **180 KB** | **~370x** |
| .rbt + gzip | 92 KB | ~720x |
| pprof (from .rbt) | 106 KB | ~660x |

The compression comes from three layers:
1. **String interning**: shared namespace/class/file strings defined once (~60% definition reduction)
2. **COMPACT_SAMPLE**: 2 bytes per sample vs ~130 bytes in phpspy text
3. **REPEAT_SAMPLE RLE**: consecutive identical stacks (idle/spin) compressed to a single event

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
reli rbt:recover < corrupted.rbt > recovered.rbt

# Recover directly to phpspy text
reli rbt:recover -f phpspy < corrupted.rbt > recovered.txt
```

**Note:** The `-f rbt` output is a **re-encoded** file, not a byte-preserving repair of the original. The recovery command reads all recoverable samples from the input, then writes them into a clean single-segment `.rbt` file with fresh frame/stack IDs. The sampling period is taken from the **first successfully parsed** segment header.

### Sampling Period in Multi-Segment Conversions

When converting a multi-segment stream into a single output (pprof, recovered `.rbt`), only **one** sampling period value can be used. The converters use the value from the **last segment header** parsed before the period is resolved — in practice, this is the first segment for the recovery command and the last segment for the pprof command.

The format does not enforce that all segments share the same sampling period. However, tools that flatten multiple segments into a single profile (pprof's `Profile.period`, recovered `.rbt` header) **implicitly assume a uniform period**. If segments were captured with different periods, the resulting output will carry only one value and sample weights may be inaccurate. Callers producing multi-segment streams should use a consistent sampling period across all segments.

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

## Daemon Output Modes

When the daemon profiles multiple PHP processes concurrently, two output modes are available:

### Per-Worker File Output (`--output-format=rbt`)

Each worker process writes directly to its own file, bypassing IPC for trace data entirely.

```bash
# Explicit output directory
reli inspector:daemon -F rbt -o /path/to/output_dir/ ...

# Default: auto-creates a session directory under XDG_STATE_HOME
reli inspector:daemon -F rbt ...
# -> writes to $XDG_STATE_HOME/reli/daemon-traces/2026-04-09T163012Z_{pid}/
```

**Default output directory** (when `-o` is not specified):
- `$XDG_STATE_HOME/reli/daemon-traces/{session}/` if `XDG_STATE_HOME` is set
- `~/.local/state/reli/daemon-traces/{session}/` otherwise
- `{session}` is `{UTC timestamp}_{daemon PID}` (e.g., `2026-04-09T163012Z_12345`)
- The resolved path is printed to stderr at startup

- Workers write to `{output_dir}/worker_{pid}.rbt`
- **Each attach creates a new segment**: fresh header + fresh `BinaryTraceWriter` (frame/stack intern state is reset)
- Segment lifecycle per attach:
  1. `Header` (16 bytes)
  2. `METADATA(pid={target_pid})`
  3. `FRAME_DEF` / `STACK_DEF` / `SAMPLE` events
  4. `CHECKPOINT` + `SEGMENT_END` on detach
- A single `.rbt` file may contain multiple segments if the worker is reattached to a different target
- Each segment is self-contained and independently decodable
- **IPC carries only control messages** (attach/detach) — zero serialize overhead for traces
- Workers install a SIGTERM handler for clean shutdown (CHECKPOINT + SEGMENT_END on the in-flight segment)
- Files can be merged post-hoc: `cat output_dir/*.rbt > combined.rbt`
- Merge and convert in one step:
  ```bash
  cat output_dir/worker_*.rbt | reli converter:speedscope > combined.json
  cat output_dir/worker_*.rbt | reli converter:pprof > combined.pb.gz
  cat output_dir/worker_*.rbt | reli converter:folded > combined.folded
  # Also works with gzip-compressed files
  cat output_dir/worker_*.rbt.gz | reli converter:pprof > combined.pb.gz
  ```
- The sampling period in each segment header is derived from `--sleep-ns` (loop settings), not hardcoded

### Bundled Output (`--output-format=rbt-bundled`)

All traces are collected to the main process and written to a single stream using PID_SAMPLE events.

```bash
# Explicit output file
reli inspector:daemon -F rbt-bundled -o combined.rbt ...

# Default: writes to stdout (pipe-friendly)
reli inspector:daemon -F rbt-bundled ... > combined.rbt
```

- **Default output**: stdout (same as template modes), so it can be piped
- Single output file with one segment, simpler management
- Workers send `TraceMessage` (with PID) via IPC to the main process
- Main process writes `PID_SAMPLE` events that carry per-sample PID
- On clean shutdown (`q` key or signal), the main process writes `CHECKPOINT` + `SEGMENT_END` via a `finally` block
- If the output path is a file (not stdout), the stream is closed on shutdown
- The sampling period in the header is derived from `--sleep-ns`
- Higher IPC overhead than per-worker mode (though TraceMessage now carries PID, not full stack text)

---

## Output Format Selection

The `--output-format` (`-F`) option selects the output format for `inspector:trace` and `inspector:daemon`:

| Value | Description |
|-------|-------------|
| `template:phpspy` | phpspy-compatible text format (default) |
| `template:phpspy_with_opcode` | phpspy with opcode information |
| `template:json_lines` | JSON Lines format |
| `rbt` | Binary trace format (per-worker files in daemon mode) |
| `rbt-bundled` | Binary trace format with PID_SAMPLE (single file in daemon mode) |

The `--template` (`-t`) option is a backward-compatible alias for `--output-format=template:{name}`.

Additional options for rbt formats:

| Option | Values | Default | Description |
|--------|--------|---------|-------------|
| `--rbt-timestamps` | `none`, `delta` | `none` | Timestamp mode. `none` uses COMPACT_SAMPLE for minimal size. `delta` records per-sample timing. |
| `--rbt-compress` | flag | off | Gzip-compress completed segments. Produces `.rbt.gz` with concatenated gzip members. |

---

## Gzip Compression

`.rbt` files can be transparently compressed with gzip. The reader auto-detects gzip by checking for the gzip magic number (`0x1f 0x8b`) before the "RELI" header magic.

### Reading

All reader paths (converter commands, `rbt:recover`) accept both raw `.rbt` and gzip-compressed `.rbt.gz` input transparently:

```bash
# Both work identically
reli converter:folded < trace.rbt
reli converter:folded < trace.rbt.gz
```

### Writing

Use `--compress` with `converter:rbt` to produce gzip-compressed output:

```bash
reli converter:rbt --compress < trace.txt > trace.rbt.gz
```

### Daemon segment compression (`--rbt-compress`)

In daemon per-worker mode, `--rbt-compress` gzip-compresses each completed segment and writes concatenated gzip members to a single `.rbt.gz` file per worker:

```bash
reli inspector:daemon -F rbt --rbt-compress -o /path/to/output_dir/ ...
# Produces worker_{pid}.rbt.gz files with concatenated gzip members
```

- Each segment is written to `php://temp`, gzip-compressed on completion, and appended to the output file
- The resulting `.rbt.gz` contains concatenated gzip members (RFC 1952 compliant)
- Readable with `zcat`, `gzopen`, or reli's auto-detecting reader
- No raw `.rbt` data touches disk — only compressed data is written
- Without `--rbt-compress`, output is raw `.rbt` per worker (default, best for recovery/tail)

**Single-shot and bundled modes don't auto-suffix the filename.** Only daemon per-worker mode rewrites the extension, because there reli generates the per-worker filename itself (`worker_{pid}.rbt` → `worker_{pid}.rbt.gz`). In `inspector:trace` and `-F rbt-bundled`, `-o` is treated as the exact output path: `-o foo.rbt --rbt-compress` writes gzip content to a file named `foo.rbt`. `rbt:analyze` auto-detects gzip so the file still works, but external tools (`file`, `tar`) will see a `.rbt`-named gzip blob. Pass `-o foo.rbt.gz` explicitly if you want the extension to match the contents.

### When to use which

| Format | Best for |
|--------|----------|
| Raw `.rbt` | Live capture, append, tail, recovery |
| `.rbt.gz` (`--rbt-compress`) | Daemon long-run, archival, transfer, Pyroscope upload |

Raw `.rbt` is the default for live capture because it supports append-only writing, crash recovery, and real-time tailing. Gzip compression trades recovery for space efficiency.

### Compression modes by use case

**Daemon per-worker mode** (`inspector:daemon -F rbt --rbt-compress`): each worker writes a single `.rbt.gz` file. Completed segments are gzip-compressed and appended as concatenated gzip members. One file per worker, multiple segments inside.

**File rotation via `stream_factory`** (programmatic API): each segment is written to a separate file. With `compress_completed_segments=true`, each file receives exactly one gzip member. The writer closes each stream on rotation.

### Performance notes

- **Raw input**: The gzip auto-detection peeks 2 bytes and, for seekable streams, simply seeks back — no full copy of the input occurs
- **Gzip input**: The compressed data is expanded into `php://temp` (spills to disk for large inputs) before parsing
- **Gzip output**: `converter:rbt --compress` buffers to `php://temp` before compressing; `--rbt-compress` in daemon mode buffers each segment individually

---

## Future Extensions

The following can be added while maintaining backward compatibility:

- **New event types**: Unknown length-delimited events are safely skipped via payload_length
- **Derived STACK_DEF**: Differential stack definitions based on an existing stack_id with 1-2 frames changed
- **THREAD_SAMPLE**: Sample event that includes a thread_id
- **Flag extensions**: Reserved header bits are available for future use
- **Pyroscope integration**: Segment-level export for continuous profiling aggregation

---

## Conversion Pipeline

All converter commands auto-detect the input format (rbt or phpspy text) by peeking the first 4 bytes for the "RELI" magic.

```
                              +-> converter:speedscope -> JSON
                              |
  phpspy text or .rbt input --+-> converter:pprof      -> protobuf (gzip)
                              |
  CallTrace --> BinaryTrace   +-> converter:folded     -> folded stacks
    Output / Segmented        |
                              +-> converter:phpspy     -> phpspy text
                              |
                              +-> converter:rbt        -> .rbt binary
                              |
                              +-> rbt:recover          -> recovered .rbt
```

### CLI Commands

```bash
# Any input → speedscope JSON (auto-detects rbt or phpspy)
reli converter:speedscope < trace.rbt > profile.json
reli converter:speedscope < trace.txt > profile.json

# Any input → folded stacks
reli converter:folded < trace.rbt | flamegraph.pl > graph.svg

# Any input → pprof protobuf
reli converter:pprof < trace.rbt > profile.pb.gz

# Any input → callgrind
reli converter:callgrind < trace.rbt > callgrind.out

# Any input → phpspy text
reli converter:phpspy < trace.rbt

# Any input → rbt binary
reli converter:rbt < trace.txt > trace.rbt

# Recover corrupted/truncated rbt
reli rbt:recover < corrupted.rbt > recovered.rbt
reli rbt:recover -f phpspy < corrupted.rbt > recovered.txt

# Flamegraph (phpspy input only, pipes through perl scripts)
reli converter:flamegraph < trace.txt > graph.svg
```

### Analyzing `.rbt` traces in the terminal

Two CLIs read `.rbt` directly without going through speedscope or pprof:

```bash
# One-shot text reports (pipe-friendly, agent-friendly)
reli rbt:analyze < trace.rbt
reli rbt:analyze --callers='Doctrine\\ORM' --hide='^Symfony\\' < trace.rbt
reli rbt:analyze --last=5 < trace.rbt   # tail the most recent sample stacks

# Interactive sandwich/flame/tree TUI
reli rbt:explore trace.rbt
```

See [rbt-analyze-and-explore.md](rbt-analyze-and-explore.md) for the
full option reference, recipes, and the explorer's keybindings.

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
| `src/Converter/TraceInputReader.php` | Auto-detecting input reader (rbt or phpspy) |
| `src/Command/Converter/RbtCommand.php` | converter:rbt CLI |
| `src/Command/Converter/PhpspyCommand.php` | converter:phpspy CLI |
| `src/Command/Converter/FoldedCommand.php` | converter:folded CLI |
| `src/Command/Converter/PprofCommand.php` | converter:pprof CLI |
| `src/Command/Rbt/RecoverCommand.php` | rbt:recover CLI |
