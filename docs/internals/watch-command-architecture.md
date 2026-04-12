# inspector:watch / inspector:peek-var Architecture

## Overview

`inspector:watch` monitors PHP processes and triggers profiling actions
when configurable conditions are met. Two modes: single-process (`-p PID`)
and daemon (`--target-regex`).

`inspector:peek-var` reads variable values directly using `VariableReader`
without the trigger/action/cooldown machinery — see
[peek-var-command.md](../peek-var-command.md).

## Data Flow

### Single-Process Mode

```
WatchCommand::execute()
  ├── TriggerFactory::build(settings) → triggers[]
  ├── ActionFactory::buildActions(settings, ...) → actions[]
  ├── TargetProcessResolver → PID
  ├── PhpGlobalsFinder → EG, SG, CG addresses
  └── LoopBuilder middleware chain:
       ├── HeapStatsReader::read()              (always, Tier 1)
       ├── RssReader::read()                    (if RssUsageTrigger)
       ├── CpuUsageReader::read()               (if CpuUsageTrigger)
       ├── CallTraceReader::readCallTrace()     (if Tier 2 trigger OR tracing)
       ├── HeapStatsReader::hasException()      (if ExceptionDetectionTrigger)
       ├── VariableReader::readVariables()      (if VariableValueTrigger)
       ├── WatchContext construction
       ├── TraceSession::recordSample()         (if tracing active)
       ├── TriggerStateTracker phase detection
       ├── on-enter / on-exit action execution
       ├── Trigger evaluation → merged TriggerEvent
       ├── CooldownManager check
       ├── Regular action execution (once per poll, not per trigger)
       └── Dynamic sleep (poll_interval or trace_interval)
```

### Daemon Mode

```
WatchCommand::executeDaemonMode()
  ├── PhpSearcherContextCreator → searcher worker (existing infra)
  ├── PhpWatchContextCreator → N watch workers (new infra)
  ├── WatchDescriptorRetriever → WatchTargetDescriptor (EG+SG+CG)
  │
  ├── Searcher future: discovers processes
  │     └── WatchDescriptorRetriever enriches with CG address
  │         └── sendAttach(WatchTargetDescriptor) to free worker
  │
  ├── Worker futures: receive WatchTriggerMessage
  │     └── Controller executes actions with WatchContext
  │         (daemon_eg/cg/php_version from message)
  │
  └── Global max-triggers counter → cancellation
```

## Trigger System

### Tiers (adaptive polling cost)

| Tier | What's Read | Cost | Triggers |
|------|-------------|------|----------|
| 1 | ZendMmHeap stats only | < 1ms | MemoryLimit, GrowthRate, PeakWatch, RssUsage, CpuUsage |
| 2 | + Call trace | ~ms | FunctionDetection, TraceDepth |
| 3 | + EG exception, variables | ~10ms | ExceptionDetection, VariableValue |

Only reads what the active triggers require. `needs_call_trace`,
`needs_cpu`, and `needs_exception_check` are computed at startup
from trigger types.

### CpuUsageTrigger

Reads `/proc/[pid]/stat` utime+stime fields. Computes delta CPU% between
polls using `SC_CLK_TCK`. First poll always returns null (needs two samples).

Supports hysteresis (separate enter/exit thresholds) and sustain duration
(must stay above threshold for N seconds before entering). Internal state
machine: INACTIVE → (cpu >= enter for sustain_s) → ACTIVE → (cpu < exit) → INACTIVE.

### Event Merging

When multiple triggers fire in the same poll, events are merged into
a single TriggerEvent with combined name (e.g., `memory-limit+memory-peak-watch`)
and actions execute once. This prevents duplicate dumps/traces.

### Cooldown Architecture

```
CooldownManager (per trigger name)
  ├── base_cooldown_seconds × backoff_multiplier^(consecutive_fires-1)
  ├── capped at backoff_max_seconds
  ├── resets on recordClear (condition no longer met)
  └── hourly sliding window (SplQueue)

max-triggers is NOT in CooldownManager.
  ├── Single-process: action_count in WatchCommand closure
  └── Daemon: global_trigger_count in controller, cancels all workers
```

**Future: per-trigger cooldown override**

Currently `--cooldown` applies to all triggers equally. A natural
extension would be per-trigger overrides:

```bash
--cooldown=60                          # default for all
--cooldown-memory-peak-watch=300       # override for peak trigger
--cooldown-memory-limit=10             # override for memory-limit
```

Implementation: add `array<string, float> $per_trigger_cooldown`
to `CooldownManager`. In `canFire()` / `recordFire()`, look up
the trigger name in the per-trigger map first, fall back to
`base_cooldown_seconds`. CLI option names follow
`--cooldown-{trigger-name}` convention.

