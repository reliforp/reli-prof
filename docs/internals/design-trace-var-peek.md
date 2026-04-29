# Design Notes: Variable Peek Annotations for `inspector:trace`

**Status:** Draft
**Target command:** `inspector:trace`
**Depends on:** existing `VariableReader` (watch/peek-var), `BinaryTraceWriter` `SAMPLE_ANNOTATION` support

## Goal

Attach PHP variable values to each trace sample so that users can correlate
stacks with the runtime state of `$GLOBALS`, locals, class statics, or function
statics. Reuse the existing `--var` / `--watch-var` expression syntax from
`inspector:peek-var` and `inspector:watch`, so the user experience is
consistent across the three commands.

- **phpspy output**: emit `varpeek`-style comment lines in the sample block
  (matching phpspy's `# key = value` convention, which the existing
  `PhpSpyCompatibleParser` already ignores as comments).
- **rbt output**: emit `SAMPLE_ANNOTATION` events (event type `0x0B`) that are
  already part of the binary-trace format spec and writer.

## Motivation

Today, the `trace` loop captures only the call stack. To understand *why* a
particular stack is hot, users have to either:

1. Run `inspector:peek-var` separately, which gives point-in-time reads but
   can't be joined back to individual samples.
2. Use `inspector:watch --watch-var ...`, which is condition-based and doesn't
   record every sample.

Both lose the 1:1 correspondence between a stack and the value that caused it.
By folding variable reads into the trace loop, each sample can carry a small
amount of structured context — e.g. the current SQL query, request URI, or an
application-level counter — right next to the stack that produced it.

## Non-Goals

- Not a replacement for full object graph dumps. Only scalar / array-count /
  string values are serialized, exactly like `peek-var`.
- Not a condition trigger. If the value doesn't match some predicate, the
  sample is still emitted — this is pure decoration, not filtering.
- Not changing how `peek-var` or `watch --watch-var` work. The new option is
  additive on `trace`.

## CLI Surface

Add one repeatable option to `inspector:trace`:

```
--trace-var=<expression>        # repeatable
```

The expression grammar is **exactly** what `inspector:peek-var --var` accepts
(parsed by `Reli\Inspector\Watch\VariableSpec::parse()`):

| Scope | Syntax |
|---|---|
| Global | `global::$var` |
| Local | `local::<fqn>()$var` (use `<main>` for top-level) |
| Static property | `static::Class::$prop` |
| Function static | `func_static::<fqn>()$var` |
| Memory | `memory::memory_get_usage` etc. |

Nested access (`[key]`, `->prop`) is already supported by
`VariableReader::parsePathExpression()` and flows through unchanged.

### Rate limit / sampling options

Variable reads are Tier-3 in the watch tier model (~10ms per poll for heavy
configurations), so at a 10ms sampling interval the overhead is significant.
Two escape hatches:

```
--trace-var-every=N             # Read vars only every N-th sample (default: 1)
--trace-var-on-function=<fqn>   # Skip reads when <fqn> is not on the stack
```

`--trace-var-on-function` is a cheap gate: we already have the `CallTrace`,
so scanning it for a function name costs ~µs and lets us avoid the ~10ms
`process_vm_readv` round-trip on samples where the interesting frame isn't
active. This is especially valuable for `local::` and `func_static::` specs.

### Failure modes

If `VariableReader::readVariables()` throws for a specific spec (target frame
gone, indirect chain dangling, etc.), that spec silently drops from the
annotation set for **that sample only** — matching how `peek-var --repeat`
already tolerates per-poll failures. The trace sample is still emitted with
whatever specs did resolve.

## Data Flow

```
GetTraceCommand::execute
  ├── GetTraceSettings parses --trace-var into list<VariableSpec>
  ├── If specs non-empty, resolve CG address (needed for static/func_static)
  ├── Construct VariableReader (already DI-wired for peek-var / watch)
  │
  └── Main loop (per sample):
       ├── readCallTrace()                           (unchanged)
       ├── if specs && (sample_index % N == 0) && on_function_gate_passes:
       │     variable_reader->readVariables(...)    (reuses existing code)
       │     → array<string, VariableValue>
       │     → formatAnnotations()                  (new, small)
       │     → array<string, string>
       │ else:
       │     $annotations = null
       │
       └── $trace_output->output($call_trace, $annotations)
```

