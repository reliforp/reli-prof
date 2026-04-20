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

## Produce memory snapshots

A snapshot is the analysable form of a PHP heap — SQLite by default, optionally JSON or an inline findings report. Production has two stages: **capture** raw memory, then **build** the heap graph from it. `inspector:memory:dump` is pure capture — a quick raw-memory copy that writes a portable `.relimem` file. `inspector:memory`, `inspector:memory:analyze`, and `inspector:coredump` all do the build; they run the same heap walk against three heap sources (a live process, a `.relimem` dump, or an ELF core file).

Because the build stops the target for as long as the heap walk takes, **dump now and build later is the kindest choice in production** — `inspector:memory:dump` finishes in a fraction of the time a full analysis would. `inspector:memory` fuses both stages into one command at the cost of a longer stop; use it for ad-hoc local inspection or small heaps.

| I want to... | Use | More |
|---|---|---|
| Capture now (short stop), build the snapshot later **(recommended)** | `inspector:memory:dump` → `inspector:memory:analyze` | [memory-dump.md](memory-dump.md) |
| Capture + build in one step (longer stop, one command) | `inspector:memory -p <pid> -f sqlite3 -o snap.db` | [memory-profiler.md](memory-profiler.md) |
| Build from a core file (crashed / post-mortem) | `inspector:coredump` | [coredump.md](coredump.md) |

Tip: pass `-f json` to any build command for `jq`-friendly output, or `-f report` to go straight to a prioritised findings report.

## Analyse memory snapshots

| I want to... | Use | More |
|---|---|---|
| Browse interactively in a TUI **(recommended)** | `inspector:rmem:explore snap.db` | [rmem-explore-and-serve.md](rmem-explore-and-serve.md) |
| Get a prioritised findings report | `inspector:memory:report snap.db` (or capture with `-f report`) | [memory-report.md](memory-report.md) |
| Compare two snapshots (regression / leak tracking) | `inspector:memory:compare before.db after.db` | [memory-report.md](memory-report.md) |
| Query a snapshot's SQL directly | `inspector:rmem:serve` or open the SQLite file | [rmem-explore-and-serve.md](rmem-explore-and-serve.md), [memory-profiler-database.md](memory-profiler-database.md) |
| Let an AI assistant explore a snapshot | `inspector:rmem:mcp` | [rmem-explore-and-serve.md](rmem-explore-and-serve.md) |

## Monitor production

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
