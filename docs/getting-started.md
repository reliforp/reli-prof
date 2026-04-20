# Getting started with reli

This page walks you from nothing to your first useful trace. If you
already know what reli is and just need the command reference, jump
to the [documentation index](README.md) or the [README](../README.md).

## What reli gives you

reli is a sampling profiler (and VM state inspector) that reads a
running PHP process from the outside — no extension to load, no code
changes to the target. Two broad capabilities:

- **Where time is spent**: periodic call-stack samples, optionally
  with C-level frames and executing-opcode detail. Output to a
  compact binary format (`.rbt`) or phpspy-compatible text.
- **Where memory is used**: reconstruct the PHP heap into a queryable
  graph (SQLite / JSON / findings report). Browse it interactively
  with `rmem:explore`, get a prioritised report with `memory:report`,
  or compare two snapshots to track regressions.

For the full catalogue of tasks-and-commands, see the
[documentation index](README.md).

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

Inside the container the CLI is available as `reli` (and also as
`./reli`). Everything below is the same whether you invoke it from
the container or from a native install.

Alternative installs — Composer, Git — are documented in
[README § Installation](../README.md#installation). Requirements
(supported PHP versions, platforms) are in
[README § Requirements](../README.md#requirements).

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
[Troubleshooting](../README.md#troubleshooting) section covers the
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
  [README § Hybrid phpspy mode](../README.md#hybrid-phpspy-mode)
