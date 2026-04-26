# inspector:trace --trace-var — Per-Sample Variable Peek in Traces

`inspector:trace --trace-var` attaches PHP variable values to each trace
sample as they are captured, so you can join "what the stack was doing" to
"what the runtime state was" without running a separate tool. Values are
read from the target process with the same techniques as
[`inspector:peek-var`](peek-var-command.md) and
[`inspector:watch --watch-var`](../monitoring/watch-command.md),
then emitted next to each sample in the trace output.

## Quick Start

```bash
# Tag every sample with a global's value
reli inspector:trace -p <pid> --trace-var='global::$request_uri'

# Multiple variables — each appears as its own annotation line
reli inspector:trace -p <pid> \
  --trace-var='global::$request_uri' \
  --trace-var='local::App\Controller::handle()$user_id'

# Only read every 10th sample to cut overhead at high sampling rates
reli inspector:trace -p <pid> -s 10000000 \
  --trace-var='global::$state' \
  --trace-var-every=10

# Only read when a specific frame is active — skips process_vm_readv entirely
# when the target function isn't on the stack
reli inspector:trace -p <pid> \
  --trace-var='local::App\PDOProxy::execute()$query' \
  --trace-var-on-function='App\PDOProxy::execute'

# Track memory usage per sample
reli inspector:trace -p <pid> \
  --trace-var='memory::memory_get_usage' \
  --trace-var='memory::memory_get_peak_usage'

# Binary (rbt) output — annotations ride on SAMPLE_ANNOTATION events
# (-F rbt is implied by the .rbt extension on -o)
reli inspector:trace -p <pid> -o trace.rbt \
  --trace-var='global::$counter'
```

## Requirements

