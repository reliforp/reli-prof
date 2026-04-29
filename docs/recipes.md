# Recipes

Copy-paste shortcuts for the most common reli workflows. Each recipe
links out to the full reference doc when you want to understand the
options.

If a recipe assumes the Docker wrapper (`reli ...`), the equivalent
on a native checkout is `./reli ...`. See
[getting-started.md](getting-started.md) for installation.

## Profile a php-fpm pool for 60 seconds

Capture all worker processes at once, write a single `.rbt` per worker,
and stop after a minute:

```bash
mkdir -p ./traces

# Attach to every worker matching the regex for ~60s.
# The daemon writes one .rbt per *reli worker* (-T, default 8) into
# ./traces/, named worker_<reli-worker-pid>.rbt — not one per target PID.
timeout 60 reli inspector:daemon \
    -P "^php-fpm" \
    -f rbt -o ./traces/

# List what was written, then browse / report on one of them
ls ./traces/
# worker_12345.rbt  worker_12346.rbt  ...
reli rbt:explore ./traces/worker_12345.rbt
reli rbt:analyze < ./traces/worker_12345.rbt
```

References: [tracing/capturing-traces.md](tracing/capturing-traces.md),
[tracing/rbt-analyze-and-explore.md](tracing/rbt-analyze-and-explore.md).

## Investigate memory growth in a worker

Dump now (short stop), analyse offline, look at the prioritised report:

```bash
# 1. Take a fast binary dump (target stopped only briefly)
reli inspector:memory:dump --pid=<pid> --output=worker.rdump

# 2. Analyse the dump into a .rmem snapshot
reli inspector:memory:analyze worker.rdump -f rmem -o worker.rmem

# 3. Read the prioritised findings (dominant classes, cycles, choke points, …)
reli inspector:memory:report worker.rmem

# 4. (optional) Drop into the TUI to chase paths interactively
reli rmem:explore worker.rmem
```

To check whether a suspected leak is actually growing, capture a second
snapshot after letting the workload run, then diff:

```bash
reli inspector:memory:compare worker.rmem worker-later.rmem
```

References: [memory/memory-dump.md](memory/memory-dump.md),
[memory/memory-report.md](memory/memory-report.md),
[memory/rmem-explore-and-serve.md](memory/rmem-explore-and-serve.md).

## Capture `memory_limit` crashes with the sidecar

Run the sidecar as a separate process / container, register one line
in your application bootstrap, and reli will dump from outside at the
exact moment the application reports it's dying:

```bash
# Start the sidecar (foreground; wrap in systemd / supervisor / a
# Kubernetes sidecar container in real deployments).
# The sidecar refuses to bind unless the socket's parent directory
# is mode 0700 and owned by the current uid. On systemd hosts the
# default $XDG_RUNTIME_DIR/reli/sidecar.sock already satisfies this,
# so --socket can be omitted entirely.
reli inspector:sidecar --output-dir=/tmp/reli-dumps
# → [sidecar] Listening on /run/user/<uid>/reli/sidecar.sock

# If $XDG_RUNTIME_DIR is unavailable (root shells outside a user
# session, minimal containers), prepare a 0700 dir you own and pass
# --socket explicitly — see docs/monitoring/sidecar.md.
```

Install the FFI-free client package in your application (the
standalone client mirror; downgraded to PHP 7.0+ so it runs anywhere
your application does):

```bash
# Until the first tagged release, the package is dev-main only —
# requires "minimum-stability": "dev" + "prefer-stable": true in composer.json.
composer require reliforp/reli-prof-sidecar-client:dev-main
```

Other install paths (full `reliforp/reli-prof` or vendoring the three
client files) are documented in
[monitoring/sidecar.md § Client Library](monitoring/sidecar.md#client-library);
the standalone package is the recommended one.

```php
// In your application bootstrap (index.php / bin/console / …)
\Reli\Sidecar\Client\MemoryLimitHandler::register();
```

When the application hits `memory_limit`, you get a `.dump` file plus a
`.meta.json` with the call trace and memory stats in `--output-dir`.

Analyse the dump like any other reli memory snapshot:

```bash
reli inspector:memory:analyze /tmp/reli-dumps/sidecar-<pid>-<datetime>.dump \
    -f rmem -o crash.rmem
reli inspector:memory:report crash.rmem
```

References: [monitoring/sidecar.md](monitoring/sidecar.md) (including
client install, Docker / Kubernetes setup, and CI cross-release
comparison).

## Profile FrankenPHP workers

FrankenPHP loads PHP as `libphp.so` and runs many threads inside one
process — only the PHP worker threads carry executor globals. Three
flags do the work:

```bash
# Daemon mode: discover workers automatically, skip Caddy / Go threads
reli inspector:daemon \
    -P frankenphp \
    --target-thread-regex='^php-[0-9a-f]+$' \
    --php-regex='.*/libphp\.so$' \
    --libpthread-regex='.*/libc\.so.*' \
    -f rbt -o ./traces/
```

For attaching to a single worker (`inspector:trace`), pass the PHP
worker **TID** rather than the FrankenPHP parent PID. The pattern
below intentionally excludes `php-main` — that's the bootstrap
thread, not a worker, and attaching to it produces empty traces /
"failed to find ZendMM main chunk" on memory commands. See
[tracing/frankenphp.md](tracing/frankenphp.md) for the full story.

```bash
ps -L -p "$(pgrep -o frankenphp)" -o tid,comm | awk '$2 ~ /^php-[0-9a-f]+$/'
#   12345 php-1abc34de
#   12346 php-2bcd45ef
# (FrankenPHP names worker threads with a hex suffix; the regex above also
#  excludes `php-main`, the bootstrap thread.)

reli inspector:trace -p 12345 \
    --php-regex='.*/libphp\.so$' \
    --libpthread-regex='.*/libc\.so.*' \
    -o trace.rbt
```

Reference: [tracing/frankenphp.md](tracing/frankenphp.md).