Key observation: `VariableReader::readVariables()` is already keyed by
`VariableSpec::$lookup_key` (e.g., `global::$counter`), so the shape returned
matches what we want for annotations — we only need a small adapter that
renders each `VariableValue` into a phpspy-safe string.

## Output: phpspy text format

### Line format

phpspy's varpeek convention is `# <name> = <value>` as a comment line
emitted inside the sample block (before the trailing blank separator).
reli-prof's existing `PhpSpyCompatibleParser` already skips lines where the
first token is `#` (see `PhpSpyCompatibleParser::parsePhpSpyCompatible()`,
`src/Converter/PhpSpyCompatibleParser.php:47`), so this is safe for
round-tripping through `converter:*` commands.

Example sample block with two annotations:

```
0 App\Repo\UserRepository::find /app/src/Repo/UserRepository.php:42
1 App\Service\UserService::lookup /app/src/Service/UserService.php:17
2 <main> /app/public/index.php:9
# global::$request_uri = "/users/1234"
# local::App\Service\UserService::lookup()$user_id = 1234

```

The blank line still terminates the sample. Frames come first, annotations
after — this way a reader that strips comments still gets a valid phpspy
trace.

### Value rendering

The rendering rules aim to stay on one line, be unambiguous, and match
`VariableValue` types directly:

| Type | Rendered |
|---|---|
| `TYPE_LONG` | `(int) 42` |
| `TYPE_DOUBLE` | `(float) 3.14` |
| `TYPE_STRING` | `(string) "hello"` — JSON-escaped, truncated at 200 chars with `...` |
| `TYPE_BOOL` | `(bool) true` / `(bool) false` |
| `TYPE_ARRAY` | `(array) count=10` |
| `TYPE_NULL` | `null` |
| `TYPE_UNKNOWN` | `(unknown)` |
| spec not resolved | the annotation line is **omitted** (not emitted as `<not found>`) |

Strings are JSON-escaped (not just `addslashes`) so that embedded newlines,
tabs, and non-ASCII bytes can't break the line-oriented format. The same
200-char truncation as `PeekVarCommand::truncate()` is applied.

> **Note on matching phpspy exactly:** upstream phpspy's varpeek (`-V`) writes
> `# varname = value_repr`. We use the same `# key = value` shape; the key is
> the full `VariableSpec::$lookup_key` (e.g. `global::$counter`) rather than a
> short alias, because that's the stable identifier already used by peek-var
> and watch. Tools that depend on a specific shorter form can strip the prefix.

### Template changes

`resources/templates/phpspy.php` currently iterates frames only. It will be
extended to iterate a new `$annotations` variable supplied alongside
`$call_trace`:

```php
<?php foreach ($call_trace->call_frames as $depth => $frame): ?>
<?= $depth ?> <?= $frame->getFullyQualifiedFunctionName() ?> <?= $frame->file_name ?>:<?= $frame->getLineno(), "\n" ?>
<?php endforeach ?>
<?php foreach ($annotations ?? [] as $key => $value): ?>
# <?= $key ?> = <?= $value, "\n" ?>
<?php endforeach ?>
<?= "\n" ?>
```

Same edit is applied to `phpspy_with_opcode.php`. Templates receiving a
missing `$annotations` continue to work (the `?? []` makes it no-op).

## Output: rbt binary format

The binary trace format already defines `SAMPLE_ANNOTATION` (`0x0B`):

```
Payload:
  [count: varint]
  [key_string_id: varint] [value_string_id: varint]  × count
```

`BinaryTraceWriter::writeTrace()` already accepts
`?array<string,string> $annotations` as its third argument and emits the
event after the `SAMPLE` / `COMPACT_SAMPLE`. The annotation values are
interned through the segment's `STRING_DEF` table, so repeated values
(e.g. the same SQL query across many samples) cost only one pair of varints
per sample after the first. See `docs/tracing/binary-trace-format.md` §SAMPLE_ANNOTATION
for the full spec.

