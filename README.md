# Reli
![Minimum PHP version: 8.5.0](https://img.shields.io/badge/php-8.5.0%2B-blue.svg)
[![Packagist](https://img.shields.io/packagist/v/reliforp/reli-prof.svg)](https://packagist.org/packages/reliforp/reli-prof)
[![Github Actions](https://github.com/reliforp/reli-prof/workflows/build/badge.svg)](https://github.com/reliforp/reli-prof/actions)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/reliforp/reli-prof/badges/quality-score.png?b=0.12.x)](https://scrutinizer-ci.com/g/reliforp/reli-prof/?branch=0.12.x)
[![Coverage Status](https://coveralls.io/repos/github/reliforp/reli-prof/badge.svg?branch=0.12.x)](https://coveralls.io/github/reliforp/reli-prof?branch=0.12.x)
![Psalm coverage](https://shepherd.dev/github/reliforp/reli-prof/coverage.svg?)

Reli is a sampling profiler (or a VM state inspector) written in PHP. It can read information about running PHP script from outside of the process. It's a stand alone CLI tool, so target programs don't need any modifications. The former name of this tool was sj-i/php-profiler. 

New here? [docs/getting-started.md](docs/getting-started.md) walks from install to your first trace. Looking for a specific task? The [documentation index](docs/README.md) maps "I want to X" to the right command and doc.

## What can I use this for?

- **Where time is spent** — sampling profiler for PHP call stacks, with optional C-level frames and per-opcode detail. Capture to the compact `.rbt` binary format, browse in the `rbt:explore` TUI, or convert to speedscope / pprof / flamegraph / callgrind / folded.
- **Where memory is used** — reconstruct the target's PHP heap into a queryable graph (`.rmem`). Open it interactively with `rmem:explore`, get a prioritised findings report with `memory:report`, or compare two snapshots with `memory:compare` to track regressions.
- **What values flow through** — read PHP variable values from a running process without modifying it (`inspector:peek-var`), or attach variable values to every trace sample (`inspector:trace --trace-var`) so you can join runtime state to hot stacks.
- **When something goes wrong** — trigger captures on runtime conditions. `inspector:watch` takes a memory dump or trace when memory thresholds, function calls, or variable conditions are met. `inspector:sidecar` accepts on-demand dump requests from the app over a Unix socket — ideal for `memory_limit` crash analysis.

For the full catalogue of tasks and commands, see the [documentation index](docs/README.md).

## Requirements
### Supported PHP versions
#### Execution
- PHP 8.5+ (NTS / ZTS)
- 64bit Linux x86_64
- 64bit Linux AArch64 (experimental)
- FFI extension must be enabled.
- PCNTL extension must be enabled.

> [!TIP]
> The provided Docker image is often the easiest way to get started:
> it ships a PHP 8.5 build with FFI/PCNTL already enabled, `--cap-add=SYS_PTRACE`
> grants the capability reli needs without elevating the host shell, and
> `--pid=host` lets you target PHP processes running in other containers or
> on the host from a single command. Bare-metal installs on older PHP versions
> are not supported.

#### Target
- PHP 7.0+ (NTS / ZTS)
- 64bit Linux x86_64
- 64bit Linux AArch64 (experimental)

On targeting ZTS, reli finds EG from the TLS. Stripped binaries are supported (TLS segments are scanned via brute force). On glibc 2.34+, where libpthread is merged into libc, reli automatically falls back to libc.so, so no extra options are needed in most cases.

### Platform notes

- **AArch64 (ARM64)** — experimental. Enables profiling on ARM-based servers (AWS Graviton) and Apple Silicon Macs running Linux VMs or Docker containers. Both NTS and ZTS targets supported. See [docs/internals/aarch64-support.md](docs/internals/aarch64-support.md).
- **Alpine / musl libc** — sampling profiler and the memory pipeline (dump / analyse / report) all work on both NTS and ZTS. Native C-level stack traces are not supported on musl due to its minimal `.eh_frame` (~4 FDE entries vs glibc's ~3,700). See [docs/internals/alpine-investigation.md](docs/internals/alpine-investigation.md).

## Installation
### From Docker
```bash
docker pull reliforp/reli-prof
docker run -it --security-opt="apparmor=unconfined" --cap-add=SYS_PTRACE --pid=host reliforp/reli-prof
```
`--cap-add=SYS_PTRACE` grants reli the ptrace capability, and `--pid=host` makes PHP processes running on the host (or in other containers) visible as targets — no extra setup on the host side.

### From Composer
```bash
composer create-project reliforp/reli-prof
cd reli-prof
./reli
```

### From Git
```bash
git clone git@github.com:reliforp/reli-prof.git
cd reli-prof
composer install
./reli
```

## Usage

For a task-oriented map of every command, see [docs/README.md](docs/README.md).
Every subsection below shows a canonical invocation plus the most commonly used flags.
Run `./reli <command> --help` for the complete option list — the CLI help is the source of truth.

### Get call traces
Sample a running process, or spawn one and sample it:

```bash
# Attach to a running process
sudo php ./reli inspector:trace -p <pid>

# Spawn and trace a new process
./reli inspector:trace -- php script.php

# Capture to compact binary format (recommended for later analysis)
sudo php ./reli inspector:trace -p <pid> -F rbt -o trace.rbt
```

Key options: `-d/--depth`, `-s/--sleep-ns`, `-S/--stop-process`, `-t/--template=phpspy|phpspy_with_opcode|json_lines`, `-F/--output-format=rbt|rbt-bundled`, `-o/--output`, `--with-native-trace`, `--trace-var`.

### Daemon mode
Concurrently trace every process whose command line matches a regex (e.g. an FPM pool):

```bash
sudo php ./reli inspector:daemon -P "^php-fpm" -F rbt -o /path/to/output_dir/
```

Key options: `-P/--target-regex` (required), `-T/--threads`, `-d/--depth`, `-s/--sleep-ns`, `-F/--output-format`, `-o/--output`, `--with-native-trace`, `--trace-var`.

### top-like mode
Real-time aggregated view across matching processes, in the spirit of UNIX `top`:

```bash
sudo php ./reli inspector:top -P "^php-fpm"
```

Key options: `-P/--target-regex` (required), `-T/--threads`, `-d/--depth`, `-s/--sleep-ns`, `--with-native-trace`.

### Get the address of EG
Useful for feeding phpspy manually, or for advanced integrations:

```bash
$ sudo php ./reli inspector:eg -p <pid>
0x555ae7825d80
```

### Hybrid phpspy mode
Reli can use [phpspy](https://github.com/adsr/phpspy) as the fast C-based tracing backend while letting reli resolve the EG address (including for ZTS targets, which phpspy alone cannot handle).

```bash
# Install phpspy (builds from source, installs to ~/.reli/bin/phpspy by default)
./reli phpspy:install

# Single-process tracing
sudo php ./reli phpspy:trace -p <pid>

# Multi-process daemon
sudo php ./reli phpspy:daemon -P "^php-fpm"
```

Key `phpspy:trace` / `phpspy:daemon` options: `-s/--sleep-ns`, `-b/--buffer-size`, `-H/--rate-hz`, `--phpspy-args` (passthrough to phpspy), `--phpspy-path`, `-o/--output`.

## Capture a memory graph
Reconstruct the target's PHP heap into an analysable graph. `.rmem` is the fastest format and is what every analyser (`rmem:explore`, `memory:report`, `memory:compare`, `rmem:serve`, `rmem:mcp`) reads natively.

```bash
# Recommended for ad-hoc / local use: live one-shot capture
sudo php ./reli inspector:memory -p <pid> -f binary -o snapshot.rmem

# Recommended in production: short-stop dump + offline graph build
sudo php ./reli inspector:memory:dump -p <pid> -o dump.relimem
php ./reli inspector:memory:analyze dump.relimem -f binary -o snapshot.rmem
```

Key options: `-f/--output-format=binary|sqlite3|json|report|report-json|mysql|postgresql`, `-o/--output`, `--stop-process/--no-stop-process`, `--pretty-print`, `--db-host`/`--db-port`/`--db-name`/`--db-user`/`--db-password`, `--memory-usage-error-file`/`--memory-usage-error-line`.

See [docs/memory/memory-dump.md](docs/memory/memory-dump.md) for the dump-then-analyse flow, [docs/memory/rmem-explore-and-serve.md](docs/memory/rmem-explore-and-serve.md) for the interactive TUI, [docs/memory/memory-report.md](docs/memory/memory-report.md) for automated reports and comparisons, [docs/memory/coredump.md](docs/memory/coredump.md) for post-mortem from a core file, and [docs/memory/memory-profiler.md](docs/memory/memory-profiler.md) for the JSON + `jq` deep-dive.

## Watch: Condition-Based Process Monitoring

`inspector:watch` monitors PHP processes and triggers profiling actions when configurable conditions are met. It only takes action when triggers fire, making it suitable for low-overhead production monitoring.

```bash
# Dump memory when usage exceeds 256M
./reli inspector:watch -p <pid> --memory-usage=256M

# Monitor multiple php-fpm processes
./reli inspector:watch --target-regex="php-fpm" --memory-usage=512M --action=log

# Watch for a specific function in the call stack
./reli inspector:watch -p <pid> --watch-function="App\Service::process" --action=trace-once

# Monitor a PHP variable
./reli inspector:watch -p <pid> --watch-var='global::$cache:count_gt:10000'

# Monitor memory usage via variable interface
./reli inspector:watch -p <pid> --watch-var='memory::memory_get_usage:gt:104857600'

# Grab 3 memory dumps and stop
./reli inspector:watch -p <pid> --memory-usage=128M --oneshot=3
```

Available triggers: `--memory-usage`, `--memory-growth-rate`, `--memory-peak-watch`, `--watch-function`, `--trace-depth-limit`, `--watch-var`.

Available actions: `memory-dump` (default), `trace`, `log`, `exec`.

Rate limiting: `--cooldown` (with exponential backoff), `--max-triggers-per-hour`, `--max-dump-size`.

See [docs/monitoring/watch-command.md](docs/monitoring/watch-command.md) for full documentation.

## Peek Variable: One-Shot Variable Inspection

`inspector:peek-var` reads PHP variable values from a running process — no triggers or actions, just the current value.

```bash
# Read global variables
./reli inspector:peek-var -p <pid> --var='global::$counter' --var='global::$cache'

# Repeat every 500ms
./reli inspector:peek-var -p <pid> --var='global::$queue' --repeat=500

# JSON output for scripting
./reli inspector:peek-var -p <pid> --var='global::$counter' --format=json
```

Supported scopes: `global::$var`, `local::func()$var`, `static::Class::$prop`, `func_static::func()$var`, `memory::memory_get_usage`.

See [docs/inspection/peek-var-command.md](docs/inspection/peek-var-command.md) for full documentation.

## Trace Var Peek: Per-Sample Variable Inspection in Traces

`inspector:trace --trace-var` attaches PHP variable values to every trace sample, so you can correlate runtime state (request URI, user id, SQL query, ...) with the hot stacks that produced it — no separate tool, no log join.

```bash
# Tag every sample with the current request URI
./reli inspector:trace -p <pid> --trace-var='global::$request_uri'

# Track memory usage per sample
./reli inspector:trace -p <pid> --trace-var='memory::memory_get_usage'

# Multiple variables — each becomes its own annotation line
./reli inspector:trace -p <pid> \
  --trace-var='global::$request_uri' \
  --trace-var='local::App\Controller::handle()$user_id'

# Skip reads when a specific function isn't on the stack (cheap gate)
./reli inspector:trace -p <pid> \
  --trace-var='local::App\PDOProxy::execute()$query' \
  --trace-var-on-function='App\PDOProxy::execute'

# Binary (rbt) output — annotations ride on SAMPLE_ANNOTATION events
./reli inspector:trace -p <pid> -F rbt -o trace.rbt \
  --trace-var='global::$counter'
```

Sample phpspy output:

```
0 App\Controller::handle /app/src/Controller.php:17
1 <main> /app/public/index.php:9
# global::$request_uri = (string) "/users/1234"
# local::App\Controller::handle()$user_id = (int) 1234

```

The same expression grammar as `inspector:peek-var --var` is supported, including nested access (`[key]`, `->prop`). Works with `inspector:daemon` in all three output modes (per-worker `rbt`, `rbt-bundled`, and template text), and with `--with-native-trace` for merged native+PHP traces.

See [docs/inspection/trace-var-command.md](docs/inspection/trace-var-command.md) for full documentation — including rate-limit options (`--trace-var-every`, `--trace-var-on-function`), RLE implications in rbt, and daemon mode behaviour.

## Examples
### Trace a script
```bash
$ ./reli i:trace -- php -r "fgets(STDIN);"
0 fgets <internal>:-1
1 <main> <internal>:-1

0 fgets <internal>:-1
1 <main> <internal>:-1

0 fgets <internal>:-1
1 <main> <internal>:-1

<press q to exit>
...
```

### Attach to a running process
```bash
$ sudo php ./reli i:trace -p 2182685
0 time_nanosleep <internal>:-1
1 Reli\Lib\Loop\LoopMiddleware\NanoSleepMiddleware::invoke /home/sji/work/reli/src/Lib/Loop/LoopMiddleware/NanoSleepMiddleware.php:33
2 Reli\Lib\Loop\LoopMiddleware\KeyboardCancelMiddleware::invoke /home/sji/work/reli/src/Lib/Loop/LoopMiddleware/KeyboardCancelMiddleware.php:39
3 Reli\Lib\Loop\LoopMiddleware\RetryOnExceptionMiddleware::invoke /home/sji/work/reli/src/Lib/Loop/LoopMiddleware/RetryOnExceptionMiddleware.php:37
4 Reli\Lib\Loop\Loop::invoke /home/sji/work/reli/src/Lib/Loop/Loop.php:26
5 Reli\Command\Inspector\GetTraceCommand::execute /home/sji/work/reli/src/Command/Inspector/GetTraceCommand.php:133
6 Symfony\Component\Console\Command\Command::run /home/sji/work/reli/vendor/symfony/console/Command/Command.php:291
7 Symfony\Component\Console\Application::doRunCommand /home/sji/work/reli/vendor/symfony/console/Application.php:979
8 Symfony\Component\Console\Application::doRun /home/sji/work/reli/vendor/symfony/console/Application.php:299
9 Symfony\Component\Console\Application::run /home/sji/work/reli/vendor/symfony/console/Application.php:171
10 <main> /home/sji/work/reli/reli:45

0 time_nanosleep <internal>:-1
1 Reli\Lib\Loop\LoopMiddleware\NanoSleepMiddleware::invoke /home/sji/work/reli/src/Lib/Loop/LoopMiddleware/NanoSleepMiddleware.php:33
2 Reli\Lib\Loop\LoopMiddleware\KeyboardCancelMiddleware::invoke /home/sji/work/reli/src/Lib/Loop/LoopMiddleware/KeyboardCancelMiddleware.php:39
3 Reli\Lib\Loop\LoopMiddleware\RetryOnExceptionMiddleware::invoke /home/sji/work/reli/src/Lib/Loop/LoopMiddleware/RetryOnExceptionMiddleware.php:37
4 Reli\Lib\Loop\Loop::invoke /home/sji/work/reli/src/Lib/Loop/Loop.php:26
5 Reli\Command\Inspector\GetTraceCommand::execute /home/sji/work/reli/src/Command/Inspector/GetTraceCommand.php:133
6 Symfony\Component\Console\Command\Command::run /home/sji/work/reli/vendor/symfony/console/Command/Command.php:291
7 Symfony\Component\Console\Application::doRunCommand /home/sji/work/reli/vendor/symfony/console/Application.php:979
8 Symfony\Component\Console\Application::doRun /home/sji/work/reli/vendor/symfony/console/Application.php:299
9 Symfony\Component\Console\Application::run /home/sji/work/reli/vendor/symfony/console/Application.php:171
10 <main> /home/sji/work/reli/reli:45

<press q to exit>
...
```
The executing process must have the CAP_SYS_PTRACE capability. (Usually run as root is enough.)

### Capture to a binary trace (`.rbt`)
For anything beyond a quick eyeball, capture straight to reli's compact binary format and analyse it offline with the `rbt:*` tools. `.rbt` compresses ~370× vs phpspy text (measured: 70 MB phpspy → 180 KB `.rbt`) via string interning, stack dedup, and run-length encoding.

```bash
# Capture a single process
$ sudo php ./reli i:trace -p <pid> -F rbt -o trace.rbt

# Browse it interactively in the terminal
$ ./reli rbt:explore trace.rbt

# Or get a one-shot text report (hot frames, callers / callees, live tail)
$ ./reli rbt:analyze trace.rbt
```

See [docs/tracing/rbt-analyze-and-explore.md](docs/tracing/rbt-analyze-and-explore.md) for the TUI / analyser tour and [docs/tracing/binary-trace-format.md](docs/tracing/binary-trace-format.md) for the format specification.

### Daemon mode
```bash
# Live view
$ sudo php ./reli i:daemon -P "^/usr/sbin/httpd"

# Per-worker .rbt files (zero IPC overhead)
$ sudo php ./reli i:daemon -P "^php-fpm" -F rbt -o ./traces/
```
The executing process must have the CAP_SYS_PTRACE capability. (Usually run as root is enough.)

### top-like mode
UNIX-`top`-style live aggregated view across matching processes:

```bash
$ sudo php ./reli i:top -P "^php-fpm"
```

### Get the address of EG
```bash
$ sudo php ./reli i:eg -p 2183131
0x555ae7825d80
```
The executing process must have the CAP_SYS_PTRACE capability. (Usually run as root is enough.)

### Hybrid phpspy mode
Install phpspy via reli and use it as the tracing backend:
```bash
# Install phpspy (builds from source, installs to ~/.reli/bin/phpspy)
$ ./reli phpspy:install

# Trace a single process (reli resolves EG, phpspy does the fast tracing)
$ sudo php ./reli phpspy:trace -p <pid>
resolving EG address...
EG address resolved: 0x564102620bc0
SG address resolved: 0x564102620600
starting phpspy for pid 12345...
0 usleep <internal>:-1
1 <main> Command line code:1
```

This is especially useful for ZTS PHP where phpspy alone cannot resolve the EG address:
```bash
# Trace a ZTS PHP process — reli handles ZTS EG resolution, phpspy traces
$ sudo php ./reli phpspy:trace -p <zts-pid>
```

Daemon mode discovers processes automatically and launches phpspy per process:
```bash
$ sudo php ./reli phpspy:daemon -P "^php-fpm"
```

You can pass extra phpspy flags via `--phpspy-args`:
```bash
$ sudo php ./reli phpspy:trace -p <pid> --phpspy-args="-c -1"
```

### Show currently executing opcodes at traces
If a user wants to profile a really CPU-bound application, then he or she wouldn't only want to know what line is slow, but what opcode is.

- **When capturing to `.rbt`**, the opcode is always recorded. Reveal it during analysis with `./reli rbt:analyze --with-opcode trace.rbt`, or press `c` inside `rbt:explore` to toggle the opcode column.
- **For phpspy text output**, add `--template=phpspy_with_opcode` to `inspector:trace` or `inspector:daemon`:

```bash
$ sudo php ./reli i:trace --template=phpspy_with_opcode -p <pid of the target process or thread>
```

The output would be like the following.

```
0 <VM>::ZEND_ASSIGN <VM>:-1
1 Mandelbrot::iterate /home/sji/work/test/mandelbrot.php:33:ZEND_ASSIGN
2 Mandelbrot::__construct /home/sji/work/test/mandelbrot.php:12:ZEND_DO_FCALL
3 <main> /home/sji/work/test/mandelbrot.php:45:ZEND_DO_FCALL

0 <VM>::ZEND_ASSIGN <VM>:-1
1 Mandelbrot::iterate /home/sji/work/test/mandelbrot.php:30:ZEND_ASSIGN
2 Mandelbrot::__construct /home/sji/work/test/mandelbrot.php:12:ZEND_DO_FCALL
3 <main> /home/sji/work/test/mandelbrot.php:45:ZEND_DO_FCALL
```

The currently executing opcode becomes the first frame of the callstack.
So visualizations of the trace like flamegraph can show the usage of opcodes.

For informational purposes, executing opcodes are also added to each end of the call frames. Except for the first frame, opcodes for function calls such as ZEND_DO_FCALL should appear there.

If JIT is enabled at the target process, this information may be slightly inaccurate. To see JIT-compiled function names in traces, use `--with-native-trace` and set `opcache.jit_debug=0x10` on the target process.

### Use in a docker container and target a process on host
```bash
$ docker pull reliforp/reli-prof
$ docker run -it --security-opt="apparmor=unconfined" --cap-add=SYS_PTRACE --pid=host reliforp/reli-prof i:trace -p <pid of the target process or thread>
```

### Collect native (C-level) stack traces
```bash
$ sudo php ./reli i:trace --with-native-trace -p <pid>
0 libc.so.6::clock_nanosleep+0x5a [native]:0
1 libc.so.6::__nanosleep+0x17 [native]:0
2 libc.so.6::usleep+0x4c [native]:0
3 php8.4::zif_usleep+0x42 [native]:0
4 usleep <internal>:-1
5 <main> /app/test.php:15
6 php8.4::execute_ex+0x4dfa [native]:0
7 php8.4::zend_execute+0x141 [native]:0
8 php8.4::zend_execute_script+0x56 [native]:0
9 php8.4::php_execute_script_ex+0x278 [native]:0
10 libc.so.6::__libc_start_main+0x8b [native]:0
11 php8.4::_start+0x25 [native]:0
```

Native frames are labeled with `[native]:0` and show `module::symbol+offset`. PHP frames are placed on the callee side of `execute_ex`, reflecting that all PHP execution happens inside the VM's opcode dispatcher.

`--with-native-trace` works with every output format. Capture to `.rbt` and drop into `rbt:explore` for interactive analysis of merged native+PHP traces, or convert to a flamegraph:
```bash
$ sudo php ./reli i:trace --with-native-trace -p <pid> -F rbt -o trace.rbt
$ ./reli rbt:explore trace.rbt
# ...or:
$ ./reli converter:flamegraph <trace.rbt >flame_native.svg
```

Symbol resolution specifics:

- **Stripped binaries** are supported — reli uses exported symbols from `.dynsym`.
- **Separate debug symbol packages** (`-dbgsym` / `-debuginfo`) are loaded when available for full symbol coverage.
- **JIT-compiled function names** resolve via `/tmp/perf-<pid>.map` when the target process has `opcache.jit_debug=0x10` (see the JIT-compiled code subsection below).

### Collect native traces during interpreter initialization / shutdown
```bash
$ sudo php ./reli i:trace --native-trace-anytime -p <pid>
```
When `--native-trace-anytime` is used, native C-level traces are collected even when no PHP code is executing (e.g. during module initialization or shutdown). This is useful for investigating interpreter startup performance or extension loading behavior.

### JIT-compiled code in native traces
When the target PHP process has JIT enabled with `opcache.jit_debug=0x10`, JIT-compiled function names are resolved via `/tmp/perf-<pid>.map`:
```bash
$ php -d opcache.jit_debug=0x10 script.php &
$ sudo php ./reli i:trace --with-native-trace -p $!
0 [jit]::TRACE-2$fibonacci$4+0x141 [native]:0
1 php8.4::zend_execute+0x141 [native]:0
2 <main> /app/test.php:14
```

For DWARF-based unwinding through JIT frames, use `opcache.jit_debug=0x100` (GDB JIT interface).

### Convert traces to other formats
`converter:*` reads both `.rbt` and phpspy text (auto-detected) and writes flamegraph SVG, speedscope, pprof, callgrind, folded stacks, or `.rbt`:

```bash
# From .rbt (preferred — smaller, lossless, no re-parse cost)
$ ./reli converter:flamegraph <trace.rbt >flame.svg
$ ./reli converter:speedscope <trace.rbt >profile.speedscope.json
$ ./reli converter:pprof <trace.rbt >profile.pb.gz
$ ./reli converter:callgrind <trace.rbt >callgrind.out && kcachegrind callgrind.out
$ ./reli converter:folded <trace.rbt | ./tools/flamegraph/flamegraph.pl >flame.svg
$ ./reli converter:phpspy <trace.rbt     # decode to phpspy text

# Same commands work on phpspy text input too
$ ./reli converter:speedscope <traces >profile.speedscope.json

# Recover a corrupted / truncated .rbt
$ ./reli rbt:recover <corrupted.rbt >recovered.rbt
```

![flame](https://user-images.githubusercontent.com/6488121/153741551-3f0fc730-c748-4908-b8ac-7c3f46a5bdbc.svg)

See [docs/tracing/binary-trace-format.md](docs/tracing/binary-trace-format.md) for the `.rbt` specification and [#101](https://github.com/reliforp/reli-prof/pull/101) for the original speedscope integration.

### Dump and analyse memory

> [!CAUTION]
> **Don't upload the output of this command to the internet — it can contain sensitive information of the target script!**

The recommended flow is **dump now, analyse later**: `inspector:memory:dump` only stops the target long enough to copy its memory pages, and the heap walk runs offline afterwards (possibly on a different machine).

```bash
# 1. Dump the target's memory to a portable file (short stop on the target)
$ sudo php ./reli inspector:memory:dump -p <pid> -o snapshot.relimem

# 2. Build the analysable memory graph offline — .rmem is the fastest
#    format and what every analyser below reads natively
$ php ./reli inspector:memory:analyze snapshot.relimem -f binary -o snapshot.rmem

# 3a. Browse it interactively
$ php ./reli inspector:rmem:explore snapshot.rmem

# 3b. Or get a prioritised findings report
$ php ./reli inspector:memory:report snapshot.rmem
```

For ad-hoc / local use where the longer stop doesn't matter, the one-shot `inspector:memory` command captures and analyses in a single call:

```bash
$ sudo php ./reli inspector:memory -p <pid> -f binary -o snapshot.rmem
$ php ./reli inspector:rmem:explore snapshot.rmem
```

`-f sqlite3` and `-f json` are also accepted — see the format tip in [docs/README.md § Capture memory graphs](docs/README.md#capture-memory-graphs-where-memory-is-used).

Only NTS targets are supported for now.

See [docs/memory/memory-dump.md](docs/memory/memory-dump.md) for capture options (`--exclude-heap`, `--include-binary`, …), [docs/memory/rmem-explore-and-serve.md](docs/memory/rmem-explore-and-serve.md) for the TUI, [docs/memory/memory-report.md](docs/memory/memory-report.md) for reports and comparisons, [docs/memory/coredump.md](docs/memory/coredump.md) for post-mortem analysis from a core file, and [docs/memory/memory-profiler.md](docs/memory/memory-profiler.md) for the JSON + `jq` deep-dive (the original workflow — still supported, just no longer the first recommendation).

### Automatic analysis report

Instead of manually querying with `jq`, generate an automatic analysis report. Save as `.rmem` first, then run the report (also works with `-f sqlite3 -o snapshot.db`):

```bash
$ sudo php ./reli i:m -p <pid> -f binary -o snapshot.rmem
$ php ./reli inspector:memory:report snapshot.rmem
```

Or generate the report directly:

```bash
$ sudo php ./reli i:m -p <pid> -f report
```

The report identifies dominant classes, circular references, choke points, deduplication candidates, and more — with severity, hypothesis, and next steps for each finding. See [docs/memory/memory-report.md](docs/memory/memory-report.md) for details.

### Comparing two snapshots

Compare memory snapshots to find regressions, verify fixes, or track leaks over time:

```bash
$ sudo php ./reli i:m -p <pid> -f binary -o before.rmem
# ... deploy code change, trigger workload, etc.
$ sudo php ./reli i:m -p <pid> -f binary -o after.rmem
$ php ./reli inspector:memory:compare before.rmem after.rmem

# SQLite snapshots are also supported (and let you compare run IDs within one DB)
$ php ./reli inspector:memory:compare snapshot.db --run-id-baseline 1 --run-id-target 2
```

The comparison report shows summary deltas, type breakdown deltas, per-class memory changes (added/removed/changed), and findings diff (new/resolved/changed issues). Use `--threshold 5` to filter changes smaller than 5%. See [docs/memory/memory-report.md](docs/memory/memory-report.md) for details.

## Binary analysis cache
Reli caches the results of expensive binary analysis operations (ELF symbol resolution, TLS brute force offsets, PHP version detection, etc.) to disk. This dramatically speeds up repeated profiling of the same PHP binary -- for example, ZTS target initialization drops from ~8 seconds to ~5 milliseconds on warm cache.

Cache files are stored under `~/.cache/reli/binary-analysis/` (following the XDG Base Directory specification), keyed by binary fingerprint (device ID + inode + ELF header content). In container environments, Docker's overlayfs can assign the same device ID and inode to different binaries across different images (e.g. `php:8.3` and `php:8.3-zts`), so the ELF header content is included to ensure different binaries always produce different cache keys.

### Clear the cache
```bash
./reli cache:clear
```

### Disable the cache
All inspector commands accept `--no-cache` to bypass the cache for a single run:
```bash
./reli inspector:trace --no-cache -p <pid>
./reli inspector:daemon --no-cache -P "^php-fpm"
```

## Troubleshooting
### I get an error message "php module not found" and can't get a trace!
If your PHP binary uses a non-standard binary name that does not end with `/php`, use the `--php-regex` option to specify the name of the executable (or shared object) that contains the PHP interpreter.

### I don't think the trace is accurate.
The `-S` option will give you better results. Using this option stops the execution of the target process for a moment at every sampling, but the trace obtained will be more accurate. If you don't stop the VMs from running when profiling CPU-heavy programs such as benchmarking programs, you may misjudge the bottleneck, because you will miss more VM states that transition very quickly and are not detected well.

### I can't get traces on Amazon Linux 2.
First, try `cat /proc/<pid>/maps` to check the memory map of the target PHP process. If the first module does not indicate the location of the PHP binary and looks like an anonymous region, try to specify `--php-regex="^$"` as an option.

## How it works

Under the hood, reli:

- Parses the ELF binary of the PHP interpreter.
- Reads the target's memory map from `/proc/<pid>/maps`.
- Reads memory of the outer process through `ptrace(2)` and `process_vm_readv(2)` via FFI.
- Analyses the internal data structures of the PHP VM (aka Zend Engine).

If you have a bit of extra CPU resource to spare on the profiling host, the overhead of this software is negligible.

## Differences to phpspy, when to use reli

Reli started out heavily inspired by [adsr/phpspy](https://github.com/adsr/phpspy); several things have since diverged.

The main structural difference is that reli is written in almost pure PHP while phpspy is written in C. If you want to customise *what* and *how* information is captured, doing it in PHP is easier — at some performance cost. (Though we aim to keep that cost modest.)

Reli can also find VM state from ZTS interpreters: daemon-mode traces of threads started via [ext-parallel](https://github.com/krakjoe/parallel) are captured automatically, which phpspy alone cannot do. `inspector:eg` exposes just the EG address so that you can feed it to phpspy manually for ZTS targets, and the [hybrid phpspy mode](#hybrid-phpspy-mode) (`phpspy:trace` / `phpspy:daemon`) combines reli's ZTS-aware EG resolution with phpspy's fast C-based tracing.

Other capabilities reli currently has that phpspy doesn't:

- More accurate line numbers.
- Output format customisation via PHP templates.
- Running-opcode output for each sample.
- Automatic PHP-version detection from stripped binaries.
- Compact binary trace format (`.rbt`) plus speedscope / pprof / folded / callgrind / flamegraph converters (see [docs/tracing/binary-trace-format.md](docs/tracing/binary-trace-format.md)).
- Deep memory-graph analysis of the target process.
- Merged native (C-level) stack traces via DWARF `.eh_frame` unwinding.
- JIT-compiled function-name resolution via perf map / GDB JIT interface.

Nothing above is technically unreachable from phpspy — these may land there one day.

On the other hand, phpspy still wins on raw sampling throughput and overhead. Much of what phpspy uniquely does will be covered by reli eventually.

## Goals
We would like to achieve the following 5 goals through this project.

- To be able to closely observe what is happening inside a running PHP script.
- To be a framework for PHP programmers to create a freely customizable PHP profiler.
- To be experimentation for the use of PHP outside of the web, where recent improvements of PHP like JIT and FFI have opened the door.
- Another entry point for PHP programmers to learn about PHP's internal implementation.
- To create a program that is fun to write for me.

## LICENSE
- MIT (mostly)
- tools/flamegraph/flamegraph.pl is copied from https://github.com/brendangregg/FlameGraph and licenced under the CDDL 1.0. See tools/flamegraph/docs/cddl1.txt and the header of the script.
- Some C headers defining internal structures are extracted from php-src. They are licensed under the Zend Engine License or the PHP License. See src/Lib/PhpInternals/Headers . So here are the words required by the Zend Engine License and the PHP License.
```
This product includes the Zend Engine, freely available at
     http://www.zend.com
```

```
This product includes PHP software, freely available from
     <http://www.php.net/software/>
```

## What does the name "Reli" mean?
Given its functionality, you might naturally think that the name stands for "Reverse Elephpantineer's Lovable Infrastructure". But unfortunately, it's not true.

"Reli" means nothing, though you are free to think of this tool as something reliable, religious, relishable, or whatever other reli-s you like.

Initially, the name of this tool was just "php-profiler".
Due to a licensing problem ([#175](https://github.com/reliforp/reli-prof/issues/175)), this simple good name had to be changed.

So we applied a randomly chosen string manipulation function to the original name. `strrev('php-profiler')` results to `'reliforp-php'`, and it can be read as "reli for p(php)".

Thus, the name of this tool is "Reli for PH*" now. And you can also call it just "Reli".

## See also
- [adsr/phpspy](https://github.com/adsr/phpspy)
    - Reli is heavily inspired by phpspy.
