# Design: `inspector:watch` Command

## Overview

Add an `inspector:watch` command that continuously monitors PHP processes and automatically
executes profiling actions (trace capture, memory dump, log recording, etc.) when
condition-based triggers fire.

Unlike the existing `inspector:top` (real-time display) and `inspector:daemon` (multi-process tracing),
this specializes in passive monitoring that **"only takes action when conditions are met."**

### Motivation

- Detect early signs of memory leaks and automatically take snapshots when thresholds are reached
- Capture the moment a specific heavy function is called
- Record call stack state when exceptions occur
- Low-overhead monitoring in production environments (only reads heap statistics when conditions are not met)

## CLI Interface

```bash
# Basic: single process, capture trace on memory threshold
reli inspector:watch -p <PID> \
  --memory-limit=256M \
  --action=trace

# Daemon mode: monitor multiple processes simultaneously
reli inspector:watch --target-regex="php-fpm" \
  --memory-limit=512M \
  --memory-growth-rate=10M/min \
  --action=memory-dump \
  --action-output-dir=/var/log/reli-watch/

# Function detection + exception detection
reli inspector:watch -p <PID> \
  --watch-function="App\\HeavyService::process" \
  --on-exception \
  --action=trace \
  --action=log

# Multiple triggers + custom command execution
reli inspector:watch --target-regex="artisan" \
  --memory-limit=256M \
  --trace-depth-limit=200 \
  --action=exec --action-exec-command="curl -s -X POST https://hooks.example.com/alert" \
  --cooldown=30

# Monitor global variable array size
reli inspector:watch -p <PID> \
  --watch-global-array-size='cache:10000' \
  --action=memory-dump

# Monitor variable values (global variables)
reli inspector:watch -p <PID> \
  --watch-var='global::retry_count:gt:5' \
  --action=trace --action=log

# Monitor class static properties
reli inspector:watch -p <PID> \
  --watch-var='static::App\Cache::$size:gt:100000' \
  --action=memory-dump

# Monitor local variables
reli inspector:watch -p <PID> \
  --watch-var='local::items:count_gt:10000' \
  --watch-function="App\\process" \
  --action=trace

# Monitor function static variables
reli inspector:watch -p <PID> \
  --watch-var='func_static::App\retry::$attempt:gt:10' \
  --action=log
```

## Trigger System

### Tier 1: Lightweight Triggers (heap statistics only, 1-2 process_vm_readv calls)

| Trigger | Option | Description |
|---------|--------|-------------|
| Memory Limit | `--memory-limit=<size>` | `ZendMmHeap::$size` exceeds threshold |
| Memory Growth Rate | `--memory-growth-rate=<size>/<period>` | Growth rate over the last N samples exceeds threshold |
| Memory Peak Watch | `--memory-peak-watch` | Fires each time `ZendMmHeap::$peak` is updated |

**Implementation key points:**
The `size`, `real_size`, and `peak` fields of `ZendMmHeap` can be read with a single `process_vm_readv` call.
The existing `MemoryLocationsCollector` performs a full scan (hundreds of ms to seconds), but this path
only reads a few fields from `ZendMmChunk::heap_slot` to `ZendMmHeap` and completes in < 1ms.

### Tier 2: Trace-Dependent Triggers (call stack reading, tens of process_vm_readv calls)

| Trigger | Option | Description |
|---------|--------|-------------|
| Function Detection | `--watch-function=<name>` | Specified function appears in the call stack |
| Trace Depth Limit | `--trace-depth-limit=<N>` | Call stack exceeds N levels |

**Implementation key points:**
Uses the existing `CallTraceReader::readCallTrace()` directly.
When at least one Tier 2 trigger is enabled, the trace is read on every poll.
Consistency can be ensured by combining with `--stop-process` (`-S`).

### Tier 3: Advanced Triggers (deep reading of PHP internal structures)