### Impact on REPEAT_SAMPLE (RLE)

The spec already states: *"The writer considers two consecutive samples
identical only if both their `stack_id` and their annotations match. If the
annotation changes, the run is broken."* This has direct consequences for
`--trace-var`:

- **Stable annotations** (e.g. `global::$config['app_name']`): RLE keeps
  working perfectly, adds ~2 bytes per changed run.
- **Noisy annotations** (e.g. a monotonically increasing counter): RLE
  effectively breaks per sample. Output grows to roughly
  `samples × (compact_sample + annotation)`. Users should be warned to pick
  variables that are stable over the scales they care about, or to use
  `--trace-var-every=N` to cut the read rate.

This is documented in the command's help text and the new section of
`trace-command.md` (TBD).

## Code changes (file by file)

### New / extended

- `src/Inspector/Settings/GetTraceSettings/GetTraceSettings.php`
  — add `list<VariableSpec> $var_specs` and the throttle fields.
- `src/Inspector/Settings/GetTraceSettings/GetTraceSettingsFromConsoleInput.php`
  — register `--trace-var`, `--trace-var-every`, `--trace-var-on-function`;
  parse each `--trace-var` via `VariableSpec::parse()`.
- `src/Inspector/Watch/VariableValueFormatter.php` (new, small)
  — pure function `format(VariableValue): string` producing the phpspy-safe
  rendered form described above. Shared between the trace command and, if
  desired, `PeekVarCommand` (dedup of the existing `formatValue()` method
  there is a cleanup opportunity, not required by this feature).
- `src/Inspector/Output/TraceOutput/TraceOutput.php`
  — extend the interface signature:
  `public function output(CallTrace $call_trace, ?array $annotations = null): void;`
  The default-null keeps all existing call sites compiling.
- `src/Inspector/Output/TraceOutput/FormattedTraceOutput.php`
  — pass `$annotations` through to the formatter (formatter interface gets a
  matching extension).
- `src/Inspector/Output/TraceOutput/BinaryTraceOutput.php`
  — forward `$annotations` into `BinaryTraceWriter::writeTrace(...)`
  (the writer already supports this; we just plumb it).
- `src/Inspector/Output/TraceFormatter/CallTraceFormatter.php`
  — extend: `format(CallTrace $call_trace, ?array $annotations = null): string;`
- `src/Inspector/Output/TraceFormatter/Templated/TemplatedCallTraceFormatter.php`
  — pass `$annotations` into the template scope alongside `$call_trace`.
- `resources/templates/phpspy.php`, `resources/templates/phpspy_with_opcode.php`
  — add the annotation `foreach` loop (see above).
- `src/Command/Inspector/GetTraceCommand.php`
  — resolve CG when needed, construct `VariableReader`, call
  `readVariables()` inside the loop, gate via `--trace-var-every` /
  `--trace-var-on-function`, format with `VariableValueFormatter`, pass
  through to `$trace_output->output()`.

### DI wiring

`VariableReader` is already registered (used by `PeekVarCommand` and
`WatchCommand`), so `GetTraceCommand` just needs a new constructor argument.
Same for `VariableValueFormatter` once extracted.

## Tests

- `tests/Inspector/Watch/VariableValueFormatterTest.php` — unit tests for
  each `VariableValue::TYPE_*` branch, including string escaping /
  truncation.
- `tests/Inspector/Output/TraceFormatter/Templated/...` — snapshot test that
  renders a `CallTrace` + `$annotations` through `phpspy.php` and compares
  against a fixture.
- `tests/Converter/PhpSpyCompatibleParserTest.php` — add a case where a
  sample block contains both frames and `# k = v` lines, confirming the
  parser still yields the expected `ParsedCallTrace` (comments skipped).
- `tests/Converter/BinaryTrace/BinaryTraceRoundTripTest.php` — extend: write
  a sample with annotations, read it back, assert
  `BinaryTraceSample::$annotations` round-trips.
