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
per sample after the first. See `docs/binary-trace-format.md` §SAMPLE_ANNOTATION
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

## Follow-ups / out of scope

- **Daemon mode**: the same flag should eventually propagate from
  `inspector:daemon` to its workers so bundled / per-worker rbt output can
  carry annotations. This is mechanically similar but involves IPC message
  changes and is deferred to a later PR.
- **Numeric annotations in rbt**: the current spec stores annotations as
  string pairs. If we later want to store ints natively, that's a
  `SAMPLE_ANNOTATION` v2 event addition (backward compatible via unknown-
  event skip).
- **Cross-sample diff encoding**: for noisy values that move RLE, an
  `ANNOTATION_DELTA` scheme (only emit changed keys) could be added later.

## References

- phpspy varpeek format — upstream `phpspy` emits `# key = value` comment
  lines inside each sample block; reli-prof's parser already treats any
  `#`-prefixed token as a comment (`src/Converter/PhpSpyCompatibleParser.php:47`).
- `docs/binary-trace-format.md` §SAMPLE_ANNOTATION — authoritative definition
  of event `0x0B`.
- `docs/peek-var-command.md` — variable expression grammar.
- `docs/watch-command.md#variable-value-watch-var` — the same grammar in the
  watch context.
- `docs/internals/php-variable-reading.md` — how `VariableReader` walks Zend
  structures.