| Trigger | Option | Description |
|---------|--------|-------------|
| Exception Detection | `--on-exception` | `EG->exception` is non-null (exception in flight) |
| Global Array Size | `--watch-global-array-size=<name>:<limit>` | `nNumOfElements` of an array in `EG->symbol_table` exceeds threshold |
| Variable Value | `--watch-var=<scope>::<name>:<op>:<value>` | Fires when a variable in the specified scope meets the condition |

**Implementation key points:**

**Variable Value (`--watch-var`):**

Variables in four scopes can be monitored. The scope is specified in the format `<scope>::<name>`.

```bash
# Global variables
--watch-var='global::counter:gt:1000'
--watch-var='global::status:eq:error'

# Local variables (variables in the current call frame)
--watch-var='local::items:count_gt:10000'
--watch-var='local::retries:gte:3'

# Class static properties
--watch-var='static::App\Cache::$entries:count_gt:50000'
--watch-var='static::App\Config::$debug:eq:true'

# Function static variables
--watch-var='func_static::App\retry::$attempt:gt:10'
```

**Read paths for each scope:**

| Scope | Internal Structure | Access Path |
|-------|-------------------|-------------|
| `global` | `EG->symbol_table` | Lookup by key from `ZendArray` -> `Zval` |
| `local` | CV area immediately after `zend_execute_data` | `EG->current_execute_data` -> `ZendOpArray->last_var` + resolve index via variable name table -> `execute_data + sizeof(zend_execute_data) + index * sizeof(zval)` |
| `static` | `ZendClassEntry->static_members_table` | Lookup class name from `EG->class_table` -> `ZendClassEntry` -> `static_members_table` + resolve offset via `PropertyInfo` |
| `func_static` | `ZendOpArray->static_variables` | Lookup function name from `EG->function_table` -> `ZendOpArray->static_variables` (`ZendArray`) lookup by key |

**Comparison operators:**

| Operator | Target Types | Description |
|----------|-------------|-------------|
| `eq`, `ne` | All types | Equality / inequality |
| `gt`, `lt`, `gte`, `lte` | `IS_LONG`, `IS_DOUBLE` | Numeric comparison |
| `contains` | `IS_STRING` | Substring match |
| `count_gt`, `count_lt`, `count_eq` | `IS_ARRAY` | Comparison of array element count |
| `is_null` | All types | `IS_NULL` check |

**Existing type reading infrastructure:**
- `ZendArray`: Hash table traversal (`iterateBucket()`, etc.)
- `Zval`: Type determination (`type` field) + value reading
- `ZendClassEntry`: Class name resolution + `static_members_table` access
- `ZendOpArray`: `static_variables` (ZendArray), `last_var`, variable name table
- `ZendExecuteData`: `symbol_table`, `func`, CV area access

**Exception Detection:**
The `zend_executor_globals` C struct has a `zend_object *exception` field
(`src/Lib/PhpInternals/Headers/v84.h:916`). The current `ZendExecutorGlobals.php` does not
expose it, so the `exception` pointer field needs to be added.
This is lightweight since it only requires a non-null pointer check (1 additional `process_vm_readv` call).

```php
// Add to ZendExecutorGlobals.php
/** @var Pointer<ZendObject>|null */
public ?Pointer $exception;
```

**Global Array Size:**
Looks up the variable name as a key from `EG->symbol_table` (type `ZendArray`),
and if the corresponding zval is `IS_ARRAY`, reads `ZendArray::$nNumOfElements`.
Hash table traversal of `ZendArray` is required, but global variable names are
often near the beginning of the symbol table, making this practically fast.

## Action System

### Built-in Actions

| Action | `--action=` value | Description |
|--------|-------------------|-------------|
| Trace Capture | `trace` | Output call trace |
| Memory Dump | `memory-dump` | Full memory profiling (JSON/SQLite3) (default) |
| Event Log | `log` | Record timestamp, PID, and trigger information to log |
| Exec | `exec` | Execute an external command |

Multiple `--action` options can be specified. If none are specified, `memory-dump` is the default.

### Trace Capture (`--action=trace`)