- `tests/Command/Inspector/GetTraceCommandTest.php` (or closest existing)
  — end-to-end: run `inspector:trace --trace-var='global::$x'` against a
  fixture process, assert the output contains the expected `# global::$x = ...`
  line in phpspy mode and a `SAMPLE_ANNOTATION` event in rbt mode.

## Daemon mode

`inspector:daemon` runs N workers in parallel, each of which owns its own
trace loop against a single target PID. `--trace-var` must work there too —
otherwise operators have to choose between multi-process coverage and
per-sample context. This section covers how the same option plugs into the
daemon without changing the rest of the daemon architecture.

### Where variable reads happen: in each worker

The worker process is the only party with `process_vm_readv` access to the
target PHP process. All variable reads must therefore happen **inside
`PhpReaderTraceLoop::run()`** (`src/Inspector/Daemon/Reader/Worker/PhpReaderTraceLoop.php:45`),
right after `readCallTrace()` returns — exactly the same insertion point as
in the single-process `GetTraceCommand`.

The controller never touches target memory on the worker's behalf. It only
consumes the annotations that the worker has already produced.

### Option propagation

`SetSettingsMessage`
(`src/Inspector/Daemon/Reader/Protocol/Message/SetSettingsMessage.php`)
already carries `GetTraceSettings` from the controller to each worker at
start-up. Since `--trace-var`, `--trace-var-every`, and
`--trace-var-on-function` live on `GetTraceSettings` (per the single-process
design above), they ride along for free. No new IPC message is required for
configuration.

The worker parses / holds the specs exactly once in
`PhpReaderEntryPoint::run()`, then reuses them for every attach cycle.

### Resolving the CG address per target

`TargetProcessDescriptor`
(`src/Inspector/Daemon/Dispatcher/TargetProcessDescriptor.php`) currently
holds `eg_address`, `sg_address`, and `php_version` — but **not**
`cg_address`. `static::` and `func_static::` specs need CG.

`inspector:watch` already hit this problem and solved it by introducing
`WatchTargetDescriptor` + `WatchDescriptorRetriever`
(`src/Inspector/Watch/Daemon/Searcher/WatchTargetDescriptor.php`) — a
parallel descriptor class with a `cg_address` field, populated at discovery
time by the searcher. We have two options:

1. **Reuse the watch descriptor** — share `WatchTargetDescriptor` /
   `WatchDescriptorRetriever` between watch daemon and trace daemon. Rename
   them to something neutral (e.g. `DaemonTargetDescriptorWithCg`) and let
   both daemons consume them. Lowest code duplication; small refactor of
   the watch daemon.
2. **Add `?int $cg_address` to `TargetProcessDescriptor`** — optional field,
   0/null when the trace command has no var-peek specs, populated only when
   the trace daemon is launched with `--trace-var` that needs CG. Smallest
   diff, no class hierarchy shuffling, at the cost of the base type
   knowing about a feature it doesn't otherwise use.

Recommend **option 2** for this PR. The field is cheap (one int), watch
daemon continues to use its richer descriptor, and the trace daemon gets
what it needs with no new class. If a third feature later wants CG on its
descriptor, option 1 becomes worth the refactor.

Either way, **CG resolution happens in the searcher** (once per newly
discovered PID, cached), not in the reader worker, so the trace loop does
not pay the lookup cost per sample.

### Data flow per output format

The daemon has three output modes (see `DaemonCommand::execute`,
`src/Command/Inspector/DaemonCommand.php:90`). Each needs a slightly
different plumbing for annotations:

#### (a) Per-worker `rbt` (`-f rbt`) — simplest

The worker writes directly to its own `worker_<pid>.rbt` file via
`PhpReaderEntryPoint::writeBinaryTrace()`
(`src/Inspector/Daemon/Reader/Worker/PhpReaderEntryPoint.php:152`).
We extend that call:

```php
$this->binary_writer->writeTrace(
    CallTraceConverter::toParsed($call_trace),
    $delta_us,
    $annotations,   // new: already a string→string map
);
```

