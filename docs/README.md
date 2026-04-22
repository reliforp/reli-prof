# Reli documentation

A task-oriented index. Start here if you know what you want to do but
don't know which command (or which doc) to reach for. Everything in
this file links out to a dedicated doc or a section of the top-level
[README](../README.md).

## Getting started

- **New here?** [getting-started.md](getting-started.md) walks you from install to your first trace in 5 minutes.
- How it works (architecture overview) — [README § How it works](../README.md#how-it-works)
- Supported PHP versions and platforms — [getting-started.md § Requirements](getting-started.md#requirements)

## Capture call traces (where time is spent)

Reli samples the call stack on a timer, so each trace reflects what the VM is doing over wall-clock time — including I/O, lock waits, and sleeps, not just on-CPU work. Aggregated across many samples, this shows where your code spends its time.

| I want to... | Use | More |
|---|---|---|
| Attach to a running process | `inspector:trace -p <pid>` | [tracing/capturing-traces.md](tracing/capturing-traces.md) |
| Capture to a compact binary file for later analysis **(recommended)** | `inspector:trace -p <pid> -F rbt -o trace.rbt` | [tracing/binary-trace-format.md](tracing/binary-trace-format.md) |
| Trace many processes at once (e.g. a php-fpm pool) | `inspector:daemon -P <regex>` | [tracing/capturing-traces.md](tracing/capturing-traces.md) |
| Live `top`-style aggregation | `inspector:top -P <regex>` | [tracing/capturing-traces.md](tracing/capturing-traces.md) |
| Include C-level frames | add `--with-native-trace` | [tracing/advanced-capture.md](tracing/advanced-capture.md) |
| See the opcode in phpspy text output (`.rbt` records it unconditionally) | `--template=phpspy_with_opcode` | [tracing/advanced-capture.md](tracing/advanced-capture.md) |
| Resolve JIT-compiled function names in native traces | `opcache.jit_debug=0x10` + `--with-native-trace` | [tracing/advanced-capture.md](tracing/advanced-capture.md) |
| Use phpspy as the fast C backend, with reli-driven ZTS support | `phpspy:trace`, `phpspy:daemon` | [tracing/phpspy-hybrid.md](tracing/phpspy-hybrid.md) |
| Attach a PHP variable to every sample | `inspector:trace --trace-var=…` | [inspection/trace-var-command.md](inspection/trace-var-command.md) |

## Analyse call traces

| I want to... | Use | More |
|---|---|---|
| Browse interactively in the terminal **(recommended)** | `rbt:explore trace.rbt` | [tracing/rbt-analyze-and-explore.md](tracing/rbt-analyze-and-explore.md) |
| One-shot text report (hot frames, callers/callees, live tail) | `rbt:analyze trace.rbt` | [tracing/rbt-analyze-and-explore.md](tracing/rbt-analyze-and-explore.md) |
| Show the opcode at each frame | `rbt:analyze --with-opcode`, or press `c` in `rbt:explore` | [tracing/rbt-analyze-and-explore.md](tracing/rbt-analyze-and-explore.md) |
| Convert to speedscope / pprof / flamegraph / callgrind / folded | `converter:<format>` | [tracing/binary-trace-format.md](tracing/binary-trace-format.md) |
| Decode `.rbt` back to phpspy text | `converter:phpspy` | [tracing/binary-trace-format.md](tracing/binary-trace-format.md) |
| Recover a corrupted or truncated `.rbt` file | `rbt:recover` | [tracing/binary-trace-format.md](tracing/binary-trace-format.md) |

## Capture memory graphs (where memory is used)

A memory graph is reli's PHP heap reconstruction — values (objects, arrays, strings, call frames) as nodes, references as edges. Analysing a live process stops the target until the heap walk finishes; dumping the raw memory does not. **In production, dump now and analyse later.**

| I want to... | Use | More |
|---|---|---|
| Dump now (short stop), analyse offline **(recommended)** | `inspector:memory:dump` → `inspector:memory:analyze -f binary -o snap.rmem` | [memory/memory-dump.md](memory/memory-dump.md) |
| One-shot live capture (longer stop, one command) | `inspector:memory -p <pid> -f binary -o snap.rmem` | [memory/memory-profiler.md](memory/memory-profiler.md) |
| From a core file (crashed / post-mortem) | `inspector:coredump` | [memory/coredump.md](memory/coredump.md) |

Tip: **`-f binary` (`.rmem`) is the fastest format** and what the analysers below prefer. `-f sqlite3` is also accepted by `memory:report` / `memory:compare` (handy if you already have SQLite tooling), `-f json` gives `jq`-friendly output, and `-f report` prints a findings report directly.

## Analyse memory graphs

| I want to... | Use | More |
|---|---|---|
| Browse interactively in a TUI **(recommended)** | `rmem:explore snap.rmem` | [memory/rmem-explore-and-serve.md](memory/rmem-explore-and-serve.md) |
| Get a prioritised findings report | `inspector:memory:report snap.rmem` (or capture with `-f report`) | [memory/memory-report.md](memory/memory-report.md) |
| Compare two graphs (regression / leak tracking) | `inspector:memory:compare before.rmem after.rmem` | [memory/memory-report.md](memory/memory-report.md) |
| Emit a self-contained HTML viz (Pack / Treemap / Sunburst / 3D Force) | `rmem:viz snap.rmem` | [memory/rmem-explore-and-serve.md](memory/rmem-explore-and-serve.md) |
| Serve the viz over HTTP with a live focus bus (TUI ↔ browsers ↔ AI) | `rmem:live snap.rmem` (or `rmem:explore --http-bridge`) | [memory/rmem-explore-and-serve.md](memory/rmem-explore-and-serve.md) |
| Run a persistent query server (JSON-over-socket) | `rmem:serve snap.rmem` | [memory/rmem-explore-and-serve.md](memory/rmem-explore-and-serve.md) |
| Let an AI assistant explore a graph | `rmem:mcp` | [memory/rmem-explore-and-serve.md](memory/rmem-explore-and-serve.md) |
| Query via raw SQL (snapshot must be SQLite, `-f sqlite3`) | open the `.db` with `sqlite3` / `duckdb` / any SQL tool | [memory/memory-profiler-database.md](memory/memory-profiler-database.md) |

## Monitor VMs

| I want to... | Use | More |
|---|---|---|
| Trigger an action when memory / a function / a variable condition matches | `inspector:watch` | [monitoring/watch-command.md](monitoring/watch-command.md) |
| Accept on-demand memory dumps from the app over a Unix socket | `inspector:sidecar` | [monitoring/sidecar.md](monitoring/sidecar.md) |

## Inspect runtime variables

| I want to... | Use | More |
|---|---|---|
| Read a variable once (or poll it) | `inspector:peek-var` | [inspection/peek-var-command.md](inspection/peek-var-command.md) |
| Attach a variable to every sample in a trace | `inspector:trace --trace-var=…` | [inspection/trace-var-command.md](inspection/trace-var-command.md) |

## Advanced / tooling

| I want to... | Use | More |
|---|---|---|
| Just get the EG address (e.g. to feed phpspy manually) | `inspector:eg -p <pid>` | [tracing/capturing-traces.md](tracing/capturing-traces.md) |
| Install phpspy | `phpspy:install` | [tracing/phpspy-hybrid.md](tracing/phpspy-hybrid.md) |
| Clear or bypass the binary analysis cache | `cache:clear`, `--no-cache` | [internals/binary-analysis-cache.md](internals/binary-analysis-cache.md) |

## Platform notes

- Alpine / musl libc — [internals/alpine-investigation.md](internals/alpine-investigation.md)
- AArch64 (ARM64, experimental) — [internals/aarch64-support.md](internals/aarch64-support.md)

## Internals / design notes

[internals/](internals/) collects architecture notes, design docs, and
post-mortem investigations — FFI CData lifetime, trace consistency,
memory analysis performance, rmem serve design, watch command
architecture, and others. Read these when you want to understand
*why* reli is shaped the way it is, or when hacking on the
internals.