Uses the existing `CallTraceReader` + `TraceOutputFactory`.
When Tier 2 triggers are enabled, reuses the trace already captured during trigger evaluation.

### Memory Dump (`--action=memory-dump`)

Captures a full snapshot using the existing `MemoryLocationsCollector` + `MemoryOutputFactory`.
Outputs as `watch-<PID>-<timestamp>.<format>` under `--action-output-dir`.
Format is specified with `--memory-output-format={json,sqlite3}`.

**Note:** Full memory scans are heavy (hundreds of ms to seconds). It is recommended to
stop the process with `-S` during action execution.

### Event Log (`--action=log`)

```
[2026-03-29T12:34:56+09:00] PID=1234 trigger=memory-limit value=268435456 threshold=256M
[2026-03-29T12:35:30+09:00] PID=1234 trigger=watch-function function="App\HeavyService::process" depth=45
[2026-03-29T12:36:01+09:00] PID=5678 trigger=on-exception
```

Output destination is specified with `--log-file=<path>`. If not specified, defaults to stderr.

### Exec (`--action=exec`)

Executes the command specified with `--action-exec-command=<command>`.
Context is passed via environment variables:

| Environment Variable | Description |
|---------------------|-------------|
| `RELI_WATCH_PID` | PID of the target process |
| `RELI_WATCH_TRIGGER` | Name of the fired trigger |
| `RELI_WATCH_MEMORY_USAGE` | Current memory usage (bytes) |
| `RELI_WATCH_MEMORY_PEAK` | Memory peak (bytes) |
| `RELI_WATCH_TIMESTAMP` | ISO 8601 timestamp |
| `RELI_WATCH_FUNCTION` | (for watch-function) Detected function name |
| `RELI_WATCH_DUMP_PATH` | (for memory-dump) Path of the output dump file |

**Security:** The command string is explicitly specified by the user, and since reli-prof
itself executes it, the risk of shell injection is limited. However, dynamic values are
passed only via environment variables, and no placeholders are used in the command string itself.

## Common Options

### Monitoring Interval

| Option | Default | Description |
|--------|---------|-------------|
| `--poll-interval=<ms>` | `1000` | Polling interval (milliseconds). Minimum value: 100ms. |

`--poll-interval` is the sleep duration between each poll. When only Tier 1 triggers are active,
the impact on the target process is minimal even at short intervals (100-500ms) (1 `process_vm_readv` call/poll).
When Tier 2/3 triggers are enabled, trace reading costs apply, so
1000ms or more is recommended.

### Rate Limiting and Disk Protection

Multi-layer controls to prevent disk explosion and performance degradation of the target process from continuous triggers:

| Option | Default | Description |
|--------|---------|-------------|
| `--cooldown=<seconds>` | `60` | Minimum wait time before the same trigger can fire again |
| `--max-triggers=<N>` | `0` (unlimited) | Cumulative trigger count limit. Monitoring ends when reached |
| `--max-triggers-per-hour=<N>` | `10` | Trigger count limit per hour. Excess triggers are ignored |
| `--max-dump-size=<size>` | `1G` | Cumulative size limit for dump files. memory-dump action is skipped when exceeded |
| `--backoff-multiplier=<float>` | `2.0` | Exponential backoff multiplier for cooldown on consecutive triggers |
| `--backoff-max=<seconds>` | `3600` | Maximum backoff duration in seconds |

**Exponential backoff behavior:**

When the same trigger fires consecutively, the cooldown increases exponentially to
reduce impact on disk and performance.

```
1st: fires immediately
2nd: waits cooldown (60s)
3rd: waits cooldown x backoff_multiplier (120s)
4th: waits cooldown x backoff_multiplier^2 (240s)
  ...
Cap: capped at backoff_max (3600s = 1 hour)
```

When the trigger condition is no longer met, the backoff counter is reset.

**CooldownManager extension:**