`BinaryTraceWriter::writeTrace()` already accepts `?array $annotations`
and handles the RLE break case. **Zero IPC changes** — the annotations
never leave the worker. This is the cleanest path and the one I'd ship
first.

#### (b) Bundled `rbt-bundled` (`-f rbt-bundled`) — writer extension

Workers send `TraceMessage` to the controller; the controller's
`writeBundledTrace()`
(`src/Command/Inspector/DaemonCommand.php:282`) writes `PID_SAMPLE` events
via `BinaryTraceWriter::writePidTrace()`.

Two changes:

1. **`TraceMessage`**
   (`src/Inspector/Daemon/Reader/Protocol/Message/TraceMessage.php`) —
   add `public ?array $annotations = null;` (serializable over the
   existing AMPHP IPC channel).
2. **`BinaryTraceWriter::writePidTrace()`**
   (`src/Converter/BinaryTrace/BinaryTraceWriter.php:193`) — accept
   `?array $annotations = null` and emit `SAMPLE_ANNOTATION` right after
   `writePidSample()`. The rbt spec explicitly allows `SAMPLE_ANNOTATION`
   to follow `PID_SAMPLE` (see `docs/tracing/binary-trace-format.md`
   §SAMPLE_ANNOTATION). `writePidSample()` does not participate in the
   RLE / run-length path, so the annotation logic is a straight append —
   no pending-run state to reason about.

Controller-side call becomes:

```php
$writer->writePidTrace(
    CallTraceConverter::toParsed($result->trace),
    $result->pid,
    $delta_us,
    $result->annotations,
);
```

#### (c) Template (phpspy text) mode — annotations ride on `TraceMessage`

The worker sends `TraceMessage` up; the controller passes
`$result->trace` into `$trace_output->output(...)`
(`DaemonCommand::execute`, inside the worker-dispatch async loop around
line 228). We extend the same call site:

```php
$trace_output->output($result->trace, $result->annotations);
```

Since `TraceOutput::output()` already takes an optional `$annotations`
parameter per the single-process design above, no additional interface
change is needed for daemon mode — just the `TraceMessage` field
extension from (b).

### Summary of changes specific to daemon mode

On top of the single-process change list in the previous sections, daemon
mode adds:

- **`src/Inspector/Daemon/Dispatcher/TargetProcessDescriptor.php`** — add
  `?int $cg_address = null` (option 2 above).
- **`src/Inspector/Daemon/Searcher/Worker/ProcessDescriptorRetriever.php`**
  (or its daemon-searcher equivalent) — resolve CG when the worker pool
  was started with trace-var specs that need it. Gate the extra lookup on
  `GetTraceSettings::$needs_cg` so daemons without var-peek pay nothing.
- **`src/Inspector/Daemon/Reader/Protocol/Message/TraceMessage.php`** — new
  `?array $annotations` field.
- **`src/Inspector/Daemon/Reader/Worker/PhpReaderTraceLoop.php`** — accept
  a `VariableReader` (new constructor arg) and, inside the loop body,
  call it after `readCallTrace()` then attach the result to the yielded
  `TraceMessage`. Respect `--trace-var-every` and
  `--trace-var-on-function` exactly like the single-process command.
- **`src/Inspector/Daemon/Reader/Worker/PhpReaderEntryPoint.php`** — in
  the per-worker rbt branch (line 104 area), pass
  `$message->annotations` through to `writeBinaryTrace()`; extend that
  helper to forward into `writeTrace()`.
- **`src/Command/Inspector/DaemonCommand.php`** — in the worker-dispatch
  async loop, pass `$result->annotations` into `writeBundledTrace()` or
  `$trace_output->output()` depending on mode.
- **`src/Converter/BinaryTrace/BinaryTraceWriter.php`** — extend
  `writePidTrace()` signature and body to emit `SAMPLE_ANNOTATION`
  when annotations are present.
- **DI wiring** — `VariableReader` already exists as a shared service; it
  needs to be added to the worker container so `PhpReaderTraceLoop` can
  receive it.

### Performance: worker-count multiplier

