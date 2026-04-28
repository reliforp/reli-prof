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

The three CLI flags below match those realities. Trace and
daemon commands work the same way against both regular
(per-request) and [worker-mode](https://frankenphp.dev/docs/worker/)
FrankenPHP setups; the memory commands diverge between the two
shapes — see [Memory and watch commands](#memory-and-watch-commands).

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
- **Some hex-named workers fail brute-forcing on first attach.**
  Before the per-binary TLS-offset cache exists, reli locates
  `_tsrm_ls_cache` by scanning the target's TLS block for a
  recognisable pattern. On a worker that has not yet served a
  request that scan can come up empty, and the call dies with:

  ```
  executor_globals not found via TSRM on TID <n>. The binary is
  ZTS (PT_TLS present) but this thread's _tsrm_ls_cache slot is
  uninitialized -- typical of an idle FrankenPHP worker.
  ```

  Recovery: pick another worker, or push traffic through to warm
  one. A single sequential request usually only warms one worker
  (Caddy picks the first idle one and routes follow-on requests
  there too), so the TID you happened to grab from `ps` may stay
  cold. Concurrent load that saturates all workers — e.g. fire
  `num_threads`+ slow requests in parallel before attaching —
  warms every worker at once. Once **any one worker** succeeds
  and writes the offset to the per-binary cache, subsequent
  attaches against the same FrankenPHP process skip the
  brute-force scan and read the cached offset directly, but the
  attach still only succeeds against workers whose
  `_tsrm_ls_cache` slot has actually been populated. Whether
  idle (never-served-a-request) workers have a populated slot
  varies by FrankenPHP build: on some builds the slot is set at
  thread startup and the cached offset is enough, on others
  (observed on `dunglas/frankenphp:latest` with PHP 8.5.5) the
  slot stays zero until the worker handles its first request and
  cold attaches keep failing per-thread. If you hit the
  uninitialised-slot error against a thread that has never
  served a request, push traffic through that worker first
  regardless of cache state.

  TSRM resolving is not the same as having something to look at:
  `inspector:memory:dump` against a TSRM-resolved cold worker in
  regular mode still fails downstream with "failed to find ZendMM
  main chunk" because the request scope hasn't been set up — see
  the [Memory and watch commands](#memory-and-watch-commands)
  section. Worker mode keeps the request scope alive throughout
  the worker's lifetime and so doesn't have this second hurdle.

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

If the chosen worker has not yet served a request and the
per-binary TLS-offset cache is also still empty, the dump fails
with the `executor_globals not found via TSRM on TID <n>` error
described in the [single-worker section](#attach-to-a-single-worker).
Both `inspector:memory:dump` and `inspector:trace` fail at the
same point in this case — the `--max-retries` knob on
`inspector:trace` retries failed memory reads inside the sampling
loop, not the cold-attach TSRM resolution itself. Recovery: rerun
against a different TID, or saturate the workers with concurrent
traffic and retry. Running a brief `inspector:trace` /
`inspector:daemon` first populates the TLS-offset cache and lets
every subsequent attach against the same FrankenPHP process
resolve TSRM via the cache regardless of whether the chosen TID
itself has served a request.

### Memory commands and FrankenPHP modes

FrankenPHP runs in two modes and the memory commands behave very
differently in each.

[**Worker mode**](https://frankenphp.dev/docs/worker/) — `frankenphp { worker /app/worker.php }` —
loads the application script once per PHP thread and serves
requests in a `frankenphp_handle_request()` loop. That loop stays
on the PHP call stack between requests, so
`executor_globals.current_execute_data` is never zero and the
worker's request heap is mapped for the worker's whole lifetime.
For reli that means:

- `inspector:memory:dump` succeeds against an idle worker-mode
  worker the same way it succeeds against one mid-request, with
  the same dump size — there is no per-request scope to wait for.
- `inspector:trace` against an idle worker-mode worker shows a
  short 2-frame `frankenphp_handle_request` / `<main>` stack;
  the same TID mid-request shows the full handler stack on top.

This is reli's happy path on FrankenPHP — being able to introspect
a worker that has been serving traffic for a long time, without
having to coordinate with request timing or restart it, is what
the memory tooling is for. Symfony Runtime, Laravel Octane on
FrankenPHP, and similar high-perf runtimes all use worker mode by
default; if you're already running one of them you already have
the easy case.

**Regular (per-request) mode** — `php_server` without a `worker`
directive — behaves like every other SAPI: ZendMM's main chunk
only exists while a request is in flight, and between requests
the worker tears it down. In this mode `inspector:memory:dump`,
`inspector:memory`, and `inspector:watch -p <pid>` need the
worker to be **mid-request at the moment of attach**, with all
the timing considerations that implies (saturating workers with
a long-running page, racing the request boundary, etc.). For a
one-shot snapshot in regular mode, drive workers with a
long-running PHP page (e.g. one that does the work you want to
capture and then `usleep`s for a few seconds). For automated
capture, prefer the condition-driven workflows that fire while a
request is active by construction:

- [`inspector:watch`](capturing-traces.md) with `--memory-usage`,
  `--watch-function`, `--cpu-usage` etc. plus `--action=memory-dump`
  polls the target and only triggers when the condition holds, so
  it naturally lands inside a request.
- [`inspector:sidecar`](../monitoring/sidecar.md) takes the dump on
  request from the application (e.g. from a `memory_limit` shutdown
  handler), guaranteeing the call stack and heap are still set up.

CLI-style invocations (`frankenphp php-cli script.php`) keep the
worker in PHP for the whole script lifetime and avoid the regular-
mode timing issue the same way worker mode does.

### `inspector:daemon` against regular-mode FrankenPHP

`inspector:daemon` works against regular-mode FrankenPHP, but only
the moments when a worker is actually executing PHP produce samples.
Between requests there are no PHP frames to record and reli emits
nothing for those ticks — rbt and template output contain only real
frames. Idle intervals therefore appear as gaps in the sample stream,
not as zero-frame samples; if you need to attribute that gap to
"worker was idle" vs "reli was reattaching", enable
`--rbt-timestamps=delta` and cross-reference the timestamps with your
access logs / APM. Worker mode keeps the worker on the PHP call
stack continuously and so produces a dense, gap-free sample stream
without any of this.

To avoid losing samples to attach/detach churn around request
boundaries, the reader rides through up to ~200 idle ticks (≈ 2 s
at the default 10 ms `-s`; scales with `-s`) before releasing the
worker back to the dispatcher pool. After that it detaches and the
dispatcher reassigns it on its next searcher cycle. This only
matters when `-T` is smaller than the number of PHP threads; with
the default `-T 8` and typical FrankenPHP `num_threads` ≤ 8 every
TID has its own permanent reader.

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