```php
final class CooldownManager
{
    /** @var array<string, CooldownState> trigger name -> state */
    private array $states = [];

    public function canFire(string $trigger_name, float $now): bool;
    public function recordFire(string $trigger_name, float $now): void;
    public function recordClear(string $trigger_name): void;  // Reset when condition clears
    public function getHourlyCount(): int;
}

final class CooldownState
{
    public int $fire_count = 0;
    public int $consecutive_fires = 0;      // Consecutive fire count (for backoff calculation)
    public float $last_fire_time = 0.0;
    public float $current_cooldown;          // Current effective cooldown
    /** @var \SplQueue<float> */
    public \SplQueue $hourly_timestamps;     // Timestamps within the last hour
}
```

**Disk Usage Tracking:**

When `MemoryDumpAction` is executed, the file size is recorded, and if the cumulative total
exceeds `--max-dump-size`, subsequent dumps are skipped and a warning is output. Trace/log
actions are exempt from this limit since their sizes are small.

```php
final class DiskUsageTracker
{
    private int $total_bytes = 0;

    public function recordFile(string $path): void
    {
        $this->total_bytes += filesize($path);
    }

    public function canWrite(int $limit_bytes): bool
    {
        return $this->total_bytes < $limit_bytes;
    }
}
```

### Status Display

**Single-Process Mode:**

Overwrites the status line inline in the terminal (`\r` + ANSI).
Uses the same style as `inspector:top`, allowing you to grasp the state without filling the screen:

```
[watching] PID=1234 | mem=45.2M/256M | polls=1523 | triggers=3/10 | disk=127M/1G | cooldown=OK
```

Only records with a newline when a trigger fires or is skipped:

```
[TRIGGERED] PID=1234 | trigger=memory-limit | mem=261.3M>256M | action=memory-dump → watch-1234-20260329T123456.json
[SKIPPED]   PID=1234 | trigger=memory-limit | reason=hourly limit (10/10)
```

**Daemon Mode:**

Since multiple processes are being monitored, per-poll status line display is not performed.
Instead, a periodic summary is output at `--status-interval=<seconds>` (default: 60):

```
[status] 2026-03-29T12:35:00+09:00 | watching=12 procs | triggers=5 total | disk=423M/1G
  PID=1234 (php-fpm) mem=198.7M  triggers=2  last=12:34:01  cooldown=backoff(240s)
  PID=2345 (php-fpm) mem=45.2M   triggers=0  last=never     cooldown=OK
  PID=3456 (artisan) mem=312.1M  triggers=3  last=12:34:55  cooldown=60s
  ... (12 procs, showing top 5 by memory)
```

Daemon status uses a **hybrid of event-driven + periodic summary**:
- On trigger fire/skip: Immediately outputs an event line (`[TRIGGERED]`, `[SKIPPED]`)
- On process discovery/disappearance: Immediately notifies (`[+process]`, `[-process]`)
- Periodic summary: Outputs an overview of all processes at every `--status-interval`
- `--quiet`: Suppresses both event lines and summaries (output only to log file)

**Log File Output (`--log-file`):**

When `--log-file` is specified, all events (including status) are written to the file as
structured logs. This operates independently of terminal display.

```
--status-log-level=<level>   # Log level for status summaries (debug/info/none)
                              # Default: daemon=info, single=debug
```

| Option | Default | Description |
|---|---|---|
| `--status-interval=<seconds>` | `60` | Summary output interval for daemon mode |
| `--status-log-level=<level>` | `info` (daemon) / `debug` (single) | Log level for status |

### Miscellaneous

| Option | Default | Description |
|---|---|---|
| `--action-output-dir=<path>` | `.` | Output directory for dump/log files |
| `--stop-process` / `-S` | `false` | Stop the process via ptrace during action execution |
| `--quiet` | `false` | Suppress terminal output on trigger fire |

## Architecture

### Class Diagram

