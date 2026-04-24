# Profiling FrankenPHP

[FrankenPHP](https://frankenphp.dev/) embeds PHP inside a Caddy
(Go) web server. A FrankenPHP process is a single OS process with
many threads: Caddy's Go runtime threads (the majority) plus a
handful of PHP worker threads that actually host the Zend VM.
reli can profile FrankenPHP, but three things differ from a vanilla
`php` target:

1. PHP is loaded as `libphp.so`, not as the main binary.
2. Glibc's pthread implementation lives inside `libc.so` rather
   than a separate `libpthread.so`.
3. Only the PHP worker threads carry valid executor globals; the
   Go-runtime TIDs have no PHP state.

The three CLI flags below match those realities.

## Required flags

| Flag | Value | Why |
|---|---|---|
| `--php-regex` | `.*/libphp\.so$` | PHP is loaded as a shared library; the default (`/php$`) never matches |
| `--libpthread-regex` | `.*/libc\.so.*` | pthread functions live in libc, not in a separate libpthread |
| `--target-thread-regex` (daemon/top/watch) | `^php-[0-9a-f]+$` | Restricts sampling to PHP worker threads; see [Thread filtering](#thread-filtering) |

## Attach by regex (recommended)

`inspector:daemon` — plus the optional per-thread filter —
discovers FrankenPHP workers automatically and skips Caddy / Go
runtime threads:

```bash
sudo php ./reli inspector:daemon \
    -P frankenphp \
    --target-thread-regex='^php-[0-9a-f]+$' \
    --php-regex='.*/libphp\.so$' \
    --libpthread-regex='.*/libc\.so.*' \
    -F rbt -o ./traces/
```

The same flags work with `inspector:top` for a live view and with
`inspector:watch --target-regex=...` for triggered captures.

## Attach to a single worker

`inspector:trace -p <pid>` expects the PID/TID of a thread that
already carries PHP executor globals. For FrankenPHP, pass the
worker TID, not the Caddy parent PID:

```bash
# List PHP worker threads by name
ps -L -p "$(pgrep -o frankenphp)" -o tid,comm | awk '$2 ~ /^php-/'
#   12345 php-0
#   12346 php-1

sudo php ./reli inspector:trace -p 12345 \
    --php-regex='.*/libphp\.so$' \
    --libpthread-regex='.*/libc\.so.*'
```

`--target-thread-regex` is not honoured by `inspector:trace`: the
single-process mode samples exactly the TID you pass in, so choose
the worker up front.

## Thread filtering

FrankenPHP names its PHP worker threads via `prctl(PR_SET_NAME)`
as `php-<hex-index>` (`php-0`, `php-1`, `php-1a`, …). reli reads
`/proc/<tid>/comm` and, when `--target-thread-regex` is set, only
considers matching TIDs as trace targets.

Without `--target-thread-regex` the daemon still works — every
non-PHP TID is tried once and then marked invalid via the binary
analysis cache — but:

- The first pass over a fresh FrankenPHP process pays a full TLS
  brute-force scan per Go-runtime thread before giving up.
- `debug`-level logs fill with "error on analyzing php binary"
  lines for every non-PHP thread.

`--target-thread-regex='^php-[0-9a-f]+$'` avoids both.

## Memory and watch commands

`inspector:memory`, `inspector:memory:dump`, `inspector:sidecar`,
and `inspector:watch -p <pid>` also need a PHP worker TID, not the
parent PID:

```bash
sudo php ./reli inspector:memory:dump -p "$(
    ps -L -p "$(pgrep -o frankenphp)" -o tid=,comm= |
        awk '$2 ~ /^php-/ {print $1; exit}'
)" \
    --php-regex='.*/libphp\.so$' \
    --libpthread-regex='.*/libc\.so.*' \
    -o ./frankenphp.rmem
```

## Caveats

- **Thread name as identifier is an internal FrankenPHP convention,
  not a stable interface.** `php-%PRIxPTR` is set by
  `set_thread_name()` in `frankenphp.c`. If upstream changes this,
  `--target-thread-regex` needs to be adjusted accordingly.
- **Worker threads come and go.** Idle workers may be torn down;
  in daemon mode this is fine (they disappear from the next search
  pass), but a long-running `inspector:trace -p <worker-tid>`
  will fail once that TID exits. Prefer daemon mode for long
  sessions.
- **Target PHP must be 8.x ZTS.** FrankenPHP requires ZTS; reli's
  ZTS TLS resolution is covered by
  [internals/binary-analysis-cache.md](../internals/binary-analysis-cache.md).

## See also

- [capturing-traces.md](capturing-traces.md) — `inspector:daemon` /
  `inspector:top` / `inspector:trace` reference
- [../troubleshooting.md](../troubleshooting.md) — `--php-regex`
  and other target-resolution troubleshooting
- [../internals/binary-analysis-cache.md](../internals/binary-analysis-cache.md)
  — how TLS offsets are cached across runs (matters for
  FrankenPHP's first-pass cost)