See [Getting started § Requirements](../getting-started.md#requirements) for the
common runtime and target requirements. No command-specific overrides apply.

## Variable Specification

`--trace-var` takes **exactly the same expression grammar** as
`inspector:peek-var --var` — see the full reference in
[docs/peek-var-command.md](peek-var-command.md#variable-specification). In
short:

| Scope | Syntax | What it reads |
|-------|--------|---------------|
| Global | `global::$var` | `$GLOBALS['var']` |
| Local | `local::func()$var` | Local variable in a specific function frame |
| Static property | `static::Class::$prop` | Class static property |
| Function static | `func_static::func()$var` | Function's `static $var` |
| Memory | `memory::memory_get_usage` | Zend MM heap stats |

Nested array keys and object properties use the same path syntax:

```bash
--trace-var='global::$config[database][host]'
--trace-var='global::$app->cache->size'
--trace-var='global::$container->services[cache]->pool[active]'
```

The `memory::` scope exposes `memory_get_usage()` / `memory_get_peak_usage()`
equivalents. Available names: `memory_get_usage`, `memory_get_peak_usage`,
`memory_get_usage_real`, `memory_get_peak_usage_real`. See
[docs/peek-var-command.md](peek-var-command.md#memory-scope) for details.

For `local::` and `func_static::`, the function name is **required**; use
`<main>` for the top-level script scope.

## Annotations in each output format

### phpspy text (`-F template:phpspy`, default)

Annotations appear as `# <spec> = <value>` comment lines **after** the frame
list, before the blank-line separator:

```
0 App\Controller::handle /app/src/Controller.php:17
1 App\Http\Router::dispatch /app/src/Router.php:42
2 <main> /app/public/index.php:9
# global::$request_uri = (string) "/users/1234"
# local::App\Controller::handle()$user_id = (int) 1234

0 App\Controller::handle /app/src/Controller.php:18
1 App\Http\Router::dispatch /app/src/Router.php:42
2 <main> /app/public/index.php:9
# global::$request_uri = (string) "/users/1234"
# local::App\Controller::handle()$user_id = (int) 1234

```

reli's own phpspy parser and every downstream tool that treats `#`-prefixed
lines as comments will simply ignore these lines, so the output stays
round-trip safe — you can pipe it into `reli converter:pprof`,
`reli converter:speedscope`, or any phpspy-compatible consumer without
breaking them.

Value rendering is stable and single-line by construction — strings are
JSON-escaped and truncated at 200 characters, so embedded newlines, tabs,
and non-ASCII bytes can never break the line-oriented format:

| Type | Rendered |
|------|----------|
| `int` | `(int) 42` |
| `float` | `(float) 3.14` |
| `string` | `(string) "hello\nworld"` (truncated at 200 chars with `...`) |
| `bool` | `(bool) true` / `(bool) false` |
| `array` | `(array) count=10` |
| `null` | `null` |
| unknown type | `(unknown)` |
| spec not resolved | the annotation line is **omitted** for that sample |

### phpspy with opcode (`-F template:phpspy_with_opcode`)

Same shape as plain phpspy, with annotations after the frame list. The
opcode column on frame lines is unaffected.

### rbt binary (`-F rbt` / `-F rbt-bundled`)

Annotations are emitted as `SAMPLE_ANNOTATION` (event type `0x0B`)
directly following each sample event. Keys and values are interned via
`STRING_DEF` per segment, so repeated values (e.g. the same SQL query
across many samples) cost only one pair of varints per sample after the
first. Full format details are in
[docs/tracing/binary-trace-format.md](../tracing/binary-trace-format.md#sample_annotation-0x0b).

All downstream converters that already read `.rbt`
(`reli converter:phpspy`, `reli converter:speedscope`,
`reli converter:pprof`, `reli converter:folded`) preserve or discard
annotations based on what the target format supports — the phpspy text
converter re-emits them as `# key = value` lines, matching the live-capture
output.

#### RLE interaction

In compact (no-timestamp) mode rbt uses `REPEAT_SAMPLE` to run-length-encode
consecutive identical samples. The writer considers two samples identical
only if **both** their stack and their annotations match. This has two
practical consequences:

- **Stable annotations** (e.g. `global::$config['app_name']`, session ID,
  tenant ID): RLE keeps working perfectly; the annotation adds roughly 2
  bytes per changed run.
- **Noisy annotations** (e.g. a monotonically increasing counter,
  microsecond timestamps): RLE effectively breaks per sample. Output grows
  to roughly `samples × (compact_sample + annotation)`, which is still far
  smaller than phpspy text but larger than annotation-free rbt. Prefer
  values that are stable over the scales you care about, or use
  `--trace-var-every` to cut the read rate.

### JSON Lines (`-F template:json_lines`)

The `json_lines` template is currently **unchanged** when `--trace-var` is
set — its JSON shape is stable for `jq`-based consumers. Annotations in
JSON Lines output are a planned follow-up via a dedicated template; for
now, use phpspy text or rbt if you need per-sample annotations.

## Native (C-level) Trace Support

`--with-native-trace` is fully supported. The merged native+PHP output
carries annotations end-to-end in both phpspy text and rbt formats:

```bash
reli inspector:trace -p <pid> --with-native-trace \
  --trace-var='global::$request_uri'
```

```
0 libphp.so::zend_execute+0x42 [native]:0
1 App\Controller::handle /app/src/Controller.php:17
2 <main> /app/public/index.php:9
# global::$request_uri = (string) "/users/1234"

```

Variable reads always resolve against the **PHP** state of the sample
(call frames, symbol table, CVs); the presence of native frames has no
effect on which variables are visible. When a sample is native-only (via
`--native-trace-anytime`, emitted during interpreter initialization or
shutdown), no annotations are attached, because there are no PHP frames
to inspect.

## Rate Limiting

Variable reads go through `process_vm_readv(2)` just like other
`inspector:*` reads. At default sampling rates the overhead is modest,
but at high sampling rates (10ms intervals × several variables) it adds
up. Two flags let you throttle without losing trace resolution:

### `--trace-var-every=<N>` (default: 1)

Read variables only every **N-th** sample. The trace itself is captured
at full rate; only the variable reads are throttled. Useful when the
variable you're watching changes on a much slower timescale than the
sampling interval:

```bash
# 10ms sampling, but only read $memory_usage every 100th sample (every 1s)
reli inspector:trace -p <pid> -s 10000000 \
  --trace-var='global::$memory_usage' \
  --trace-var-every=100
```

### `--trace-var-on-function=<fqn>`

Skip the variable read entirely when the named fully-qualified function
is **not** on the call stack. This is a cheap in-process gate — it scans
the already-captured PHP call trace in microseconds and only pays the
`process_vm_readv` cost when the target frame is actually active:

```bash
# Only read $query when PDO::execute is on the stack
reli inspector:trace -p <pid> \
  --trace-var='local::App\PDOProxy::execute()$query' \
  --trace-var-on-function='App\PDOProxy::execute'
```

This is the right knob when you're correlating a specific function's
state with the stacks that reach it — you pay zero extra cost on samples
that are elsewhere in the program.

The two flags compose: `--trace-var-every=5 --trace-var-on-function=...`
means "at most once every 5 samples **and** only when the gate passes".

## Error Handling

Variable reads can fail for harmless reasons — the target frame may have
just returned, an object may have been freed mid-read, or the process may
be in the middle of a memory allocation that briefly makes the structure
inconsistent. `--trace-var` degrades gracefully:

- A single spec that fails (target not found, indirect chain dangling,
  etc.) is silently omitted from that sample's annotation set. Other
  specs in the same sample are still emitted.
- If the whole `readVariables` call throws (transient memory read
  failure), that sample's annotation set is dropped entirely. The trace
  **sample itself is still emitted** — you get a naked frame list, never
  a gap in the trace.
- Specs that resolve but have no value at the moment (e.g. a CV that
  hasn't been assigned yet) produce no line for that sample.

This is the same contract as `inspector:peek-var --repeat` and
`inspector:watch --watch-var`: transient reader failures never interrupt
the loop.

## Daemon Mode

`inspector:daemon` supports `--trace-var` in all three output modes:

```bash
# Per-worker rbt (annotations never leave the worker; zero IPC overhead)
reli inspector:daemon -P 'php-fpm' -F rbt \
  --trace-var='local::App\Svc::run()$req_id'

# Bundled rbt (annotations ride on PID_SAMPLE + SAMPLE_ANNOTATION)
reli inspector:daemon -P 'php-fpm' -F rbt-bundled \
  --trace-var='global::$request_uri'

# Template text (annotations flow over IPC to the controller)
reli inspector:daemon -P 'php-fpm' -F template:phpspy \
  --trace-var='global::$request_uri'
```

The same `--trace-var-every` / `--trace-var-on-function` knobs apply to
every worker. The specs apply uniformly across all discovered targets —
per-target specs are a planned future extension.

### Performance considerations in daemon mode

Variable reads are paid **per attached worker**, not once globally — the
cost across the worker pool is roughly
`active_workers × vars_per_sample × sample_rate`. At 8 workers sampling
at 10ms with 5 variables each, the daemon uses a measurable but still
small fraction of a CPU. Two mitigations worth knowing:

- `--trace-var-on-function` is disproportionately valuable in daemon
  mode: only the workers whose **current** stack contains the gate
  function pay the `process_vm_readv` cost. For rare target frames
  (e.g. "only when inside `PDO::execute`"), total daemon cost can drop
  by 10× or more.
- `--trace-var-every` is a uniform knob across all workers. Use it when
  the variable you care about changes on a slower timescale than the
  sampling interval.

CG (compiler globals) resolution for `static::` and `func_static::`
specs happens **in the searcher**, once per discovered PID, and is
cached. So adding a static-scope spec doesn't make the hot reader path
any more expensive than the equivalent local-scope spec.

## All Options

| Option | Default | Description |
|--------|---------|-------------|
| `--trace-var=<expr>` | — | Variable to peek per sample (repeatable). Same grammar as `inspector:peek-var --var` |
| `--trace-var-every=<N>` | `1` | Read variables only every N-th sample |
| `--trace-var-on-function=<fqn>` | — | Skip reads when the given FQN is not on the stack |

All existing `inspector:trace` / `inspector:daemon` options (sampling
interval, output format, native traces, etc.) continue to work unchanged.
Run `reli inspector:trace --help` for the complete list.

## Relationship with `peek-var` and `watch --watch-var`

| | `inspector:peek-var` | `inspector:watch --watch-var` | `inspector:trace --trace-var` |
|---|---|---|---|
| **Purpose** | Read current value | Trigger actions on condition | Attach values to every trace sample |
| **Output** | Variable values | Trigger events + actions | Call traces with per-sample annotations |
| **When values are read** | On demand (one-shot or repeat) | Every poll while monitoring | Once per trace sample (or every N-th) |
| **Joins to stacks?** | No | No (separate trace captures) | **Yes**, 1:1 with each sample |
| **Best for** | Quick checks, scripting | Production monitoring / alerting | Correlating runtime state with hot stacks |

Use `--trace-var` when you want to answer *"which stacks are hot while
`$request_uri` is `/api/heavy`?"* or *"what values of `$user_id` show up
in the `PDO::execute` stack?"* — questions that need the stack and the
value to be recorded together, sample by sample.