```
src/
├── Command/Inspector/
│   └── WatchCommand.php                          # Symfony Console command
│
├── Inspector/
│   ├── Settings/WatchSettings/
│   │   ├── WatchSettings.php                     # Immutable settings data class
│   │   └── WatchSettingsFromConsoleInput.php      # CLI → Settings conversion
│   │
│   └── Watch/
│       ├── WatchContext.php                       # Per-poll collected data
│       ├── TriggerEvent.php                       # Trigger fire event DTO
│       ├── HeapStats.php                          # Lightweight heap statistics DTO
│       ├── HeapStatsReader.php                    # ZendMmHeap lightweight reader
│       ├── CooldownManager.php                    # Per-trigger cooldown + backoff tracking
│       ├── DiskUsageTracker.php                   # Cumulative dump size limiter
│       ├── WatchLoop.php                          # Single-process watch loop
│       ├── DaemonWatchCoordinator.php             # Multi-process daemon orchestrator
│       │
│       ├── Trigger/
│       │   ├── TriggerInterface.php
│       │   ├── MemoryLimitTrigger.php
│       │   ├── MemoryGrowthRateTrigger.php
│       │   ├── MemoryPeakTrigger.php
│       │   ├── FunctionDetectionTrigger.php
│       │   ├── TraceDepthTrigger.php
│       │   ├── ExceptionDetectionTrigger.php
│       │   ├── GlobalArraySizeTrigger.php
│       │   └── VariableValueTrigger.php
│       │
│       └── Action/
│           ├── ActionInterface.php
│           ├── TraceAction.php
│           ├── MemoryDumpAction.php
│           ├── LogAction.php
│           └── ExecAction.php
```

### Key Interfaces

```php
interface TriggerInterface
{
    /** Trigger name (for CLI display and logging) */
    public function name(): string;

    /** Whether the trigger requires Tier 2 (call trace reading) */
    public function requiresCallTrace(): bool;

    /** Whether the trigger requires Tier 3 (deep EG reading) */
    public function requiresDeepInspection(): bool;

    /** Evaluation: returns a TriggerEvent if the condition is met, otherwise null */
    public function evaluate(WatchContext $context): ?TriggerEvent;
}

interface ActionInterface
{
    /** Action name */
    public function name(): string;

    /** Executed when a trigger fires */
    public function execute(
        TriggerEvent $event,
        ProcessSpecifier $process,
        WatchContext $context,
    ): void;
}
```

### WatchContext

```php
final class WatchContext
{
    public function __construct(
        public readonly int $pid,
        public readonly HeapStats $heap_stats,
        public readonly ?CallTrace $call_trace,       // Only when Tier 2 triggers are enabled
        public readonly ?bool $has_exception,          // Tier 3: only when on-exception is enabled
        public readonly float $timestamp,
        public readonly ?WatchContext $previous,       // Previous context (for growth rate)
    ) {}
}
```

### HeapStats / HeapStatsReader

```php
final class HeapStats
{
    public function __construct(
        public readonly int $size,           // Equivalent to memory_get_usage(false)
        public readonly int $real_size,      // Equivalent to memory_get_usage(true)
        public readonly int $peak,           // Equivalent to memory_get_peak_usage(false)
        public readonly int $limit,          // Value of memory_limit
    ) {}
}
```

`HeapStatsReader` extracts the first pass of `MemoryLocationsCollector::collectAll()` (L131-L220)
-- main_chunk retrieval -> `ZendMmChunk::heap_slot` -> `ZendMmHeap` field reading --
as a lightweight version. It uses `PhpZendMemoryManagerChunkFinder` and `Dereferencer`.

### Adaptive Polling (Tier-based Optimization)

Optimizes the amount of data read per poll based on the maximum Tier of enabled triggers:

```
Only Tier 1 enabled → Execute only HeapStatsReader (< 1ms)
Tier 2 enabled      → HeapStats + CallTraceReader (a few ms)
Tier 3 enabled      → HeapStats + CallTrace + EG deep fields (a few ms to tens of ms)
```

When only Tier 1 is active, the performance impact on the target process is nearly zero.

### Single-Process Mode Flow

