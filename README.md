# Reli
![Minimum PHP version: 8.1.0](https://img.shields.io/badge/php-8.1.0%2B-blue.svg)
[![Packagist](https://img.shields.io/packagist/v/reliforp/reli-prof.svg)](https://packagist.org/packages/reliforp/reli-prof)
[![Github Actions](https://github.com/reliforp/reli-prof/workflows/build/badge.svg)](https://github.com/reliforp/reli-prof/actions)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/reliforp/reli-prof/badges/quality-score.png?b=0.11.x)](https://scrutinizer-ci.com/g/reliforp/reli-prof/?branch=0.11.x)
[![Coverage Status](https://coveralls.io/repos/github/reliforp/reli-prof/badge.svg?branch=0.11.x)](https://coveralls.io/github/reliforp/reli-prof?branch=0.11.x)
![Psalm coverage](https://shepherd.dev/github/reliforp/reli-prof/coverage.svg?)

Reli is a sampling profiler (or a VM state inspector) written in PHP. It can read information about running PHP script from outside of the process. It's a stand alone CLI tool, so target programs don't need any modifications. The former name of this tool was sj-i/php-profiler. 

## What can I use this for?
- Detecting and visualizing bottlenecks in PHP scripts
  - It provides not only at the function level of profiling but also at line level or opcode level resolution, and even native C-level stack traces from the interpreter itself
- Profiling without accumulated overhead even when a lot of fast functions called as this is a sampling profiler (see the links below, tideways, xhprof, and the profiler of xdebug, many profilers have this overhead)
  - [Profiling Overhead and PHP 7](https://tideways.com/profiler/blog/profiling-overhead-and-php-7)
  - [nikic/sample_prof](https://github.com/nikic/sample_prof)
- Investigating the cause of a bug or performance failure
  - Even if a PHP script is in an unexplained unresponsive state, you can use this to find out what it is doing internally.
- [Finding memory bottlenecks or memory leaks](https://github.com/reliforp/reli-prof/blob/0.11.x/docs/memory-profiler.md)
- [Automatic memory analysis report](docs/memory-report.md): generate prioritized findings from a memory snapshot — dominant classes, cycles, choke points, blame allocation, and more
- [Condition-based monitoring](docs/watch-command.md): automatically trigger memory dumps, trace captures, or alerts when memory thresholds, function calls, or variable conditions are met
- [Variable inspection](docs/peek-var-command.md): read PHP variable values from a running process without modifying it
- [Sidecar daemon](docs/sidecar.md): run a daemon that accepts on-demand memory dump requests from PHP processes via Unix socket — no FFI needed in the application, ideal for memory_limit crash analysis and CI regression detection

## How it works
It's implemented by using following techniques:

- Parsing ELF binary of the interpreter
- Reading memory map from /proc/\<pid\>/maps
- Reading memory of outer process by using ptrace(2) and process_vm_readv(2) via FFI
- Analyzing internal data structure in the PHP VM (aka Zend Engine)

If you have a bit of extra CPU resource, the overhead of this software would be negligible.

## Native (C-level) stack trace support
Reli can collect native C-level stack traces from the PHP interpreter alongside PHP traces. This lets you see what C functions the interpreter is executing inside each PHP function call, which is useful for diagnosing performance issues in PHP internals, extensions, or the interpreter itself.

- Works with stripped binaries (uses exported symbols from `.dynsym`)
- Loads separate debug symbol packages (`-dbgsym` / `-debuginfo`) for full symbol coverage
- Resolves JIT-compiled function names when the target process has `opcache.jit_debug` enabled

## Differences to phpspy, when to use reli
Reli is heavily inspired by [adsr/phpspy](https://github.com/adsr/phpspy).

The main difference between the two is that reli is written in almost pure PHP while phpspy is written in C.
In profiling, there are cases you want to customize how and what information to get.
If customizability for PHP developers matters, you can use this software at the cost of performance. (Although, we hope the cost is not too big.)

Additionally, reli can find VM state from ZTS interpreters. For example, in the daemon mode, traces of threads started via [ext-parallel](https://github.com/krakjoe/parallel) are automatically retrieved. Currently this cannot be done with phpspy only.
Reli also provides functionality to only get the address of EG from targets, so you can use actual profiling with phpspy if you want, even when the target is ZTS.

Furthermore, reli provides a **hybrid phpspy mode** (`phpspy:trace`, `phpspy:daemon`) that combines reli's ZTS-aware EG resolution with phpspy's fast C-based tracing. Reli resolves the EG address (including for ZTS targets where phpspy alone cannot), then launches phpspy as the actual tracer with the resolved address. This gives you phpspy's speed with reli's ZTS support. See [Hybrid phpspy mode](#hybrid-phpspy-mode) for details.

Other features of reli that phpspy does not currently have include:

- Output more accurate line numbers
- Customize output format with PHP templates
- Get running opcodes of the PHP-VM
- Automatic retrieval of the target PHP version from stripped PHP binaries
- Output traces in speedscope, pprof, folded stacks, callgrind, or [compact binary (`.rbt`)](docs/binary-trace-format.md) format
- Deeply analyzing memory usage of the target process
- Collecting native (C-level) stack traces alongside PHP traces via DWARF `.eh_frame` unwinding
- Resolving JIT-compiled function names via perf map and GDB JIT interface

There is no particular reason why these features cannot be implemented on the phpspy side, so it may be possible to do them on phpspy in the future.

On the other hand, there are a few things that phpspy can do but reli cannot yet.

- Redirecting output of child processes
- Run more faster with lower overhead.
- etc.

Much of what can be done with phpspy will be done with reli in the future.

## Requirements
### Supported PHP versions
#### Execution
- PHP 8.1+ (NTS / ZTS)
- 64bit Linux x86_64
- 64bit Linux AArch64 (experimental)
- FFI extension must be enabled.
- PCNTL extension must be enabled.

#### Target
- PHP 7.0+ (NTS / ZTS)
- 64bit Linux x86_64
- 64bit Linux AArch64 (experimental)

On targeting ZTS, reli finds EG from the TLS. Stripped binaries are supported (TLS segments are scanned via brute force). On glibc 2.34+, where libpthread is merged into libc, reli automatically falls back to libc.so, so no extra options are needed in most cases.

### AArch64 (ARM64) support
AArch64 Linux support is experimental. It enables profiling on ARM-based servers (e.g., AWS Graviton) and Apple Silicon Macs running Linux VMs or Docker containers. Both NTS and ZTS targets are supported. See [docs/aarch64-support.md](docs/aarch64-support.md) for technical details.

## Installation
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

### From Docker
```bash
docker pull reliforp/reli-prof
docker run -it --security-opt="apparmor=unconfined" --cap-add=SYS_PTRACE --pid=host reliforp/reli-prof
```

## Usage
### Get call traces
```bash
./reli inspector:trace --help
Description:
  periodically get call trace from an outer process or thread

Usage:
  inspector:trace [options] [--] [<cmd> [<args>...]]

Arguments:
  cmd                                        command to execute as a target: either pid (via -p/--pid) or cmd must be specified
  args                                       command line arguments for cmd

Options:
  -p, --pid=PID                                process id
  -d, --depth[=DEPTH]                          max depth
      --with-native-trace                      collect native (C-level) stack traces alongside PHP traces
      --native-trace-anytime                   collect native traces even when PHP trace is unavailable (e.g. during init, shutdown)
  -s, --sleep-ns[=SLEEP-NS]                    nanoseconds between traces (default: 1000 * 1000 * 10)
  -r, --max-retries[=MAX-RETRIES]              max retries on contiguous errors of read (default: 10)
  -S, --stop-process[=STOP-PROCESS]            stop the target process while reading its trace (default: off)
      --php-regex[=PHP-REGEX]                  regex to find the php binary loaded in the target process
      --libpthread-regex[=LIBPTHREAD-REGEX]    regex to find the libpthread.so loaded in the target process
      --zts-globals-regex[=ZTS-GLOBALS-REGEX]  regex to find the binary containing globals symbols for ZTS loaded in the target process
      --php-version[=PHP-VERSION]              php version (auto|v7[0-4]|v8[01234]) of the target (default: auto)
      --php-path[=PHP-PATH]                    path to the php binary (only needed in tracing chrooted ZTS target)
      --libpthread-path[=LIBPTHREAD-PATH]      path to the libpthread.so (only needed in tracing chrooted ZTS target)
  -t, --template[=TEMPLATE]                    template name (phpspy|phpspy_with_opcode|json_lines) (default: phpspy)
  -o, --output=OUTPUT                          path to write output from this tool (default: stdout)
      --no-cache                               disable the binary analysis cache
  -h, --help                                   Display help for the given command. When no command is given display help for the list command
  -q, --quiet                                  Do not output any message
  -V, --version                                Display this application version
      --ansi|--no-ansi                         Force (or disable --no-ansi) ANSI output
  -n, --no-interaction                         Do not ask any interactive question
  -v|vv|vvv, --verbose                         Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
```

### Daemon mode
```bash
./reli inspector:daemon --help
Description:
  concurrently get call traces from processes whose command-lines match a given regex

Usage:
  inspector:daemon [options]

Options:
  -P, --target-regex=TARGET-REGEX              regex to find target processes which have matching command-line (required)
  -T, --threads[=THREADS]                      number of workers (default: 8)
  -d, --depth[=DEPTH]                          max depth
      --with-native-trace                      collect native (C-level) stack traces alongside PHP traces
      --native-trace-anytime                   collect native traces even when PHP trace is unavailable (e.g. during init, shutdown)
  -s, --sleep-ns[=SLEEP-NS]                    nanoseconds between traces (default: 1000 * 1000 * 10)
  -r, --max-retries[=MAX-RETRIES]              max retries on contiguous errors of read (default: 10)
  -S, --stop-process[=STOP-PROCESS]            stop the target process while reading its trace (default: off)
      --php-regex[=PHP-REGEX]                  regex to find the php binary loaded in the target process
      --libpthread-regex[=LIBPTHREAD-REGEX]    regex to find the libpthread.so loaded in the target process
      --zts-globals-regex[=ZTS-GLOBALS-REGEX]  regex to find the binary containing globals symbols for ZTS loaded in the target process
      --php-version[=PHP-VERSION]              php version (auto|v7[0-4]|v8[01234]) of the target (default: auto)
      --php-path[=PHP-PATH]                    path to the php binary (only needed in tracing chrooted ZTS target)
      --libpthread-path[=LIBPTHREAD-PATH]      path to the libpthread.so (only needed in tracing chrooted ZTS target)
  -t, --template[=TEMPLATE]                    template name (phpspy|phpspy_with_opcode|json_lines) (default: phpspy)
  -o, --output=OUTPUT                          path to write output from this tool (default: stdout)
      --no-cache                               disable the binary analysis cache
  -h, --help                                   Display help for the given command. When no command is given display help for the list command
  -q, --quiet                                  Do not output any message
  -V, --version                                Display this application version
      --ansi|--no-ansi                         Force (or disable --no-ansi) ANSI output
  -n, --no-interaction                         Do not ask any interactive question
  -v|vv|vvv, --verbose                         Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
```

### top-like mode
```bash
./reli inspector:top --help
Description:
  show an aggregated view of traces in real time in a form similar to the UNIX top command.

Usage:
  inspector:top [options]

Options:
  -P, --target-regex=TARGET-REGEX              regex to find target processes which have matching command-line (required)
  -T, --threads[=THREADS]                      number of workers (default: 8)
  -d, --depth[=DEPTH]                          max depth
      --with-native-trace                      collect native (C-level) stack traces alongside PHP traces
      --native-trace-anytime                   collect native traces even when PHP trace is unavailable (e.g. during init, shutdown)
  -s, --sleep-ns[=SLEEP-NS]                    nanoseconds between traces (default: 1000 * 1000 * 10)
  -r, --max-retries[=MAX-RETRIES]              max retries on contiguous errors of read (default: 10)
  -S, --stop-process[=STOP-PROCESS]            stop the target process while reading its trace (default: off)
      --php-regex[=PHP-REGEX]                  regex to find the php binary loaded in the target process
      --libpthread-regex[=LIBPTHREAD-REGEX]    regex to find the libpthread.so loaded in the target process
      --zts-globals-regex[=ZTS-GLOBALS-REGEX]  regex to find the binary containing globals symbols for ZTS loaded in the target process
      --php-version[=PHP-VERSION]              php version (auto|v7[0-4]|v8[01234]) of the target (default: auto)
      --php-path[=PHP-PATH]                    path to the php binary (only needed in tracing chrooted ZTS target)
      --libpthread-path[=LIBPTHREAD-PATH]      path to the libpthread.so (only needed in tracing chrooted ZTS target)
      --no-cache                               disable the binary analysis cache
  -h, --help                                   Display help for the given command. When no command is given display help for the list command
  -q, --quiet                                  Do not output any message
  -V, --version                                Display this application version
      --ansi|--no-ansi                         Force (or disable --no-ansi) ANSI output
  -n, --no-interaction                         Do not ask any interactive question
  -v|vv|vvv, --verbose                         Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
```

### Get the address of EG
```bash
./reli inspector:eg --help
Description:
  get EG address from an outer process or thread

Usage:
  inspector:eg_address [options] [--] [<cmd> [<args>...]]

Arguments:
  cmd                                        command to execute as a target: either pid (via -p/--pid) or cmd must be specified
  args                                       command line arguments for cmd

Options:
  -p, --pid=PID                                process id
      --php-regex[=PHP-REGEX]                  regex to find the php binary loaded in the target process
      --libpthread-regex[=LIBPTHREAD-REGEX]    regex to find the libpthread.so loaded in the target process
      --zts-globals-regex[=ZTS-GLOBALS-REGEX]  regex to find the binary containing globals symbols for ZTS loaded in the target process
      --php-version[=PHP-VERSION]              php version (auto|v7[0-4]|v8[01234]) of the target (default: auto)
      --php-path[=PHP-PATH]                    path to the php binary (only needed in tracing chrooted ZTS target)
      --libpthread-path[=LIBPTHREAD-PATH]      path to the libpthread.so (only needed in tracing chrooted ZTS target)
      --no-cache                               disable the binary analysis cache
  -h, --help                                   Display help for the given command. When no command is given display help for the list command
  -q, --quiet                                  Do not output any message
  -V, --version                                Display this application version
      --ansi|--no-ansi                         Force (or disable --no-ansi) ANSI output
  -n, --no-interaction                         Do not ask any interactive question
  -v|vv|vvv, --verbose                         Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
```

### Hybrid phpspy mode
Reli can use [phpspy](https://github.com/adsr/phpspy) as the tracing backend while handling EG address resolution (including ZTS). This combines phpspy's fast C-based tracing with reli's ZTS support.

#### Install phpspy
```bash
./reli phpspy:install --help
Description:
  install or check phpspy binary

Usage:
  phpspy:install [options]

Options:
      --check                      only check if phpspy is installed, do not install
      --install-path=INSTALL-PATH  install location (default: ~/.reli/bin/phpspy)
      --phpspy-path=PHPSPY-PATH    path to an existing phpspy binary to check
  -h, --help                       Display help for the given command. When no command is given display help for the list command
```

#### Single process tracing with phpspy
```bash
./reli phpspy:trace --help
Description:
  get call traces using phpspy as the tracer backend (reli resolves EG address for ZTS support, then delegates tracing to phpspy)

Usage:
  phpspy:trace [options] [--] [<cmd> [<args>...]]

Arguments:
  cmd                                          command to execute as a target: either pid (via -p/--pid) or cmd must be specified
  args                                         command line arguments for cmd

Options:
  -p, --pid=PID                                process id
  -d, --depth[=DEPTH]                          max depth
      --php-regex[=PHP-REGEX]                  regex to find the php binary loaded in the target process
      --libpthread-regex[=LIBPTHREAD-REGEX]    regex to find the libpthread.so loaded in the target process
      --zts-globals-regex[=ZTS-GLOBALS-REGEX]  regex to find the binary containing globals symbols for ZTS loaded in the target process
      --php-version[=PHP-VERSION]              php version (auto|v7[0-4]|v8[012345]) of the target (default: auto)
      --php-path[=PHP-PATH]                    path to the php binary (only needed in tracing chrooted ZTS target)
      --libpthread-path[=LIBPTHREAD-PATH]      path to the libpthread.so (only needed in tracing chrooted ZTS target)
      --phpspy-path=PHPSPY-PATH                path to the phpspy binary
  -s, --sleep-ns[=SLEEP-NS]                    phpspy sleep between samples in nanoseconds (default: 10101010)
  -b, --buffer-size[=BUFFER-SIZE]              phpspy buffer size (default: 4096)
  -H, --rate-hz[=RATE-HZ]                      phpspy sampling rate in Hz (default: 99)
      --phpspy-args=PHPSPY-ARGS                extra arguments to pass to phpspy
      --no-cache                               disable the binary analysis cache
  -o, --output=OUTPUT                          output file path (default: stdout)
```

#### Multi-process daemon mode with phpspy
```bash
./reli phpspy:daemon --help
Description:
  concurrently get call traces from multiple processes using phpspy as the tracer backend (reli resolves EG addresses for ZTS support, then delegates tracing to phpspy)

Usage:
  phpspy:daemon [options]

Options:
  -P, --target-regex=TARGET-REGEX              regex to find target processes which have matching command-line (required)
  -T, --threads[=THREADS]                      number of workers (default: 8)
  -d, --depth[=DEPTH]                          max depth
      --phpspy-path=PHPSPY-PATH                path to the phpspy binary
  -s, --sleep-ns[=SLEEP-NS]                    phpspy sleep between samples in nanoseconds (default: 10101010)
  -b, --buffer-size[=BUFFER-SIZE]              phpspy buffer size (default: 4096)
  -H, --rate-hz[=RATE-HZ]                      phpspy sampling rate in Hz (default: 99)
      --phpspy-args=PHPSPY-ARGS                extra arguments to pass to phpspy
      --no-cache                               disable the binary analysis cache
  -o, --output=OUTPUT                          output file path (default: stdout)
```

## [Experimental] Dump the memory usage of the target process
```bash
./reli inspector:memory --help
Description:
  [experimental] get memory usage from an outer process

Usage:
  inspector:memory [options] [--] [<cmd> [<args>...]]

Arguments:
  cmd                                                                command to execute as a target: either pid (via -p/--pid) or cmd must be specified
  args                                                               command line arguments for cmd

Options:
      --stop-process|--no-stop-process                               stop the process while inspecting (default: on)
      --pretty-print|--no-pretty-print                               pretty print the result (default: off)
  -f, --output-format=OUTPUT-FORMAT                                  output format (json, sqlite3, mysql, postgresql) [default: "json"]
  -o, --output=OUTPUT                                                output file path (required for sqlite3 format)
      --db-host=DB-HOST                                              database host (for mysql/postgresql) [default: "127.0.0.1"]
      --db-port=DB-PORT                                              database port (for mysql/postgresql)
      --db-name=DB-NAME                                              database name (for mysql/postgresql)
      --db-user=DB-USER                                              database user (for mysql/postgresql)
      --db-password=DB-PASSWORD                                      database password (for mysql/postgresql)
      --memory-usage-error-file=MEMORY-LIMIT-ERROR-FILE              file path where memory_limit is exceeded
      --memory-usage-error-line=MEMORY-LIMIT-ERROR-LINE              line number where memory_limit is exceeded
      --memory-usage-error-max-depth[=MEMORY-LIMIT-ERROR-MAX-DEPTH]  max attempts to trace back the VM stack on memory_limit error [default: 512]
  -p, --pid=PID                                                      process id
      --php-regex[=PHP-REGEX]                                        regex to find the php binary loaded in the target process
      --libpthread-regex[=LIBPTHREAD-REGEX]                          regex to find the libpthread.so loaded in the target process
      --zts-globals-regex[=ZTS-GLOBALS-REGEX]                        regex to find the binary containing globals symbols for ZTS loaded in the target process
      --php-version[=PHP-VERSION]                                    php version (auto|v7[0-4]|v8[01234]) of the target (default: auto)
      --php-path[=PHP-PATH]                                          path to the php binary (only needed in tracing chrooted ZTS target)
      --libpthread-path[=LIBPTHREAD-PATH]                            path to the libpthread.so (only needed in tracing chrooted ZTS target)
      --no-cache                                                     disable the binary analysis cache
  -h, --help                                                         Display help for the given command. When no command is given display help for the list command
  -q, --quiet                                                        Do not output any message
  -V, --version                                                      Display this application version
      --ansi|--no-ansi                                               Force (or disable --no-ansi) ANSI output
  -n, --no-interaction                                               Do not ask any interactive question
  -v|vv|vvv, --verbose                                               Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug

```

## [Experimental] Watch: Condition-Based Process Monitoring

`inspector:watch` monitors PHP processes and triggers profiling actions when configurable conditions are met. It only takes action when triggers fire, making it suitable for low-overhead production monitoring.

```bash
# Dump memory when usage exceeds 256M
./reli inspector:watch -p <pid> --memory-usage=256M

# Monitor multiple php-fpm processes
./reli inspector:watch --target-regex="php-fpm" --memory-usage=512M --action=log

# Watch for a specific function in the call stack
./reli inspector:watch -p <pid> --watch-function="App\Service::process" --action=trace

# Monitor a PHP variable
./reli inspector:watch -p <pid> --watch-var='global::$cache:count_gt:10000'

# Grab 3 memory dumps and stop
./reli inspector:watch -p <pid> --memory-usage=128M --oneshot=3
```

Available triggers: `--memory-usage`, `--memory-growth-rate`, `--memory-peak-watch`, `--watch-function`, `--trace-depth-limit`, `--watch-var`.

Available actions: `memory-dump` (default), `trace`, `log`, `exec`.

Rate limiting: `--cooldown` (with exponential backoff), `--max-triggers-per-hour`, `--max-dump-size`.

See [docs/watch-command.md](docs/watch-command.md) for full documentation.

## [Experimental] Peek Variable: One-Shot Variable Inspection

`inspector:peek-var` reads PHP variable values from a running process — no triggers or actions, just the current value.

```bash
# Read global variables
./reli inspector:peek-var -p <pid> --var='global::$counter' --var='global::$cache'

# Repeat every 500ms
./reli inspector:peek-var -p <pid> --var='global::$queue' --repeat=500

# JSON output for scripting
./reli inspector:peek-var -p <pid> --var='global::$counter' --format=json
```

Supported scopes: `global::$var`, `local::func()$var`, `static::Class::$prop`, `func_static::func()$var`.

See [docs/peek-var-command.md](docs/peek-var-command.md) for full documentation.

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

### Daemon mode
```bash
$ sudo php ./reli i:daemon -P "^/usr/sbin/httpd"
``` 
The executing process must have the CAP_SYS_PTRACE capability. (Usually run as root is enough.)

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
If a user wants to profile a really CPU-bound application, then he or she wouldn't only want to know what line is slow, but what opcode is. In such cases, use `--template=phpspy_with_opcode` with `inspector:trace` or `inspector:daemon`.

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

### Generate flamegraphs from traces
```bash
$ ./reli i:trace -o traces -- php ./vendor/bin/psalm.phar --no-cache
$ ./reli c:flamegraph <traces >flame.svg
$ google-chrome flame.svg
```

The generated flamegraph below visualizes traces from the execution of the psalm command.

![flame](https://user-images.githubusercontent.com/6488121/153741551-3f0fc730-c748-4908-b8ac-7c3f46a5bdbc.svg)

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

The output is phpspy-compatible, so it can be directly converted to flamegraphs or speedscope profiles:
```bash
$ ./reli i:trace --with-native-trace -o traces -p <pid>
$ ./reli c:flamegraph <traces >flame_native.svg
```

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

### Generate the [speedscope](https://github.com/jlfwong/speedscope) format from phpspy compatible traces
```bash
$ sudo php ./reli i:trace -p <pid of the target process or thread> >traces
$ ./reli c:speedscope <traces >profile.speedscope.json
$ speedscope profile.speedscope.json
```

See [#101](https://github.com/reliforp/reli-prof/pull/101).

### Generate the callgrind format output from phpspy compatible traces and visualize it with kcachegrind
```bash
$ ./reli c:callgrind <traces >callgrind.out
$ kcachegrind callgrind.out
  ```

### Binary trace format (`.rbt`)

Reli includes a compact binary trace format that achieves **~370x compression** vs phpspy text (measured: 70 MB phpspy → 180 KB rbt). It uses string interning, stack deduplication, and run-length encoding.

```bash
# Capture directly to rbt (single process)
$ sudo php ./reli i:trace -p <pid> -F rbt -o trace.rbt

# Capture with daemon (per-worker files, zero IPC overhead)
$ sudo php ./reli i:daemon -F rbt -o /path/to/output_dir/

# Convert between formats (auto-detects rbt or phpspy input)
$ ./reli converter:pprof <trace.rbt >profile.pb.gz
$ ./reli converter:speedscope <trace.rbt >profile.json
$ ./reli converter:folded <trace.rbt | flamegraph.pl >flame.svg
$ ./reli converter:phpspy <trace.rbt     # decode to text

# Also works with phpspy text input
$ ./reli converter:pprof <traces >profile.pb.gz

# Recover corrupted/truncated files
$ ./reli rbt:recover <corrupted.rbt >recovered.rbt
```

See [docs/binary-trace-format.md](docs/binary-trace-format.md) for the full specification.

### Dump the memory usage of the target process

> [!CAUTION]
> **Don't upload the output of this command to the internet, because it can contain sensitive information of the target script!!!**

> [!WARNING]  
> This feature is in an experimental stage and may be less stable than others. The contents of the output may change in the near future.

```bash
$ sudo php ./reli i:memory -p 2183131 >2183131.memory_dump.json
$ cat 2183131.memory_dump.json | jq .summary
```

Only NTS targets are supported for now.

The output would be like the following.

```bash
[
  {
    "zend_mm_heap_total": 10485760,
    "zend_mm_heap_usage": 7642504,
    "zend_mm_chunk_total": 10485760,
    "zend_mm_chunk_usage": 7642504,
    "zend_mm_huge_total": 0,
    "zend_mm_huge_usage": 0,
    "vm_stack_total": 262144,
    "vm_stack_usage": 8224,
    "compiler_arena_total": 917504,
    "compiler_arena_usage": 815480,
    "possible_allocation_overhead_total": 549645,
    "possible_array_overhead_total": 378768,
    "memory_get_usage": 8263440,
    "memory_get_real_usage": 12582912,
    "cached_chunks_size": 2097152,
    "heap_memory_analyzed_percentage": 92.48574443573136,
    "php_version": "v82"
  }
]

```

And you can get the call trace from the dump.

```bash
$ cat 2183131.memory_dump.json | jq '.context.call_frames[]|objects|.function_name'
"time_nanosleep"
"Reli\\Lib\\Loop\\LoopMiddleware\\NanoSleepMiddleware::invoke"
"Reli\\Lib\\Loop\\LoopMiddleware\\KeyboardCancelMiddleware::invoke"
"Reli\\Lib\\Loop\\LoopMiddleware\\RetryOnExceptionMiddleware::invoke"
"Reli\\Lib\\Loop\\Loop::invoke"
"Reli\\Command\\Inspector\\GetTraceCommand::execute"
"Symfony\\Component\\Console\\Command\\Command::run"
"Symfony\\Component\\Console\\Application::doRunCommand"
"Symfony\\Component\\Console\\Application::doRun"
"Symfony\\Component\\Console\\Application::run"
""
```

You can also see the contents of the local variables of a specific call frame.

```bash
$ cat 2183131.memory_dump.json | jq '.context.call_frames[]|objects|select(.function_name=="time_nanosleep")'
{
  "#node_id": 1,
  "#type": "CallFrameContext",
  "function_name": "time_nanosleep",
  "local_variables": {
    "#node_id": 2,
    "#type": "CallFrameVariableTableContext",
    "$args_to_internal_function[0]": {
      "#node_id": 3,
      "#type": "ScalarValueContext",
      "value": 0
    },
    "$args_to_internal_function[1]": {
      "#node_id": 4,
      "#type": "ScalarValueContext",
      "value": 9743095
    }
  }
}
```

If a context is referencing another location in the dump file, it can also be extracted with `jq`.

```bash
$ cat 2183131.memory_dump.json | jq '.context.call_frames["7"].local_variables'
{
  "#node_id": 1433,
  "#type": "CallFrameVariableTableContext",
  "command": {
    "#reference_node_id": 368
  },
  "input": {
    "#reference_node_id": 1395
  },
  "output": {
    "#reference_node_id": 54
  },
  "helper": {
    "#reference_node_id": 591
  },
  "commandSignals": {
    "#reference_node_id": 69
  }
}

$ cat 2183131.memory_dump.json | jq '..|objects|select(."#node_id"==368)|.' | head -n 20
{
  "#node_id": 368,
  "#type": "ObjectContext",
  "#locations": [
    {
      "address": 139988652434432,
      "size": 472,
      "refcount": 6,
      "type_info": 3221409800,
      "class_name": "Reli\\Command\\Inspector\\GetTraceCommand"
    }
  ],
  "object_handlers": {
    "#reference_node_id": 7
  },
  "object_properties": {
    "#node_id": 369,
    "#type": "ObjectPropertiesContext",
    "php_globals_finder": {
      "#node_id": 370,
      "#type": "ObjectContext",
      "#locations": [
        {
```

You can also extract all references to a specific object.

```bash
$ cat 2183131.memory_dump.json | jq 'path(..|objects|select(."#reference_node_id"==368 or ."#node_id"==368))|join(".")'
"context.call_frames.1.this.chain.callable.closure.this_ptr"
"context.call_frames.1.this.chain.callable.closure.this_ptr.application.commands.array_elements.inspector:trace.value"
"context.call_frames.1.this.chain.callable.closure.this_ptr.application.runningCommand"
"context.call_frames.5.this"
"context.call_frames.6.this"
"context.call_frames.7.local_variables.command"
"context.call_frames.8.local_variables.command"
"context.objects_store.285"

```

The refcount of the object recorded in the memory location is 6 in this example. Calling methods via `$obj->call()` adds refcount by 1, but `$this->call()` doesn't add refcount. References from objects_store don't add refcount too. So all 6 references are analyzed here.

See [./docs/memory-profiler.md](https://github.com/reliforp/reli-prof/blob/0.11.x/docs/memory-profiler.md) for more info.

### Automatic analysis report

Instead of manually querying with `jq`, you can generate an automatic analysis report. First save to SQLite, then run the report:

```bash
$ sudo php ./reli i:m -p <pid> -f sqlite3 -o snapshot.db
$ php ./reli inspector:memory:report snapshot.db
```

Or generate the report directly:

```bash
$ sudo php ./reli i:m -p <pid> -f report
```

The report identifies dominant classes, circular references, choke points, deduplication candidates, and more — with severity, hypothesis, and next steps for each finding. See [docs/memory-report.md](docs/memory-report.md) for details.

### Comparing two snapshots

Compare memory snapshots to find regressions, verify fixes, or track leaks over time:

```bash
$ sudo php ./reli i:m -p <pid> -f sqlite3 -o before.db
# ... deploy code change, trigger workload, etc.
$ sudo php ./reli i:m -p <pid> -f sqlite3 -o after.db
$ php ./reli inspector:memory:compare before.db after.db

# Or compare run IDs within the same database
$ php ./reli inspector:memory:compare snapshot.db --run-id-baseline 1 --run-id-target 2
```

The comparison report shows summary deltas, type breakdown deltas, per-class memory changes (added/removed/changed), and findings diff (new/resolved/changed issues). Use `--threshold 5` to filter changes smaller than 5%. See [docs/memory-report.md](docs/memory-report.md) for details.

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
