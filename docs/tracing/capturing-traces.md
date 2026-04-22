# Capturing call traces

How to attach reli to a PHP process (or spawn one) and capture call
stacks. For analysing the result, see
[rbt-analyze-and-explore.md](rbt-analyze-and-explore.md); for the
on-disk format, [binary-trace-format.md](binary-trace-format.md);
for opcode / native-frame / JIT captures,
[advanced-capture.md](advanced-capture.md); for using phpspy as the
tracing backend, [phpspy-hybrid.md](phpspy-hybrid.md).

## Commands

| Command | Shape |
|---|---|
| `inspector:trace` | Sample one process (by `-p <pid>`) or spawn one and sample it |
| `inspector:daemon` | Concurrently sample every process whose command-line matches a regex |
| `inspector:top` | UNIX-`top`-style live aggregated view across matching processes |
| `inspector:eg` | Just the EG address (e.g. to feed phpspy manually) |

All four require `CAP_SYS_PTRACE` on the reli process (running as
root is usually enough).

Every option listed below exists on the actual CLI — run
`./reli <command> --help` for the complete flag set and the default
values. The CLI help is the source of truth.

## `inspector:trace`

Sample a single process, either by attaching or by spawning:

```bash
# Attach to a running process
sudo php ./reli inspector:trace -p <pid>

# Spawn and trace a new process
./reli inspector:trace -- php script.php

# Capture to the compact .rbt binary format (recommended for later
# analysis with rbt:explore / rbt:analyze / converter:*)
sudo php ./reli inspector:trace -p <pid> -F rbt -o trace.rbt
```

Useful flags:

- `-F/--output-format=rbt|rbt-bundled|template:phpspy|template:phpspy_with_opcode|template:json_lines`
- `-o/--output <path>` — file path (default: stdout)
- `-d/--depth <N>` — max stack depth
- `-s/--sleep-ns <N>` — sleep between samples (default ~10 ms)
- `-S/--stop-process` — `SIGSTOP` the target for the duration of each sample (more accurate at a higher cost)
- `--with-native-trace`, `--native-trace-anytime` — see [advanced-capture.md](advanced-capture.md)
- `--trace-var=…` — see [../inspection/trace-var-command.md](../inspection/trace-var-command.md)

## `inspector:daemon`

Trace every process whose command line matches a regex. Common for
`php-fpm` pools or any worker army:

```bash
# Live view (phpspy-style text output per worker)
sudo php ./reli inspector:daemon -P "^/usr/sbin/httpd"

# Per-worker .rbt files (zero IPC overhead between workers)
sudo php ./reli inspector:daemon -P "^php-fpm" -F rbt -o ./traces/
```

Useful flags (in addition to the `inspector:trace` set):

- `-P/--target-regex <regex>` (required)
- `-T/--threads <N>` — worker pool size

Output modes for `.rbt` come in two flavours: per-worker files
(`-F rbt -o <dir>/`) which each worker writes independently, or a
single bundled stream. See [binary-trace-format.md](binary-trace-format.md).

## `inspector:top`

UNIX-`top`-style aggregated view, updated live as samples come in.
Useful as a quick "what is this pool doing right now?" check:

```bash
sudo php ./reli inspector:top -P "^php-fpm"
```

Flags are the regex/pool subset of `inspector:daemon`:
`-P/--target-regex`, `-T/--threads`, `-d/--depth`, `-s/--sleep-ns`,
`--with-native-trace`.

## `inspector:eg`

Just the EG address, no sampling. Useful if you want to feed phpspy
manually, or script an integration that needs to bootstrap phpspy
itself with an EG address (which phpspy alone cannot resolve for
ZTS):

```bash
$ sudo php ./reli inspector:eg -p <pid>
0x555ae7825d80
```

If all you wanted was "phpspy but ZTS-aware", reach for
[phpspy-hybrid.md](phpspy-hybrid.md) instead — `phpspy:trace` wraps
the EG lookup and the phpspy launch into one command.

## See also

- [rbt-analyze-and-explore.md](rbt-analyze-and-explore.md) — read the captured trace
- [binary-trace-format.md](binary-trace-format.md) — `.rbt` format spec, converters, `rbt:recover`
- [advanced-capture.md](advanced-capture.md) — opcodes, native traces, JIT
- [phpspy-hybrid.md](phpspy-hybrid.md) — phpspy as the tracer backend, ZTS-aware
- [../inspection/trace-var-command.md](../inspection/trace-var-command.md) — attach variable values to each sample