```
WatchCommand::execute()
  │
  ├── TargetProcessResolver::resolve()           // Obtain PID
  ├── PhpVersionDetector::decidePhpVersion()     // Determine PHP version
  ├── PhpGlobalsFinder::findExecutorGlobals()    // Obtain EG address
  ├── Build Trigger[] / Action[] from WatchSettings
  │
  └── Build watch loop with LoopBuilder
       ├── ExitLoopOnSpecificExceptionMiddleware
       ├── RetryOnExceptionMiddleware
       ├── KeyboardCancelMiddleware ('q')
       ├── NanoSleepMiddleware (poll_interval)
       └── CallableMiddleware:
            │
            ├── HeapStatsReader::read()              // Always
            ├── CallTraceReader::readCallTrace()     // When Tier 2+ is enabled
            ├── EG->exception check                  // When Tier 3 is enabled
            │
            ├── Build WatchContext
            │
            ├── foreach (triggers as trigger):
            │     event = trigger->evaluate(context)
            │     if event && cooldown passed:
            │       foreach (actions as action):
            │         action->execute(event, process, context)
            │
            └── return true  // Continue loop
```

### Daemon Mode

Extends the existing `inspector:daemon` pattern, with `DaemonWatchCoordinator`
managing monitoring of multiple processes in parallel.

```
WatchCommand::execute() [daemon mode]
  │
  ├── Launch search worker with PhpSearcherContextCreator
  │     └── Continuously discover processes matching target-regex
  │
  ├── DaemonWatchCoordinator
  │     ├── Assign a WatchLoop to an Amphp worker for each discovered process
  │     ├── Release worker when a process disappears
  │     └── Send trigger events to the main thread
  │
  └── Main thread
        ├── Receive trigger events from workers
        ├── Execute actions (with exclusive control for file output)
        └── Cancel with 'q' key
```

**Extension of the Amphp Worker Protocol:**

The existing Reader worker sends `TraceMessage` / `DetachWorkerMessage`, but the
Watch worker requires a new protocol that sends `WatchTriggerMessage`.

```php
final class WatchTriggerMessage
{
    public function __construct(
        public readonly int $pid,
        public readonly TriggerEvent $event,
        public readonly HeapStats $heap_stats,
        public readonly ?CallTrace $call_trace,
    ) {}
}
```

Referring to the existing `PhpReaderContextCreator` / `PhpReaderEntryPoint`,
create new `PhpWatcherContextCreator` / `PhpWatcherEntryPoint`.
Execute the WatchLoop within a worker and send a message when a trigger fires.

## Reused Existing Classes

| Class | Purpose |
|--------|------|
| `LoopBuilder` / `TraceLoopProvider` | Build watch loop with middleware |
| `CallTraceReader` | Call trace reading (Tier 2) |
| `MemoryLocationsCollector` | Full scan for memory-dump action |
| `MemoryOutputFactory` | Output format for memory-dump |
| `TraceOutputFactory` | Output for trace action |
| `PhpGlobalsFinder` | EG/SG/CG address resolution |
| `PhpVersionDetector` | PHP version detection |
| `ProcessSearcher` | Process discovery for daemon mode |
| `PhpSearcherContextCreator` | Search worker for daemon mode |
| `WorkerPool` | Worker management for daemon mode (reference) |
| `DispatchTable` | Process assignment for daemon mode (reference) |
| `MemoryReaderInterface` | Memory reading via `process_vm_readv` |
| `ProcessStopper` | ptrace attach/detach |
| `TargetProcessResolver` | Target resolution by PID / command execution |
| `ZendMmHeap` | Heap metadata type |
| `ZendMmChunk` | heap_slot access from chunk |
| `PhpZendMemoryManagerChunkFinder` | Obtain main_chunk address |
| `DaemonSettingsFromConsoleInput` | Settings for `--target-regex`, `--threads` |
| `EchoBackCanceller` | Terminal echo-back control |

## Required Modifications to Existing Code

### 1. Add `exception` field to ZendExecutorGlobals

```php
// src/Lib/PhpInternals/Types/Zend/ZendExecutorGlobals.php

/** @var Pointer<ZendObject>|null */
public ?Pointer $exception;

// Add to getFieldLazy():
'exception' => $this->exception = $this->field_reader->readPointerField(
    $this->pointer,
    'exception',
    ZendObject::class,
),
```