This is particularly relevant for `memory-peak-watch` which fires
on every peak update (one-directional, never clears) — a longer
cooldown prevents noise while still capturing major peaks.

## Variable Reading

### VariableSpec and VariableReader

`VariableReader::readVariables()` accepts `VariableSpec[]` — a simple
value object carrying `scope`, `var_name`, and `lookup_key`. This
decouples variable reading from trigger evaluation:

- `VariableSpec::parse('scope::name')` — for `inspector:peek-var`
- `VariableValueTrigger` — carries condition (`op`, `operand`) in
  addition to scope/name, used by `inspector:watch`. The watch command
  maps triggers to `VariableSpec` when calling `readVariables()`.

### --watch-var Syntax

```
scope::[$]name[path]:op:value

Scopes:
  global::$var              EG->symbol_table
  local::$var               current_execute_data CVs (walks stack)
  local::func()$var         specific frame only
  static::Class::$prop      class static property (needs CG)
  func_static::func()$var   function static variable
  memory::memory_get_usage  ZendMM heap stats (via HeapStatsReader)
```

### Path Expressions

```
$config[db][host]     → array key traversal
$obj->prop            → object property traversal
$app->cache[key]->x   → mixed traversal
```

Implementation: `VariableReader::parsePathExpression()` splits into
root name + list of `['[]', key]` or `['->', prop]` segments.
`resolvePath()` walks the zval chain.

### Key Implementation Details

1. **IS_INDIRECT handling**: `$GLOBALS` entries in PHP 8.1+ use
   IS_INDIRECT zvals pointing to CV slots. `resolveIndirectAndRef()`
   follows both IS_INDIRECT and IS_REFERENCE.

2. **CData lifetime**: After findByKey() or getPropertiesIterator(),
   re-deref the Zval from its Pointer to avoid dangling CData.
   See `docs/internals/ffi-cdata-lifetime.md`.

3. **Local variable stack walk**: `readLocalVariable()` traverses
   `prev_execute_data` chain to find variables in parent frames
   (handles case where process is stopped in an internal function).

4. **Function frame filtering**: `local::func()$var` uses
   `frameMatchesFunction()` with strict exact match on
   `ZendFunction::getFullyQualifiedFunctionName()`.

## Trigger State Tracking (on-enter / on-exit)

`TriggerStateTracker` wraps trigger evaluation to detect state transitions:

```
TriggerPhase (enum):
  ENTER   — was inactive, now active   → execute --on-enter actions
  EXIT    — was active, now inactive   → execute --on-exit actions
  ACTIVE  — still active               → execute --action with cooldown
  null    — still inactive             → no action

Poll loop:
  for each trigger:
    event = trigger.evaluate(context)
    phase = state_tracker.update(trigger.name, event !== null)
    ENTER  → on-enter actions (once, no cooldown)
    EXIT   → on-exit actions (once) + cooldown.recordClear
    ACTIVE → regular --action (with cooldown)
```

This preserves full backward compatibility. Without `--on-enter`/`--on-exit`,
the existing `--action` behavior is unchanged.

### Continuous Tracing (TraceSession)

`TraceSession` manages the lifecycle of `.rbt` trace recording:

```
ContinuousTraceAction (on-enter) → TraceSession.start()
Poll loop: if session.isActive() → session.recordSample(call_trace)
StopTraceAction (on-exit) → TraceSession.stop() → finalize .rbt
```

**Dynamic poll interval:** When trace session is active, the poll loop
switches from `--poll-interval` (default 1s) to `--trace-interval`
(default 10ms) for higher sampling resolution.

**Interaction with other actions:** The watch monitoring loop continues
normally during tracing. Other triggers evaluate at the same frequency.
Memory dumps (which ptrace-stop the target) naturally pause tracing.

**CPU sampling window stability:** CpuUsageReader uses a minimum 0.5s
sampling window regardless of poll frequency. When tracing at 10ms, the
CPU% is still averaged over 0.5s, preventing noisy exit transitions.

**Daemon mode:** Each worker manages its own TraceSession locally.
Workers detect trigger state transitions via TriggerStateTracker and
start/stop traces autonomously. Two messages flow on EXIT:
`WatchTraceNotifyMessage` (trace lifecycle) and `WatchTriggerExitMessage`
(on-exit one-shot actions). No trace sample data flows through the channel.

## Action System