The single-process analysis puts Tier-3 variable reads at ~2ms overhead per
poll on a warm cache, dominated by `process_vm_readv`. In daemon mode this
cost is paid **per attached worker**, not per concurrent target in aggregate
(workers read in parallel). However:

- Total wall-clock CPU used by the daemon grows roughly linearly with
  `active_workers × vars_per_sample`. Operators running at 10ms sampling
  with 8 workers and 5 variables should expect the daemon itself to consume
  a measurable core fraction. `--trace-var-every` remains the primary
  throttle.
- `--trace-var-on-function` becomes disproportionately valuable in daemon
  mode: only workers whose current target stack contains the gate function
  pay the `process_vm_readv` cost. For frameworks where the interesting
  frame is rare (e.g. "only when inside `PDO::execute`"), the total cost
  across the worker pool can drop by 10×+.

### Annotation reliability during attach/detach

Worker attach/detach is a normal lifecycle event. `VariableReader`
throwing on a stale frame (e.g. target just exited `local::fn()`) is
already handled by the per-spec `try { ... } catch (\Throwable) {}` in
`VariableReader::readVariables()`, so there is no new failure mode
introduced by running in daemon workers. The annotation set simply
shrinks for that sample and the trace is emitted normally.

On hard-detach (target process gone), `PhpReaderTraceLoop::run()` already
terminates the generator; no annotations are produced for the half-read
sample. The worker then proceeds to the next attach cycle.

### Not in scope for daemon mode (yet)

- **Per-target specs.** Today a single `--trace-var` list applies to every
  target in the daemon. A future `--trace-var-for=<regex>:<spec>` that
  scopes specs to PIDs matching a regex (or a function signature) would
  let operators read `$controller->requestId` on web workers while
  reading `$job->id` on queue workers. Straightforward extension, deferred
  to a follow-up PR so this one stays bounded.
- **Dynamic re-config.** Changing the var-peek spec list at runtime (via a
  signal or control socket) is nice-to-have, not required. Restarting the
  daemon is fine for v1.

## Follow-ups / out of scope

- **Numeric annotations in rbt**: the current spec stores annotations as
  string pairs. If we later want to store ints natively, that's a
  `SAMPLE_ANNOTATION` v2 event addition (backward compatible via unknown-
  event skip).
- **Cross-sample diff encoding**: for noisy values that move RLE, an
  `ANNOTATION_DELTA` scheme (only emit changed keys) could be added later.
- **Per-spec config file (`--trace-var-config=<path>`)**: once users want
  distinct `every` / `on-function` gates / short display names / error
  policy (skip vs. mark `<error>`) *per variable*, the `--trace-var` flag
  shape starts to fight them — all attached flags become global. A YAML
  (or TOML) file that lists each spec with its own attributes is the
  natural escape hatch, e.g.:
  ```yaml
  vars:
    - spec: global::$request->requestId
      name: req
      every: 1
    - spec: local::App\Queue\Worker::run()$job->id
      name: job_id
      every: 5
      on_function: App\Queue\Worker::run
      on_error: skip
  ```
  Not needed for v1 — the flag shape is enough while the spec count is
  small and attributes are uniform. Revisit when either condition breaks
  (multiple specs needing different cadences, or users asking for stable
  short names in the output). The current CLI options are designed to be
  a clean subset of what this file would express, so the migration is
  additive: introduce `--trace-var-config`, keep the flags as sugar for
  "one spec with defaults", and have both paths build the same internal
  `list<VariableSpec>` + attribute record.

## References

- phpspy varpeek format — upstream `phpspy` emits `# key = value` comment
  lines inside each sample block; reli-prof's parser already treats any
  `#`-prefixed token as a comment (`src/Converter/PhpSpyCompatibleParser.php:47`).
- `docs/tracing/binary-trace-format.md` §SAMPLE_ANNOTATION — authoritative definition
  of event `0x0B`.
- `docs/inspection/peek-var-command.md` — variable expression grammar.
- `docs/monitoring/watch-command.md#variable-value-watch-var` — the same grammar in the
  watch context.
- `docs/internals/php-variable-reading.md` — how `VariableReader` walks Zend
  structures.