### 2. Registration in the DI Container

```php
// Add WatchCommand-related bindings to config/di.php
// Most can be resolved via autowire
```

## Implementation Plan

### Phase 1: Core (Single Process Mode)

1. `HeapStats` / `HeapStatsReader` — Lightweight heap statistics reader
2. `TriggerInterface` + Tier 1 triggers (`MemoryLimitTrigger`, `MemoryGrowthRateTrigger`, `MemoryPeakTrigger`)
3. `ActionInterface` + `TraceAction`, `LogAction`
4. `WatchContext`, `TriggerEvent`, `CooldownManager`
5. `WatchLoop` — Single process watch loop
6. `WatchSettings` / `WatchSettingsFromConsoleInput`
7. `WatchCommand` — Symfony Console command

### Phase 2: Advanced Triggers

8. Add `exception` field to `ZendExecutorGlobals`
9. Tier 2 triggers (`FunctionDetectionTrigger`, `TraceDepthTrigger`)
10. Tier 3 triggers (`ExceptionDetectionTrigger`, `GlobalArraySizeTrigger`)
11. `MemoryDumpAction`, `ExecAction`

### Phase 3: Daemon Mode

12. `WatchTriggerMessage` — Worker communication protocol
13. `PhpWatcherEntryPoint` / `PhpWatcherContextCreator` — Worker for Watch
14. `DaemonWatchCoordinator` — Multi-process orchestrator
15. Add daemon mode path to `WatchCommand`

## Testing Strategy

### Unit Tests

- `evaluate()` logic for each Trigger (test around thresholds with mock WatchContext)
- Timing control for `CooldownManager`
- Rate calculation for `MemoryGrowthRateTrigger`
- Size parsing for `HeapStats` (`256M` -> bytes)

### Integration Tests

- Whether `HeapStatsReader` can read heap statistics from a real process
- Whether `WatchLoop` correctly runs the trigger firing -> action execution pipeline

### Manual Tests

```bash
# PHP script that leaks memory
php -r 'while(true){$a[]=str_repeat("x",1024);usleep(10000);}'

# Watch
reli inspector:watch -p <PID> --memory-limit=10M --action=trace --action=log
```

### CI

- `composer test` — No regression in existing tests
- `composer phpstan` — Static analysis passes

## Container / Orchestrator Deployment

`process_vm_readv` and ptrace can only access processes within the **same PID namespace**.
In container environments, PID namespace sharing must be configured.

### Kubernetes

**Recommended: Sidecar Container**

```yaml
apiVersion: v1
kind: Pod
metadata:
  name: php-app
spec:
  shareProcessNamespace: true    # Required: share PID namespace
  containers:
  - name: app
    image: php-app:latest
  - name: reli-watch
    image: reli-prof:latest
    command:
    - reli
    - inspector:watch
    - --target-regex=php-fpm
    - --memory-limit=512M
    - --action=memory-dump
    - --action=log
    - --log-file=/var/log/reli/watch.log
    - --action-output-dir=/var/log/reli/dumps/
    - --max-dump-size=2G
    - --quiet
    securityContext:
      capabilities:
        add: ["SYS_PTRACE"]      # Required: process_vm_readv / ptrace
    volumeMounts:
    - name: reli-logs
      mountPath: /var/log/reli
  volumes:
  - name: reli-logs
    emptyDir:
      sizeLimit: 3Gi             # Dual-layer disk protection
```

**Key Points:**
- `shareProcessNamespace: true` places all containers in the Pod within the same PID namespace
- Only `SYS_PTRACE` capability is added (privileged is not required)
- `--quiet` + `--log-file` prevents stdout noise
- Dual-layer disk protection via emptyDir `sizeLimit` and `--max-dump-size`
- Dump files are written to emptyDir and can be forwarded via FluentBit, etc., or
  uploaded to S3 using `--action=exec`