| Action | Type | Single-Process | Daemon |
|--------|------|---------------|--------|
| trace-once | one-shot | TraceAction (reads or reuses from context) | DaemonTraceAction (from WatchTriggerMessage) |
| trace | stateful | ContinuousTraceAction → TraceSession.start() | Worker-local TraceSession |
| stop-trace | stateful | StopTraceAction → TraceSession.stop() | Worker-local TraceSession.stop() |
| log | one-shot | LogAction (stderr or file) | LogAction (same) |
| exec | one-shot | ExecAction (fire-and-forget, /dev/null) | ExecAction (same) |
| memory-dump | one-shot | MemoryDumpAction (MemoryDumper) | DaemonMemoryDumpAction (MemoryDumper + WatchContext addresses) |

### MemoryDumper

Extracted from `MemoryDumpCommand`. Shared between:
- `MemoryDumpCommand` (CLI)
- `MemoryDumpAction` (single-process watch)
- `DaemonMemoryDumpAction` (daemon watch)

Dumps: EG + CG structs, ZendMM chunks, huge allocs, VM stacks,
compiler arenas. Output: binary `.dump` file for offline analysis
via `inspector:memory:analyze`.

## Rate Limiting

| Mechanism | Scope | Restart-Resilient |
|-----------|-------|-------------------|
| `--cooldown` + backoff | Per trigger, per process | No (stateful) |
| `--max-triggers-per-hour` | Per trigger (sliding window) | Yes (natural recovery) |
| `--max-dump-size` | Global (DiskUsageTracker) | Yes (checks file sizes) |
| `--oneshot=N` | Global action count | No (counter resets) |

`--oneshot` is for ad-hoc debugging. Long-running daemons should use
the restart-resilient mechanisms instead.

## Daemon Worker Protocol

```
Controller                          Worker (subprocess)
─────────                           ──────
sendSettings(WatchSettings)    →    receiveSettings()
                                      ↓ build triggers + cooldown + state tracker
sendAttach(WatchTargetDescriptor) → receiveAttach()
                                      ↓ poll loop:
                                      │  HeapStatsReader.read()
                                      │  CpuUsageReader.read() (if needed)
                                      │  CallTraceReader (if needed OR tracing)
                                      │  readVariables() (if needed)
                                      │  TraceSession.recordSample() (if tracing)
                                      │  trigger.evaluate() + state tracking
                                      │
                                      │  on ENTER:
                                      │    TraceSession.start() (if continuous trace)
receiveMessage()               ←       sendTraceNotify(STARTED)
                                      │
                                      │  on EXIT:
                                      │    TraceSession.stop() (if tracing)
receiveMessage()               ←       sendTraceNotify(STOPPED)
receiveMessage()               ←       sendTriggerExit(WatchTriggerExitMessage)
  → on-exit actions (log, exec)       │
                                      │  while ACTIVE (cooldown check):
receiveMessage()               ←       sendTrigger(WatchTriggerMessage)
  → on-enter actions (1st time)       │
  → regular actions                   │
                                      │  dynamic sleep (trace/poll interval)
                                      ↓ on process exit:
                                      │  TraceSession.stop() if active
receiveMessage()               ←       sendDetach(WatchDetachMessage)
  → on-exit for remaining active      │
```

### Message Types (Worker → Controller)

| Message | When | Controller action |
|---------|------|-------------------|
| `WatchTriggerMessage` | Trigger fires (condition true + cooldown) | Execute on-enter (1st), regular actions |
| `WatchTriggerExitMessage` | Trigger condition clears | Execute on-exit actions (log, exec) |
| `WatchTraceNotifyMessage` | Trace session starts or stops | Log notification |
| `WatchDetachMessage` | Process exits or read failures | Clean up, execute on-exit for any remaining active triggers |

Worker handles per-process cooldown/backoff internally.
Controller handles global max-triggers counter.

### Responsibility Split

| Concern | Single-process | Daemon |
|---------|---------------|--------|
| Trigger evaluation | WatchCommand poll loop | Worker poll loop |
| State tracking (enter/exit) | TriggerStateTracker in command | TriggerStateTracker in worker |
| Continuous trace (stateful) | TraceSession in command | TraceSession in worker (local .rbt) |
| One-shot on-enter/on-exit (log, exec) | Direct execution | Worker sends message → controller executes |
| Regular actions with cooldown | Direct execution | Worker sends trigger → controller executes |

### WatchTargetDescriptor vs TargetProcessDescriptor

```
TargetProcessDescriptor (existing): pid, eg_address, sg_address, php_version
WatchTargetDescriptor (new):        pid, eg_address, sg_address, cg_address, php_version
```

CG address is needed for memory dumps and static property reading.
`WatchDescriptorRetriever` resolves all 4 addresses with per-PID caching.
Existing searcher infrastructure is unchanged; enrichment happens in
the controller's searcher future.
