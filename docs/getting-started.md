# Getting started with reli

This page walks you from nothing to your first useful trace. If you
already know what reli is and just need the command reference, jump
to the [documentation index](README.md) or the [README](../README.md).

## What reli gives you

reli is a sampling profiler (and VM state inspector) that reads a
running PHP process from the outside — no extension to load, no code
changes to the target. Four broad capabilities:

- **Where time is spent** — periodic call-stack samples, optionally
  with C-level frames and executing-opcode detail. Output to the
  compact `.rbt` binary format (or phpspy-compatible text) and
  browse with `rbt:explore` / `rbt:analyze`.
- **Where memory is used** — reconstruct the PHP heap into a
  queryable graph. `.rmem` is the fastest on-disk format and is what
  every analyser reads natively: browse interactively with
  `rmem:explore`, get a prioritised report with `memory:report`, or
  compare two snapshots with `memory:compare`. SQLite and JSON
  outputs are also supported for interop.
- **What values flow through** — read PHP variable values from a
  running process with `inspector:peek-var`, or attach variable
  values to every trace sample with `inspector:trace --trace-var`
  so that runtime state sits next to the hot stacks that produced
  it.
- **When something goes wrong** — react to runtime conditions.
  `inspector:watch` triggers a memory dump / trace when memory
  thresholds, function calls, or variable conditions are met.
  `inspector:sidecar` accepts on-demand dump requests from the
  application over a Unix socket — ideal for `memory_limit` crash
  analysis.

For the full catalogue of tasks-and-commands, see the
[documentation index](README.md).

## Requirements

### Runtime

- PHP 8.5+ (NTS / ZTS)
- 64bit Linux x86_64 (or AArch64, experimental)
- `FFI` extension enabled
- `PCNTL` extension enabled
- Ability to `ptrace(2)` the target — usually running reli as root, or granting `CAP_SYS_PTRACE`

### Target PHP process

- PHP 7.0+ (NTS / ZTS)
- 64bit Linux x86_64 (or AArch64, experimental)

### Platform notes

- **AArch64 (ARM64)** — experimental.
- **Alpine / musl libc** — `--with-native-trace` not supported.

## 1. Install

The provided Docker image is usually the easiest starting point —
PHP 8.5, FFI and PCNTL already enabled, and `--cap-add=SYS_PTRACE`
+ `--pid=host` let you target PHP processes running on the host or
in other containers without touching the host shell:

```bash
docker pull reliforp/reli-prof
docker run -it --security-opt="apparmor=unconfined" \
                --cap-add=SYS_PTRACE --pid=host \
                reliforp/reli-prof
```

`--cap-add=SYS_PTRACE` grants reli the ptrace capability, and
`--pid=host` makes PHP processes running on the host (or in other
containers) visible as targets — no extra setup on the host side.

Inside the container the CLI is available as `reli` (and also as
`./reli`). Everything below is the same whether you invoke it from
the container or from a native install.

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

## 2. Smoke test

Trace a throwaway PHP command to confirm reli can see inside the VM:

```bash
$ ./reli inspector:trace -- php -r "for(;;){usleep(10000);}"
0 usleep <internal>:-1
1 <main> Command line code:1

0 usleep <internal>:-1
1 <main> Command line code:1
...
<press q to exit>
```

You should see samples scrolling by roughly ~100 times per second.
Stop it with `q` or `Ctrl-C`.

If this works, you're ready for real targets. If it fails, the
[Troubleshooting](troubleshooting.md) page covers the
common issues (missing `CAP_SYS_PTRACE`, unusual PHP binary paths,
Amazon Linux 2 memory maps).

## 3. Your first real trace

The recommended capture format for anything beyond eyeballing is
`.rbt` — a compact binary format (~370× smaller than phpspy text,
and what `rbt:explore` / `rbt:analyze` are built around).

Run your workload in one terminal, attach reli from another:

```bash
# Terminal A: something to profile
php ./your-script.php

# Terminal B: attach and capture for ~10 s, then Ctrl-C
sudo php ./reli inspector:trace -p "$(pgrep -f your-script.php)" \
                                -F rbt -o trace.rbt
```

The target process must be reachable with `ptrace(2)` — usually this
means running reli as root (or granting `CAP_SYS_PTRACE`).

## 4. Read the trace

Drop into the interactive TUI:

```bash
./reli rbt:explore trace.rbt
```

From here you can:

- Press `/` to filter frames by regex, then navigate hot code.
- Switch between sandwich / flame / tree views.
- Press `c` to toggle the opcode column.
- Press `?` for the full keymap.

Full walkthrough: [tracing/rbt-analyze-and-explore.md](tracing/rbt-analyze-and-explore.md).

If you prefer a one-shot text report, `rbt:analyze trace.rbt` prints
hot frames, callers/callees of a regex, and a live tail — see the
same doc.

To feed the trace into an existing visualiser (speedscope,
Flamegraph SVG, pprof, callgrind, …) use the converters:

```bash
./reli converter:speedscope <trace.rbt >profile.speedscope.json
./reli converter:flamegraph <trace.rbt >flame.svg
```

Full list and `.rbt` format details: [tracing/binary-trace-format.md](tracing/binary-trace-format.md).

## 5. Where to go next

The [documentation index](README.md) maps tasks to commands and
docs. Suggested entry points:

- **Memory leaks / heap analysis**: dump now, analyse later
  → [memory/memory-dump.md](memory/memory-dump.md) →
    [memory/rmem-explore-and-serve.md](memory/rmem-explore-and-serve.md) /
    [memory/memory-report.md](memory/memory-report.md)
- **Condition-triggered captures in production**:
  [monitoring/watch-command.md](monitoring/watch-command.md)
- **On-demand dumps from inside the app**:
  [monitoring/sidecar.md](monitoring/sidecar.md)
- **Reading PHP variables from the outside**:
  [inspection/peek-var-command.md](inspection/peek-var-command.md) /
  [inspection/trace-var-command.md](inspection/trace-var-command.md)
- **Post-mortem from a core file**:
  [memory/coredump.md](memory/coredump.md)
- **Tracing a ZTS PHP process via phpspy**:
  [tracing/phpspy-hybrid.md](tracing/phpspy-hybrid.md)
