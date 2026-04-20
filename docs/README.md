# Reli documentation

A task-oriented index. Start here if you know what you want to do but
don't know which command (or which doc) to reach for. Everything in
this file links out to a dedicated doc or a section of the top-level
[README](../README.md).

## Getting started

- Install and first run — [README § Installation](../README.md#installation)
- How it works (architecture overview) — [README § How it works](../README.md#how-it-works)
- Supported PHP versions and platforms — [README § Requirements](../README.md#requirements)

## Capture call traces (where time is spent)

Reli samples the call stack on a timer, so each trace reflects what the VM is doing over wall-clock time — including I/O, lock waits, and sleeps, not just on-CPU work. Aggregated across many samples, this shows where your code spends its time.

| I want to... | Use | More |
|---|---|---|
| Attach to a running process | `inspector:trace -p <pid>` | [README § Get call traces](../README.md#get-call-traces) |
| Capture to a compact binary file for later analysis **(recommended)** | `inspector:trace -p <pid> -F rbt -o trace.rbt` | [binary-trace-format.md](binary-trace-format.md) |
| Trace many processes at once (e.g. a php-fpm pool) | `inspector:daemon -P <regex>` | [README § Daemon mode](../README.md#daemon-mode) |
| Live `top`-style aggregation | `inspector:top -P <regex>` | [README § top-like mode](../README.md#top-like-mode) |
| Include C-level frames | add `--with-native-trace` | [README § Collect native stack traces](../README.md#collect-native-c-level-stack-traces) |
| See the executing opcode at each sample | add `--template=phpspy_with_opcode` | [README § opcodes](../README.md#show-currently-executing-opcodes-at-traces) |
| Use phpspy as the fast C backend, with reli-driven ZTS support | `phpspy:trace`, `phpspy:daemon` | [README § Hybrid phpspy mode](../README.md#hybrid-phpspy-mode) |
| Attach a PHP variable to every sample | `inspector:trace --trace-var=…` | [trace-var-command.md](trace-var-command.md) |

## Analyse call traces (where time is spent)

| I want to... | Use | More |
|---|---|---|
| Browse interactively in the terminal **(recommended)** | `rbt:explore trace.rbt` | [rbt-analyze-and-explore.md](rbt-analyze-and-explore.md) |
| One-shot text report (hot frames, callers/callees, live tail) | `rbt:analyze trace.rbt` | [rbt-analyze-and-explore.md](rbt-analyze-and-explore.md) |
| Convert to speedscope / pprof / flamegraph / callgrind / folded | `converter:<format>` | [binary-trace-format.md](binary-trace-format.md) |
| Decode `.rbt` back to phpspy text | `converter:phpspy` | [binary-trace-format.md](binary-trace-format.md) |
| Recover a corrupted or truncated `.rbt` file | `rbt:recover` | [binary-trace-format.md](binary-trace-format.md) |

## Capture memory graphs

A memory graph is reli's PHP heap reconstruction — values (objects, arrays, strings, call frames) as nodes, references as edges. Analysing a live process stops the target until the heap walk finishes; dumping the raw memory does not. **In production, dump now and analyse later.**

| I want to... | Use | More |
|---|---|---|
| Dump now (short stop), analyse offline **(recommended)** | `inspector:memory:dump` → `inspector:memory:analyze` | [memory-dump.md](memory-dump.md) |
| One-shot live capture (longer stop, one command) | `inspector:memory -p <pid> -f sqlite3 -o snap.db` | [memory-profiler.md](memory-profiler.md) |
| From a core file (crashed / post-mortem) | `inspector:coredump` | [coredump.md](coredump.md) |

Tip: pass `-f json` or `-f report` to any of the above for alternative output.

## Analyse memory graphs

| I want to... | Use | More |
|---|---|---|
| Browse interactively in a TUI **(recommended)** | `inspector:rmem:explore snap.db` | [rmem-explore-and-serve.md](rmem-explore-and-serve.md) |
| Get a prioritised findings report | `inspector:memory:report snap.db` (or capture with `-f report`) | [memory-report.md](memory-report.md) |
| Compare two graphs (regression / leak tracking) | `inspector:memory:compare before.db after.db` | [memory-report.md](memory-report.md) |
| Query a graph's SQL directly | `inspector:rmem:serve` or open the SQLite file | [rmem-explore-and-serve.md](rmem-explore-and-serve.md), [memory-profiler-database.md](memory-profiler-database.md) |
| Let an AI assistant explore a graph | `inspector:rmem:mcp` | [rmem-explore-and-serve.md](rmem-explore-and-serve.md) |

## Monitor VMs

| I want to... | Use | More |
|---|---|---|
| Trigger an action when memory / a function / a variable condition matches | `inspector:watch` | [watch-command.md](watch-command.md) |
| Accept on-demand memory dumps from the app over a Unix socket | `inspector:sidecar` | [sidecar.md](sidecar.md) |

## Inspect runtime variables

| I want to... | Use | More |
|---|---|---|
| Read a variable once (or poll it) | `inspector:peek-var` | [peek-var-command.md](peek-var-command.md) |
| Attach a variable to every sample in a trace | `inspector:trace --trace-var=…` | [trace-var-command.md](trace-var-command.md) |

## Advanced / tooling

| I want to... | Use | More |
|---|---|---|
| Just get the EG address (e.g. to feed phpspy manually) | `inspector:eg -p <pid>` | [README § Get the address of EG](../README.md#get-the-address-of-eg) |
| Install phpspy | `phpspy:install` | [README § Hybrid phpspy mode](../README.md#hybrid-phpspy-mode) |
| Clear or bypass the binary analysis cache | `cache:clear`, `--no-cache` | [README § Binary analysis cache](../README.md#binary-analysis-cache) |

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
