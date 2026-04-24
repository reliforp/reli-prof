# inspector:watch — Condition-Based PHP Process Monitoring

`inspector:watch` monitors PHP processes and automatically triggers profiling actions when configurable conditions are met.

Unlike `inspector:top` (real-time display) or `inspector:daemon` (continuous tracing), this command specializes in **passive monitoring that only takes action when triggers fire**, making it suitable for low-overhead production monitoring.

## Quick Start

```bash
# Monitor a single process: dump memory when usage exceeds 256M
reli inspector:watch -p <pid> --memory-usage=256M

# Monitor multiple processes matching a regex
reli inspector:watch --target-regex="php-fpm" --memory-usage=512M

# Watch for a specific function appearing in the call stack
reli inspector:watch -p <pid> --watch-function="App\Service::heavyProcess"

# Grab 3 dumps and stop (ad-hoc debugging)
reli inspector:watch -p <pid> --memory-usage=128M --oneshot=3
```

## Requirements

- Same as other `inspector:*` commands (FFI, PCNTL, PHP 8.1+ for execution, PHP 7.0+ for targets)
- `CAP_SYS_PTRACE` capability (usually root)
- For `--target-regex` (daemon mode): same as `inspector:daemon`

## Triggers

Triggers define **when** to take action. At least one trigger must be specified.

### Memory Limit (`--memory-usage=<size>`)

Fires when heap usage exceeds the threshold.

```bash
reli inspector:watch -p <pid> --memory-usage=256M
reli inspector:watch -p <pid> --memory-usage=1G
```

### Memory Growth Rate (`--memory-growth-rate=<size>/<period>`)

Fires when memory grows faster than the specified rate. Useful for detecting memory leaks.

```bash
reli inspector:watch -p <pid> --memory-growth-rate=10M/min
reli inspector:watch -p <pid> --memory-growth-rate=500K/s
```

Supported periods: `s` (seconds), `min` (minutes), `h` (hours).

### Memory Peak Watch (`--memory-peak-watch`)

Fires whenever the memory peak is updated. Useful for tracking peak memory progression.

```bash
reli inspector:watch -p <pid> --memory-peak-watch
```

Note: with exponential backoff cooldown, frequent peak updates are naturally throttled.

### RSS Usage (`--rss-usage=<size>`)

Fires when the process's RSS (Resident Set Size) exceeds the threshold. Unlike `--memory-usage` which monitors PHP's internal heap, this monitors the actual physical memory used by the process as reported by the OS (`/proc/[pid]/statm`).

```bash
reli inspector:watch -p <pid> --rss-usage=512M
reli inspector:watch -p <pid> --rss-usage=1G
```

This is useful for detecting memory usage that occurs outside the Zend heap (e.g., FFI allocations, shared memory, memory-mapped files, or native extensions).

### Function Detection (`--watch-function=<name>`)

Fires when the specified function appears in the call stack. Requires the **fully qualified function name** (exact match).

```bash
reli inspector:watch -p <pid> --watch-function="sleep"
reli inspector:watch -p <pid> --watch-function="App\Service::process"
reli inspector:watch -p <pid> --watch-function="PDO::query"
```

### Trace Depth Limit (`--trace-depth-limit=<N>`)

Fires when the call stack exceeds N frames. Detects runaway recursion or deeply nested framework calls.

```bash
reli inspector:watch -p <pid> --trace-depth-limit=200
```

### Variable Value (`--watch-var=<expression>`)

Fires when a PHP variable meets a condition. Multiple `--watch-var` flags can be specified.

> **Tip:** To simply read a variable's current value without trigger conditions, use [`inspector:peek-var`](../inspection/peek-var-command.md).

**Syntax:** `scope::identifier:operator:value`

#### Scopes

| Scope | Syntax | What it reads |
|-------|--------|---------------|
| Global | `global::$var` | `$GLOBALS['var']` |
| Local | `local::func()$var` | Local variable in a specific function frame |
| Static property | `static::Class::$prop` | Class static property |
| Function static | `func_static::func()$var` | Function's `static $var` |
| Memory | `memory::memory_get_usage` | Zend MM heap stats |

For `local::` and `func_static::`, the function name is **required**. Use `<main>` for the top-level script scope.