**k8s Ephemeral Container (for temporary investigation):**

```bash
kubectl debug -it php-app \
  --image=reli-prof:latest \
  --target=app \
  -- reli inspector:watch --target-regex=php --memory-limit=256M --action=trace
```

Allows on-demand attachment when issues occur, without pre-deploying a sidecar.
However, `shareProcessNamespace` must be enabled at Pod creation time.

**DaemonSet Pattern (node-wide monitoring):**

```yaml
apiVersion: apps/v1
kind: DaemonSet
metadata:
  name: reli-watch
spec:
  template:
    spec:
      hostPID: true               # Use the host's PID namespace
      containers:
      - name: reli-watch
        image: reli-prof:latest
        command:
        - reli
        - inspector:watch
        - --target-regex=php-fpm
        - --memory-limit=1G
        - --action=log
        - --action=exec
        - --action-exec-command=<alert script>
        securityContext:
          capabilities:
            add: ["SYS_PTRACE"]
```

Monitors all PHP processes on the node at once. Suitable for environments where security requirements permit it.

### Amazon ECS

```json
{
  "family": "php-app",
  "pidMode": "task",
  "containerDefinitions": [
    {
      "name": "app",
      "image": "php-app:latest",
      "essential": true
    },
    {
      "name": "reli-watch",
      "image": "reli-prof:latest",
      "essential": false,
      "command": [
        "reli", "inspector:watch",
        "--target-regex=php",
        "--memory-limit=512M",
        "--action=memory-dump",
        "--action=log",
        "--log-file=/var/log/reli/watch.log",
        "--action-output-dir=/var/log/reli/dumps/",
        "--max-dump-size=2G",
        "--quiet"
      ],
      "linuxParameters": {
        "capabilities": {
          "add": ["SYS_PTRACE"]
        }
      }
    }
  ]
}
```

**Key Points:**
- `pidMode: "task"` enables PID namespace sharing (supported on both Fargate 1.4.0+ and EC2)
- `essential: false` allows the app to continue running even if the watcher goes down

### Dump File Transfer

In container environments, local disk is ephemeral. Patterns for persisting dump files:

| Pattern | Implementation | Use Case |
|----------|------|----------|
| Direct S3 upload | `--action=exec --action-exec-command='aws s3 cp $RELI_WATCH_DUMP_PATH s3://...'` | AWS environments |
| FluentBit sidecar | Tail and forward from the dump directory | When log infrastructure is in place |
| Persistent Volume | PVC mount | When EBS/EFS is available in k8s |
| `--action=log` only | Record only event logs without taking dumps | When disk space is limited |

**exec action + environment variables for S3 transfer:**

```bash
--action=exec \
--action-exec-command='aws s3 cp "$RELI_WATCH_DUMP_PATH" "s3://my-bucket/reli-dumps/$(hostname)/" && rm "$RELI_WATCH_DUMP_PATH"'
```

The `RELI_WATCH_DUMP_PATH` environment variable stores the file path output by the `memory-dump` action.
Since the exec action runs after memory-dump, a dump -> upload -> delete pipeline can be configured.

### Security Considerations

- `SYS_PTRACE` capability is a powerful permission that allows reading other processes' memory
- In production environments, restrict deployment of the reli-watch sidecar using RBAC / Pod Security Standards
- Use a narrow `--target-regex` to prevent attaching to unintended processes
- Dump files contain memory contents, so encryption during transfer and storage is recommended
- Hard-code `--action=exec` commands in the Pod spec / Task Definition;
  avoid dynamic command assembly via environment variables

## Future Considerations

- **Auto-analysis report** integration: Output automatic analysis reports for feature branches with `--action=report`
- **Prometheus / StatsD integration**: Metrics export action
- **Conditional action**: Configure different actions per trigger (`--on memory-limit do memory-dump`)
- **Watch profile**: Load trigger and action settings from a YAML/JSON file
- **Web UI**: Real-time monitoring dashboard via WebSocket
- **OCI image**: Official Docker image for reli-prof sidecar deployment
