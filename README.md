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
- **Where memory is used** — reconstruct the target's PHP heap into a queryable graph (`.rmem`). Open it interactively with `rmem:explore` (TUI), `rmem:viz` (standalone HTML — Pack / Treemap / Sunburst / 3D Force) or `rmem:live` (HTTP/SSE so TUI / browsers / AI share one focus); get a prioritised findings report with `memory:report`, or compare two snapshots with `memory:compare` to track regressions.
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

### Platform notes

- **AArch64 (ARM64)** — experimental.
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
Reconstruct the target's PHP heap into an analysable graph. `.rmem` is the fastest format and is what every analyser (`rmem:explore`, `memory:report`, `memory:compare`, `rmem:viz`, `rmem:live`, `rmem:serve`, `rmem:mcp`) reads natively.

> [!CAUTION]
> Memory snapshots contain the target script's runtime state — including any secrets / PII it was holding. Don't upload them to the public internet.

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

A taste of what reli looks like in use. For the full walkthroughs, follow each showcase's link. For step-by-step first-use, see [docs/getting-started.md](docs/getting-started.md).

### Interactive trace browsing — `rbt:explore`

Capture to `.rbt`, open the sandwich / flame / tree TUI.

```bash
$ sudo php ./reli inspector:trace -p <pid> -F rbt -o trace.rbt
$ ./reli rbt:explore trace.rbt
```

![rbt:explore — sandwich + panes view](docs/images/rbt-explore-panes.png)

Full tour (keymap, filters, `--with-opcode`, mouse, live tail): [docs/tracing/rbt-analyze-and-explore.md](docs/tracing/rbt-analyze-and-explore.md). Format spec and converters: [docs/tracing/binary-trace-format.md](docs/tracing/binary-trace-format.md). Advanced capture (opcodes / native frames / JIT): [docs/tracing/advanced-capture.md](docs/tracing/advanced-capture.md).

### Memory graph visualization — `rmem:viz` / `rmem:live`

Render the heap as a standalone HTML file — Circle Pack, Treemap, Sunburst, 3D Force — or serve it live with a shared focus bus that `rmem:explore` (TUI), browsers, and an MCP client all follow in sync.

```bash
# Standalone HTML
$ php ./reli rmem:viz snapshot.rmem
# wrote snapshot.rmem.viz.html

# Live (HTTP/SSE) with follow-from-TUI
$ php ./reli rmem:explore snapshot.rmem --http-bridge 8080
# press `f` in the TUI, then open http://127.0.0.1:8080/
```

<!-- TODO(screenshot): rmem:viz 3D Force graph of a real heap -->
![rmem:viz — 3D Force graph of a PHP heap (screenshot coming soon)](docs/images/rmem-viz-force.png)

<!-- TODO(screenshot): rmem:viz Circle Pack of a real heap -->
![rmem:viz — Circle Pack view (screenshot coming soon)](docs/images/rmem-viz-pack.png)

<!-- TODO(gif): rmem:explore ↔ rmem:live focus bus — moving the TUI cursor moves the browser highlight -->
![rmem:explore ↔ browser follow mode (GIF coming soon)](docs/images/rmem-follow.gif)

Full tour (views, palettes, focus bus, mouse, MCP): [docs/memory/rmem-explore-and-serve.md](docs/memory/rmem-explore-and-serve.md).

### Automated memory findings — `memory:report`

Capture a snapshot and get a prioritised report back — dominant classes, cycles, choke points, deduplication candidates — each with severity, hypothesis, and next steps.

```bash
$ sudo php ./reli inspector:memory -p <pid> -f binary -o snapshot.rmem
$ php ./reli inspector:memory:report snapshot.rmem
```

<!-- TODO(screenshot): memory:report terminal output (findings + tables) -->
![memory:report — findings and tables (screenshot coming soon)](docs/images/memory-report-output.png)

Compare two snapshots to track regressions or verify fixes:

```bash
$ php ./reli inspector:memory:compare before.rmem after.rmem
```

Full reference (output formats, thresholds, JSON mode): [docs/memory/memory-report.md](docs/memory/memory-report.md). Capture options (`--exclude-heap`, portable dumps, core-file analysis): [docs/memory/memory-dump.md](docs/memory/memory-dump.md), [docs/memory/coredump.md](docs/memory/coredump.md).

### Flamegraph from a `.rbt`

```bash
$ ./reli converter:flamegraph <trace.rbt >flame.svg
```

![flame](https://user-images.githubusercontent.com/6488121/153741551-3f0fc730-c748-4908-b8ac-7c3f46a5bdbc.svg)

Also available: `converter:speedscope`, `converter:pprof`, `converter:callgrind`, `converter:folded`, `converter:phpspy`, `rbt:recover`. See [docs/tracing/binary-trace-format.md](docs/tracing/binary-trace-format.md).

## Binary analysis cache

reli caches expensive binary-analysis results (ELF symbol resolution, ZTS TLS offsets, PHP version detection, …) under `~/.cache/reli/binary-analysis/`. This turns a ~8-second cold start for a ZTS target into ~5 ms on subsequent runs against the same binary.

```bash
# Clear the cache
./reli cache:clear

# Bypass the cache for a single run
./reli inspector:trace --no-cache -p <pid>
```

For what exactly is cached, how keys are computed, and the Docker-overlayfs edge case that shaped the keying scheme, see [docs/internals/binary-analysis-cache.md](docs/internals/binary-analysis-cache.md).

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