The `memory::` scope exposes `memory_get_usage()` / `memory_get_peak_usage()` equivalents as integers (bytes). Available names: `memory_get_usage`, `memory_get_peak_usage`, `memory_get_usage_real`, `memory_get_peak_usage_real`. See [docs/inspection/peek-var-command.md](../inspection/peek-var-command.md#memory-scope) for details.

#### Operators

| Operator | Types | Description |
|----------|-------|-------------|
| `eq`, `ne` | All | Equal / not equal |
| `gt`, `lt`, `gte`, `lte` | int, float | Numeric comparison |
| `contains` | string | Substring match |
| `count_gt`, `count_lt`, `count_eq` | array | Array element count |
| `is_null` | All | Check if null |

#### Nested Access

Variable names support path expressions for nested array keys and object properties:

```bash
# Array key access
--watch-var='global::$config[database][pool_size]:gt:50'

# Object property access
--watch-var='global::$app->cache->size:gt:10000'

# Mixed
--watch-var='global::$container->services[cache]->pool[active]:gt:100'
```

#### Examples

```bash
# Global array growing too large
--watch-var='global::$cache:count_gt:10000'

# Local variable in a specific function
--watch-var='local::App\Controller::index()$response->statusCode:eq:500'

# Class static property
--watch-var='static::App\Cache::$entries:count_gt:50000'

# Function static variable (reads runtime value, not initial)
--watch-var='func_static::App\retry()$attempts:gt:10'

# Top-level script variable
--watch-var='local::<main>()$counter:gt:1000'

# Memory usage exceeds 100MB
--watch-var='memory::memory_get_usage:gt:104857600'

# Peak memory exceeds 256MB
--watch-var='memory::memory_get_peak_usage:gt:268435456'
```

### CPU Usage (`--cpu-usage=<percent>`)

Fires when the process's CPU usage exceeds the threshold. Reads from `/proc/[pid]/stat` (user + system time).

```bash
reli inspector:watch -p <pid> --cpu-usage=80
```

Supports **hysteresis** to prevent rapid toggling around a single boundary:

```bash
# Enter when CPU >= 80%, exit when CPU < 60%
reli inspector:watch -p <pid> --cpu-usage=80 --cpu-usage-exit=60
```

Supports **sustain duration** — the condition must hold continuously before entering:

```bash
# CPU must stay >= 80% for 5 seconds before triggering
reli inspector:watch -p <pid> --cpu-usage=80 --cpu-sustain=5
```

Note: the first poll always returns null (needs two samples to compute delta), so the trigger never fires on the very first poll.

**Sampling window:** CPU% is calculated over a minimum 0.5-second window regardless of `--poll-interval` or `--trace-interval`. When tracing at 10ms intervals, the CPU% still reflects the average over the last 0.5s, preventing noisy readings from causing premature exit transitions.

### Combining Triggers

Multiple triggers can be active simultaneously. When multiple triggers fire in the same poll cycle, actions execute **once** with a merged event:

```bash
reli inspector:watch -p <pid> \
  --memory-usage=256M \
  --memory-peak-watch \
  --watch-function="sleep" \
  --action=log
```

Output: `[TRIGGERED] PID=1234 | trigger=memory-usage+memory-peak-watch | ...`

## Actions

Actions define **what to do** when a trigger fires.

There are three categories:

| Category | Actions | When |
|----------|---------|------|
| One-shot | `memory-dump`, `trace-once`, `log`, `exec` | Fires each time (with cooldown) |
| Stateful start | `trace` | Starts continuous trace recording |
| Stateful stop | `stop-trace` | Stops continuous trace recording |

### `--action` (Regular Actions)

Regular actions fire while the trigger condition is active, subject to cooldown. Default: `memory-dump`.

### `--on-enter` / `--on-exit` (Lifecycle Actions)

Lifecycle actions fire exactly once on state transitions:

- `--on-enter=<action>`: fires when the trigger condition **becomes true**
- `--on-exit=<action>`: fires when the trigger condition **becomes false**

Both are repeatable. Combine with `--action` for mixed behavior:

```bash
# On CPU spike: start tracing + log; on recovery: stop tracing + log
reli inspector:watch -p <pid> \
  --cpu-usage=80 --cpu-usage-exit=60 --cpu-sustain=5 \
  --on-enter=trace --on-enter=log \
  --on-exit=stop-trace --on-exit=log \
  --trace-interval=10
```

```bash
# Mixed: start tracing on enter, also take memory dumps while active
reli inspector:watch -p <pid> \
  --cpu-usage=80 --cpu-usage-exit=60 \
  --on-enter=trace \
  --on-exit=stop-trace \
  --action=memory-dump
```

### Memory Dump (`--action=memory-dump`)

Captures a binary memory dump (same format as `inspector:memory:dump`) for offline analysis via `inspector:memory:analyze`.

```bash
reli inspector:watch -p <pid> --memory-usage=256M --action=memory-dump
```

Output files: `<output-dir>/watch-<pid>-<timestamp>.dump`

### Trace Snapshot (`--action=trace-once`)

Outputs the call trace at the moment the trigger fires (one-shot).

```bash
reli inspector:watch -p <pid> --watch-function="sleep" --action=trace-once
```

`--action` accepts `trace-once` for a one-shot snapshot; plain `trace` is **not** a valid value here. For continuous recording, use `--on-enter=trace` with `--on-exit=stop-trace` (see below).

### Continuous Trace (`--on-enter=trace` / `--on-exit=stop-trace`)

Starts/stops a continuous `.rbt` trace recording that samples the call stack at `--trace-interval` frequency while the trigger condition holds.

```bash
reli inspector:watch -p <pid> \
  --cpu-usage=80 --cpu-usage-exit=60 \
  --on-enter=trace --on-exit=stop-trace \
  --trace-interval=10
```

Output files: `<output-dir>/watch-trace-<pid>-<timestamp>.rbt`

During continuous tracing, the poll interval automatically switches to `--trace-interval` (default: 10ms) for higher sampling resolution. When tracing stops, it reverts to `--poll-interval`.

The trace session can coexist with other actions — memory dumps, log events, and exec commands continue to work during tracing.

In **daemon mode**, each worker manages its own trace session locally. Trace files are written to `--action-output-dir` with per-PID filenames. The controller receives notifications when traces start and stop.

### Event Log (`--action=log`)

Logs trigger events with timestamp, PID, trigger name, and memory stats.

```bash
reli inspector:watch -p <pid> --memory-usage=256M --action=log --log-file=/var/log/reli-watch.log
```

Log format:
```
[2026-03-30T12:34:56+00:00] PID=1234 trigger=memory-usage mem=261.3M>256M mem=261.3M peak=261.3M
```

### External Command (`--action=exec`)

Executes an external command (fire-and-forget, non-blocking). Context is passed via environment variables.

```bash
reli inspector:watch -p <pid> --memory-usage=256M \
  --action=exec \
  --action-exec-command='curl -s -X POST https://hooks.example.com/alert'
```

| Environment Variable | Description |
|---------------------|-------------|
| `RELI_WATCH_PID` | Target process PID |
| `RELI_WATCH_TRIGGER` | Trigger name(s) |
| `RELI_WATCH_MEMORY_USAGE` | Current memory usage (bytes) |
| `RELI_WATCH_MEMORY_PEAK` | Memory peak (bytes) |
| `RELI_WATCH_TIMESTAMP` | ISO 8601 timestamp |
| `RELI_WATCH_DUMP_PATH` | Dump file path (if memory-dump action ran) |

### Multiple Actions

Actions can be combined:

```bash
reli inspector:watch -p <pid> --memory-usage=256M \
  --action=memory-dump --action=log --action=exec \
  --action-exec-command='notify-send "Memory alert"' \
  --log-file=/var/log/reli.log
```

## Rate Limiting & Disk Protection

### Cooldown (`--cooldown=<seconds>`)

Minimum wait time before the same trigger fires again. Default: 60 seconds.

With consecutive fires, the cooldown increases exponentially:
- 1st fire: immediate
- 2nd: 60s wait
- 3rd: 120s wait (60 × 2)
- 4th: 240s wait (60 × 2²)
- Capped at `--backoff-max` (default: 3600s)

Resets when the trigger condition is no longer met.

```bash
--cooldown=30 --backoff-multiplier=2.0 --backoff-max=3600
```

### Hourly Rate Limit (`--max-triggers-per-hour=<N>`)

Maximum trigger fires per hour (sliding window). Default: 10.

### Disk Size Limit (`--max-dump-size=<size>`)

Maximum cumulative size of dump files. Default: 1G. Scans existing `watch-*.dump` files in the output directory on startup, so the limit persists across restarts.

```bash
--max-dump-size=2G --action-output-dir=/var/log/reli/dumps
```

### Oneshot Mode (`--oneshot=<N>`)

Capture N trigger events then exit. Alias for `--max-triggers`.

```bash
# Grab 5 memory dumps and stop
reli inspector:watch -p <pid> --memory-usage=128M --oneshot=5
```

**Note:** `--oneshot` is for ad-hoc debugging. For long-running daemons, use `--max-dump-size` + `--max-triggers-per-hour` + `--cooldown` instead — these persist across process restarts.

## Daemon Mode (`--target-regex`)

Monitor multiple processes simultaneously:

```bash
reli inspector:watch --target-regex="php-fpm" \
  --memory-usage=512M \
  --action=log \
  --log-file=/var/log/reli-watch.log
```

Each discovered process gets its own worker that independently reads heap stats, evaluates triggers, and applies cooldown. Only trigger events are sent back to the controller. The `--memory-limit` option is propagated to each worker process.

Global `--max-triggers` (or `--oneshot`) is a single counter across all workers.

### Output

```
[watch-daemon] Monitoring processes matching "{php-fpm}" | triggers=memory-usage | workers=8
[+process] PID=1234 assigned to worker 0
[+process] PID=2345 assigned to worker 1
[TRIGGERED] PID=1234 | trigger=memory-usage | mem=523.4M>512M (1/unlimited)
[-process] PID=2345 detached from worker 1
```

## All Options

| Option | Default | Description |
|--------|---------|-------------|
| `-p, --pid` | — | Target process PID (single-process mode) |
| `-P, --target-regex` | — | Regex to find target processes (daemon mode) |
| `--memory-usage` | — | Trigger on heap usage threshold |
| `--memory-growth-rate` | — | Trigger on memory growth rate |
| `--memory-peak-watch` | off | Trigger on peak memory update |
| `--rss-usage` | — | Trigger on RSS (Resident Set Size) threshold |
| `--watch-function` | — | Trigger on function in call stack |
| `--trace-depth-limit` | — | Trigger on call stack depth |
| `--watch-var` | — | Trigger on variable value condition (repeatable) |
| `--cpu-usage` | — | Trigger on process CPU usage (percent) |
| `--cpu-usage-exit` | same as enter | CPU exit threshold (hysteresis) |
| `--cpu-sustain` | `0` | Seconds CPU must stay above threshold |
| `--action` | `memory-dump` | Regular actions while active (repeatable) |
| `--on-enter` | — | Actions on enter transition (repeatable) |
| `--on-exit` | — | Actions on exit transition (repeatable) |
| `--trace-interval` | `10` | Sampling interval (ms) during tracing |
| `--action-exec-command` | — | Command for exec action |
| `--action-output-dir` | `.` | Output directory for dumps and logs |
| `--log-file` | stderr | Log file path for log action |
| `--poll-interval` | `1000` | Polling interval in ms (min: 100) |
| `--cooldown` | `60` | Cooldown seconds between trigger fires |
| `--backoff-multiplier` | `2.0` | Exponential backoff multiplier |
| `--backoff-max` | `3600` | Maximum backoff seconds |
| `--max-triggers-per-hour` | `10` | Hourly trigger rate limit |
| `--max-dump-size` | `1G` | Cumulative dump file size limit |
| `--max-triggers` | `0` | Total trigger limit (0=unlimited) |
| `--oneshot` | — | Alias for --max-triggers |
| `--memory-limit` | — | Set PHP memory_limit (e.g. 2G, 512M) |
| `--quiet-watch` | off | Suppress terminal status output |
| `-S, --stop-process` | off | Stop target with ptrace during reads |
| `-T, --threads` | `8` | Worker count (daemon mode) |
| `--no-cache` | off | Disable binary analysis cache |

## Container Deployment

### Kubernetes (Sidecar)

```yaml
spec:
  shareProcessNamespace: true
  containers:
  - name: app
    image: php-app:latest
  - name: reli-watch
    image: reli-prof:latest
    command: ["reli", "inspector:watch",
      "--target-regex=php-fpm",
      "--memory-usage=512M",
      "--action=memory-dump", "--action=log",
      "--log-file=/var/log/reli/watch.log",
      "--action-output-dir=/var/log/reli/dumps/",
      "--max-dump-size=2G", "--quiet-watch"]
    securityContext:
      capabilities:
        add: ["SYS_PTRACE"]
```

### Amazon ECS

```json
{
  "pidMode": "task",
  "containerDefinitions": [{
    "name": "reli-watch",
    "essential": false,
    "linuxParameters": {"capabilities": {"add": ["SYS_PTRACE"]}}
  }]
}
```

## Performance

Measured polling overhead (PHP 8.4, median):

| Configuration | Latency | Max polls/sec |
|---------------|---------|---------------|
| Tier 1 only (memory triggers) | ~680μs | ~1,470 |
| Tier 1 + Tier 2 (+ function/depth) | ~750μs | ~1,330 |
| Tier 1 + 2 + 3 (+ variable watch) | ~2,170μs | ~460 |

Trigger evaluation itself is < 1μs. The cost is dominated by `process_vm_readv` calls for reading target process memory.

At the default `--poll-interval=1000` (1 second), even the heaviest configuration uses only ~0.2% of the polling interval.
