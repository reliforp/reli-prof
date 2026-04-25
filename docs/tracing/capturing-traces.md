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
sudo php ./reli inspector:trace -p <pid> -o trace.rbt
```

Useful flags:

- `-F/--output-format=rbt|rbt-bundled|template:phpspy|template:phpspy_with_opcode|template:json_lines`
- `-o/--output <path>` — file path (default: stdout)
- `-d/--depth <N>` — max stack depth
- `-s/--sleep-ns <N>` — sleep between samples (default ~10 ms)
- `-S/--stop-process` — `SIGSTOP` the target for the duration of each sample (more accurate at a higher cost)
- `--with-native-trace`, `--native-trace-anytime` — see [advanced-capture.md](advanced-capture.md)
- `--trace-var=…` — see [../inspection/trace-var-command.md](../inspection/trace-var-command.md)

### What the output looks like

Pick the format with `-F`. If `-F` is omitted, the format is chosen
from the output destination: writing to a `.rbt` file picks the
binary format, anything else (including stdout) falls back to the
configured default template — phpspy text out of the box.

#### Text (default: `-F template:phpspy`)

One block per sample, blank-line separated. Each line is
`<idx> <fqn> <file>:<line>`, deepest frame on top:

```
0 App\Controller::handle /app/src/Controller.php:17
1 App\Http\Router::dispatch /app/src/Router.php:42
2 <main> /app/public/index.php:9

0 PDO::query /app/src/Db.php:142
1 App\UserRepo::find /app/src/UserRepo.php:28
2 App\Controller::handle /app/src/Controller.php:18
3 App\Http\Router::dispatch /app/src/Router.php:42
4 <main> /app/public/index.php:9

```

Convenient for eyeballing live and for piping into any
phpspy-compatible tool. Note that the phpspy text format is **much
larger** than `.rbt` for the same trace — for anything beyond a
quick look, capture to `.rbt` instead.

#### Binary (`-F rbt`, auto-selected for `-o *.rbt`)

Compact append-only binary — typically a few bytes per sample after
string interning and run-length encoding. Not human-readable;
inspect or convert it with:

```bash
./reli rbt:explore   trace.rbt          # interactive TUI
./reli rbt:analyze < trace.rbt          # one-shot text report
./reli converter:phpspy < trace.rbt     # decode back to phpspy text
./reli converter:speedscope < trace.rbt # speedscope JSON
./reli converter:pprof < trace.rbt      # pprof
./reli converter:flamegraph < trace.rbt # flamegraph SVG (via Brendan Gregg's tool)
```

Recommended for any capture beyond a few seconds: `.rbt` files stay
small enough to keep around, the analysers above all expect this
format, and every other text/visualisation format reli supports is
reachable via `converter:*`. See [binary-trace-format.md](binary-trace-format.md)
for the format spec.

#### Choosing

| Criterion | `template:phpspy` (default on stdout) | `rbt` (auto-selected for `-o *.rbt`) |
|---|---|---|
| File size | large (text per sample) | small (interned, RLE) |
| Human-readable | yes | no — use `rbt:analyze` / `rbt:explore` |
| Live tail (`tail -f`, `less`) | yes | no |
| Works with `rbt:analyze` / `rbt:explore` | no — convert with `converter:rbt` first | yes |
| Per-sample annotations (`--trace-var`) | yes (`#` comment lines) | yes |
| Per-sample timestamps, segment markers | no | yes |
| Long captures (minutes+) | not recommended | recommended |

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
- [frankenphp.md](frankenphp.md) — profiling FrankenPHP (embedded PHP in Caddy)
- [../inspection/trace-var-command.md](../inspection/trace-var-command.md) — attach variable values to each sample
