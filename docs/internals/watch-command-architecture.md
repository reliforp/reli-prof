# inspector:watch Command Architecture

## Overview

`inspector:watch` monitors PHP processes and triggers profiling actions
when configurable conditions are met. Two modes: single-process (`-p PID`)
and daemon (`--target-regex`).

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
       ├── CallTraceReader::readCallTrace()     (if Tier 2 trigger)
       ├── HeapStatsReader::hasException()      (if ExceptionDetectionTrigger)
       ├── VariableReader::readVariables()      (if VariableValueTrigger)
       ├── WatchContext construction
       ├── Trigger evaluation → merged TriggerEvent
       ├── CooldownManager check
       └── Action execution (once per poll, not per trigger)
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
| 1 | ZendMmHeap stats only | < 1ms | MemoryLimit, GrowthRate, PeakWatch |
| 2 | + Call trace | ~ms | FunctionDetection, TraceDepth |
| 3 | + EG exception, variables | ~10ms | ExceptionDetection, VariableValue |

Only reads what the active triggers require. `needs_call_trace` and
`needs_exception_check` are computed at startup from trigger types.

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

## Variable Reading

### --watch-var Syntax

```
scope::[$]name[path]:op:value

Scopes:
  global::$var              EG->symbol_table
  local::$var               current_execute_data CVs (walks stack)
  local::func()$var         specific frame only
  static::Class::$prop      class static property (needs CG)
  func_static::func()$var   function static variable
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

## Action System

| Action | Single-Process | Daemon |
|--------|---------------|--------|
| trace | TraceAction (reads or reuses from context) | DaemonTraceAction (from WatchTriggerMessage) |
| log | LogAction (stderr or file) | LogAction (same) |
| exec | ExecAction (fire-and-forget, /dev/null) | ExecAction (same) |
| memory-dump | MemoryDumpAction (MemoryDumper) | DaemonMemoryDumpAction (MemoryDumper + WatchContext addresses) |

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
                                      ↓ build triggers + cooldown
sendAttach(WatchTargetDescriptor) → receiveAttach()
                                      ↓ poll loop:
                                      │  HeapStatsReader.read()
                                      │  CallTraceReader (if needed)
                                      │  hasException() (if needed)
                                      │  readVariables() (if needed)
                                      │  trigger.evaluate()
                                      │  cooldown check
                                      │  if fired:
receiveTriggerOrDetach()       ←       sendTrigger(WatchTriggerMessage)
                                      ↓ on process exit:
receiveTriggerOrDetach()       ←       sendDetach(WatchDetachMessage)
```

Worker handles per-process cooldown/backoff internally.
Controller handles global max-triggers counter.

### WatchTargetDescriptor vs TargetProcessDescriptor

```
TargetProcessDescriptor (existing): pid, eg_address, sg_address, php_version
WatchTargetDescriptor (new):        pid, eg_address, sg_address, cg_address, php_version
```

CG address is needed for memory dumps and static property reading.
`WatchDescriptorRetriever` resolves all 4 addresses with per-PID caching.
Existing searcher infrastructure is unchanged; enrichment happens in
the controller's searcher future.
