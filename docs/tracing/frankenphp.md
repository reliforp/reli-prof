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
already carries PHP executor globals. For FrankenPHP, pass a
**hex-named** worker TID — not the Caddy parent PID, and not
`php-main`:

```bash
# List PHP worker threads by name (hex-only — see note below)
ps -L -p "$(pgrep -o frankenphp)" -o tid,comm |
    awk '$2 ~ /^php-[0-9a-f]+$/'
#   12345 php-0
#   12346 php-1

sudo php ./reli inspector:trace -p 12345 \
    --php-regex='.*/libphp\.so$' \
    --libpthread-regex='.*/libc\.so.*'
```

A few realities on FrankenPHP that the snippet above is built around:

- **Skip `php-main`.** It also matches `^php-` but is the
  bootstrap thread — its executor globals do not point at a
  populated request heap, so memory commands fail with
  "failed to find ZendMM main chunk". `^php-[0-9a-f]+$` excludes
  it.
- **First attach is slower than subsequent ones.** The first attach
  to a given `libphp.so` parses the dynamic symbol table, reads
  PT_TLS, and brute-forces `_tsrm_ls_cache` out of the TLS block.
  Once that succeeds, the offset is cached on disk and every later
  attach (including other threads of the same process) skips
  straight to the cache. The first attach typically completes in
  well under a second on a normal dev box; sandboxed or
  IO-constrained environments may take a few seconds. Either way,
  don't time the very first run out aggressively.
- **Some hex-named workers fail brute-forcing.** A worker that has
  not yet served a request leaves `_tsrm_ls_cache` zeroed, so the
  search returns nothing and the call dies with `global symbol
  not found executor_globals`. Pick a different worker (or push
  some traffic through and retry) — once any one worker succeeds,
  the cached offset works for every other thread of the same
  process. `inspector:daemon` and `inspector:trace` retry
  internally; `inspector:memory:dump` and friends do not, so cold
  failures are most visible there.

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
parent PID. Use the hex-only filter to skip `php-main` (see the
[Attach to a single worker](#attach-to-a-single-worker) section
for why):

```bash
sudo php ./reli inspector:memory:dump -p "$(
    ps -L -p "$(pgrep -o frankenphp)" -o tid=,comm= |
        awk '$2 ~ /^php-[0-9a-f]+$/ {print $1; exit}'
)" \
    --php-regex='.*/libphp\.so$' \
    --libpthread-regex='.*/libc\.so.*' \
    -o ./frankenphp.relimem
```

Unlike `inspector:trace`, `inspector:memory:dump` does **not**
retry TLS resolution. If the chosen worker happens to be one that
never handled a request, the dump fails with `global symbol not
found executor_globals`. Recovery: rerun against a different TID,
or push a request through the server and retry. Running a brief
`inspector:trace` / `inspector:daemon` first populates the
TLS-offset cache and lets every subsequent worker TID succeed
immediately.

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
