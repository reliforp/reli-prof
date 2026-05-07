# Diagnosing apps that move to long-running PHP application servers

Migrating a PHP application from PHP-FPM (or `php` CLI per request) to a
long-running runtime — **FrankenPHP worker mode**, **RoadRunner**,
**Laravel Octane**, **Symfony Runtime**, **Swoole**,
**ReactPHP / AMPHP** — usually delivers a large latency / throughput win,
but the change in process model is what bites. Bugs that PHP-FPM hid by
tearing every request down to a fresh process suddenly become memory
leaks, state bleed, or resource exhaustion.

This guide is a checklist of what typically goes wrong, plus a mapping
from each symptom to the reli command that finds it. It is runtime-
agnostic; runtime-specific notes (attach flags, thread regex, mode
caveats) are at the bottom and link out to dedicated docs.

If you only want copy-paste workflows, jump to
[Diagnostic playbook](#diagnostic-playbook).

## Why long-running servers behave differently

PHP-FPM (and `php-cli` per invocation) gives you a strong implicit
guarantee: at the end of every request, **the entire PHP heap is freed**.
ZendMM tears down request-scoped allocations, `register_shutdown_function`
runs, statics in user-space are reset by process recycling
(`pm.max_requests`), and any leak that takes more than one request to
matter is invisible to ordinary testing.

Long-running runtimes break that invariant in two ways, and most
migration bugs fall into one of them:

1. **The PHP process keeps running across requests.** Static properties,
   global variables, singletons, in-memory caches, observers, event
   listeners, and registered shutdown handlers persist. Anything that
   "just leaked a little per request" now grows linearly with traffic.
2. **The request scope is no longer the process scope.** Code that
   assumed `$_SERVER`, the DI container, the current user, the request
   ID, or open DB transactions would be reset between requests has to be
   reset explicitly. If a singleton captures a request-scoped object
   (current request, current user, current tenant), every subsequent
   request sees stale state.

Different runtimes draw the line slightly differently:

- **FrankenPHP worker mode**, **Laravel Octane**, **Symfony Runtime**:
  the application script is loaded once per worker thread / process and
  serves requests in an in-process loop. ZendMM's request scope is
  re-opened per request inside the same thread.
- **RoadRunner**, **Swoole HTTP server**, **ReactPHP / AMPHP**:
  PHP workers are normal CLI processes that loop on a request channel
  (stdin / Swoole event loop / coroutines). No ZTS in the RoadRunner
  case; the worker's heap is the worker's whole-process heap.
- **FrankenPHP regular (per-request) mode**: like PHP-FPM in heap
  behaviour — the worker is long-lived but ZendMM's main chunk is
  request-scoped. State *between* requests still bleeds the same way
  worker mode does, but `inspector:memory:dump` requires mid-request
  timing. See [tracing/frankenphp.md § Memory commands and FrankenPHP modes](tracing/frankenphp.md#memory-commands-and-frankenphp-modes).

The diagnostic strategies below assume the worker stays in PHP between
requests (the common case). The "regular mode" caveat only changes
*when* you can capture; the *what to look for* is identical.

## What typically goes wrong

A taxonomy of the bugs that almost always surface during this kind of
migration. For each one there is a reli command (or workflow) that
makes it concrete.

### 1. Memory grows unbounded across requests

The single most common migration bug. Common sources:

- **Static properties / singletons** holding per-request data
  (`User::$current`, a logger that buffers context, a metrics collector
  with a request-keyed map).
- **DI container state** — services that should be transient are
  registered as shared, or the container holds request-scoped factories.
- **Event dispatchers / observers / listeners** registering anew every
  request and never deregistering.
- **In-memory caches without an eviction policy** (`Doctrine` array
  cache, hand-rolled `static $memo = []`, ORM identity maps).
- **Object cycles that the cycle collector never reaches** because the
  cycle collector budget is small and the worker doesn't idle long
  enough between requests for a full pass.

Diagnose with:

```bash
# 1. Warm the worker (drive ~100 requests through the workload you care about)
# 2. Snapshot
sudo ./reli inspector:memory:dump -p <worker-pid> -o warm.rdump
sudo ./reli inspector:memory:analyze warm.rdump -f rmem -o warm.rmem

# 3. Drive another N requests of the same workload, then snapshot again
sudo ./reli inspector:memory:dump -p <worker-pid> -o later.rdump
sudo ./reli inspector:memory:analyze later.rdump -f rmem -o later.rmem

# 4. Diff
./reli inspector:memory:compare warm.rmem later.rmem
```

What to look for in the diff: classes whose instance count grew with
request count (a leak), or whose total bytes grew without instance
count (a buffer that keeps appending). The dominant-class section of
`inspector:memory:report` against `later.rmem` alone is also a good
quick read — leaks that survive long enough show up at the top.

For an automated capture at the moment a worker crosses a memory
threshold or starts growing too fast, use `inspector:watch`:

```bash
# Trip when heap usage crosses 256 MB
sudo ./reli inspector:watch -p <worker-pid> \
    --memory-usage=256M --action=memory-dump --output-dir=./dumps

# Trip when growth rate alone crosses 10 MB/min — catches slow leaks
# before they hit memory_limit
sudo ./reli inspector:watch -p <worker-pid> \
    --memory-growth-rate=10M/min --action=memory-dump --output-dir=./dumps
```

`--rss-usage` is the right knob if the leak is **outside** the Zend
heap (FFI buffers, mmap, native extensions). Full reference:
[monitoring/watch-command.md](monitoring/watch-command.md).

### 2. Cross-request state bleed (no growth, but wrong answers)

Static or singleton state that *replaces itself* every request — so
memory doesn't grow — but is request-scoped data sitting in
process-scoped storage. Symptoms:

- User A occasionally sees user B's data.
- The first request sets a locale / tenant / log context that "sticks".
- A failed transaction's connection state persists into the next
  request.

This kind of bug is invisible to memory diff (no growth), but
`inspector:peek-var` confirms it directly. Read the suspect static
between requests:

```bash
# Read a class static / singleton holder
sudo ./reli inspector:peek-var -p <worker-pid> \
    --var='global::$container' \
    --depth=4
```

For hard-to-reach state, attach a variable to *every* sample of a trace
so you can correlate request boundaries to suspect state:

```bash
sudo ./reli inspector:trace -p <worker-pid> \
    --trace-var='global::$currentTenant->id' \
    -o trace.rbt
```

Reference: [inspection/peek-var-command.md](inspection/peek-var-command.md),
[inspection/trace-var-command.md](inspection/trace-var-command.md).

### 3. File / DB / FFI / stream resource leaks

Per-request `fopen()`, PDO connections opened on demand, stream
contexts attached to long-lived strings — anything that PHP-FPM cleaned
up by exiting the process. Symptoms:

- `Too many open files` after a few hours.
- Database connection count rises with no corresponding traffic spike.
- `inspector:memory:report` flags a growing `resource` /
  `Pdo*Statement` / stream class.

Detect with:

```bash
# OS-level: file descriptor count over time
ls /proc/<worker-pid>/fd | wc -l

# Heap-level: which class is dominating?
./reli inspector:memory:report later.rmem | head -40
```

If the resource is wrapped in a PHP object (PDO statement, Guzzle
client, Symfony HttpClient response), the dominant-classes section of
the report names the offender. If it's a raw `resource` (file handle,
socket), the OS counter rises but the heap stays flat — that's the
clue.

### 4. Bootstrap-once code running per request (or vice versa)

Per-request code that PHP-FPM happened to make cheap (parsing routes
from disk, building the DI container, rebuilding a regex cache) is now
in the **hot per-request path** instead of the bootstrap path.
Conversely, code that was supposed to run per request (resetting the
container, clearing identity maps, clearing the request stack) only
runs at bootstrap — and silently breaks request 2.

Detect with sampling. After warming the worker, the per-request hot
path should show your **handler**, not framework setup:

```bash
sudo ./reli inspector:trace -p <worker-pid> -o hot.rbt
./reli rbt:analyze --top=20 < hot.rbt
```

If `Container::resolve`, `RouteParser::parse`, autoload, or
`require_once` of vendor files dominates a *warm* worker, that's
bootstrap work that escaped into per-request. The fix is usually to
move the result into a worker-scoped cache; the diagnosis is the
trace.

To check what an **idle** worker is spending time on (it should be
trivial), trace it between request bursts:

```bash
sudo ./reli inspector:trace -p <worker-pid> -o idle.rbt
# An idle FrankenPHP worker shows ~2 frames (frankenphp_handle_request / <main>);
# anything else — a sleep, a poll, a stuck cleanup — is suspicious.
```

### 5. First-request latency spike (the "warm-up cliff")

The first request after process start (or after `opcache.preload`) is
much slower than steady-state, because autoload, route compilation,
ORM metadata, and JIT haven't warmed yet. This is expected; what's
**not** expected is having that warm-up cliff happen *every N
requests*, which means something is being torn down and rebuilt.

Pin it down by tracing across the warm-up window with
`--rbt-timestamps=delta` and looking for the second cliff:

```bash
sudo ./reli inspector:trace -p <worker-pid> \
    --rbt-timestamps=delta -o startup.rbt
# Drive request 1, request 2, ... wait for the cliff to recur, then stop.
./reli rbt:explore startup.rbt   # press 't' to enable timeline view
```

If a slow path *only* fires every M-th request, look for
`max_requests` recycling, opcache flushes, JIT tier resets, or
container rebuilds.

### 6. Crashes near `memory_limit`

`memory_limit` reached in PHP-FPM kills one worker; the next request
starts fresh. In a long-running runtime it kills a worker that may
hold dozens of in-flight requests' worth of warmed state, and the
recovery is much more visible. You also lose the usual "just look at
the last response" debugging — by the time you notice, the worker has
respawned.

Use the **sidecar** to capture a memory dump *at the moment the
worker reports it's dying*, from the application itself:

```bash
# Run as a separate process / container (full setup in monitoring/sidecar.md)
sudo ./reli inspector:sidecar --output-dir=/tmp/reli-dumps
```

```php
// In your worker bootstrap (once per process, BEFORE serving requests):
\Reli\Sidecar\Client\MemoryLimitHandler::register();
```

The sidecar then dumps the heap (and the call trace, and `memory_get_*`
stats) the instant the application's `memory_limit` shutdown handler
fires — independent of whether the runtime itself respawns the worker.
Reference: [monitoring/sidecar.md](monitoring/sidecar.md),
[recipes.md § `memory_limit` crashes](recipes.md#capture-memory_limit-crashes-with-the-sidecar).

## Diagnostic playbook

The order to walk when an app behaves differently after a long-running
migration:

### Step 1 — Confirm whether it's a leak or state bleed

```bash
# Watch RSS over a few minutes of representative traffic
while sleep 30; do
    awk '/VmRSS/ {print strftime("%H:%M:%S"), $2, $3}' /proc/<worker-pid>/status
done
```

- RSS climbs monotonically → **leak** (jump to step 2).
- RSS flat but behaviour wrong → **state bleed** (jump to step 3).
- RSS climbs in steps, plateaus, climbs again → bounded cache that
  keeps re-filling per tenant / per request shape → step 2 still
  applies; the diff will name the cache.

### Step 2 — Find the leaking class with a snapshot diff

```bash
# Warm
curl ...                # drive ~100 of your representative requests
sudo ./reli inspector:memory:dump -p <pid> -o warm.rdump
sudo ./reli inspector:memory:analyze warm.rdump -f rmem -o warm.rmem

# Soak
ab -n 5000 -c 8 ...     # or whatever your load tool is
sudo ./reli inspector:memory:dump -p <pid> -o soak.rdump
sudo ./reli inspector:memory:analyze soak.rdump -f rmem -o soak.rmem

./reli inspector:memory:compare warm.rmem soak.rmem
./reli inspector:memory:report soak.rmem
```

Read the diff bottom-up: the class with the biggest **instance**
delta is usually the leak; the class with the biggest **bytes**
delta but flat instance count is usually a buffer / collection
on a singleton.

For tracking down *who holds* the offending instance, drop into
the TUI and follow predecessors:

```bash
./reli rmem:explore soak.rmem
# Find the class → press 'p' to walk back to GC roots
```

Reference: [memory/rmem-explore-and-serve.md](memory/rmem-explore-and-serve.md).

### Step 3 — Confirm cross-request state bleed

`inspector:peek-var` reads any global / static / class-static between
requests. Drive one request, peek, drive another, peek again:

```bash
# Static property on a singleton
sudo ./reli inspector:peek-var -p <worker-pid> \
    --var='global::$container' --depth=5

# Repeated polling
sudo ./reli inspector:peek-var -p <worker-pid> \
    --var='global::$currentUser->id' \
    --repeat=200 --interval-ms=50
```

If the value persists across the request boundary when it shouldn't,
that's the bug. Reference:
[inspection/peek-var-command.md](inspection/peek-var-command.md).

### Step 4 — Catch the failure event automatically

For intermittent leaks where steps 2–3 don't reproduce on demand,
arm `inspector:watch` and walk away:

```bash
# Auto-dump when a worker is about to OOM
sudo ./reli inspector:watch -p <worker-pid> \
    --memory-usage=$(($PHP_MEMORY_LIMIT * 80 / 100))M \
    --action=memory-dump --output-dir=./dumps \
    --max-triggers-per-hour=4 --cooldown=60s

# Or arm growth-rate detection across a whole pool
sudo ./reli inspector:watch \
    --target-regex='^php-[0-9a-f]+$' \
    --memory-growth-rate=20M/min \
    --action=memory-dump --output-dir=./dumps
```

Then `inspector:memory:analyze` + `inspector:memory:report` on each
captured dump — you have one snapshot per offending worker, taken
right at the failure point. Reference:
[monitoring/watch-command.md](monitoring/watch-command.md).

### Step 5 — Validate the fix

Capture a baseline before the fix, deploy, capture after, diff:

```bash
./reli inspector:memory:compare before-fix.rmem after-fix.rmem
```

Negative deltas on the previously-leaking class is the success
condition. Use the same snapshots later as the regression baseline
for CI; `inspector:memory:compare` produces JSON
(`-f report-json`) suitable for asserting against in a build.

## Runtime-specific notes

### FrankenPHP (worker mode and regular mode)

Three required flags (`--php-regex='.*/libphp\.so$'`,
`--libpthread-regex='.*/libc\.so.*'`,
`--target-thread-regex='^php-[0-9a-f]+$'` for daemon / top / watch),
plus the worker-vs-regular-mode caveat for memory commands. Full
walkthrough: [tracing/frankenphp.md](tracing/frankenphp.md).

The diagnostic playbook above maps onto worker mode without
modification — `inspector:memory:dump` against an idle worker-mode
worker succeeds the same as mid-request. In **regular mode** memory
commands need the worker mid-request; prefer `inspector:watch` or
`inspector:sidecar` over manual `inspector:memory:dump` there.

### RoadRunner

PHP workers are ordinary CLI processes (no ZTS, no shared library);
default `--php-regex` matches them. Find worker PIDs with
`pgrep -f 'rr-php\|roadrunner'` or whatever your launcher names them
as, and pass the PID directly:

```bash
sudo ./reli inspector:trace -p <worker-pid> -o trace.rbt
sudo ./reli inspector:memory:dump -p <worker-pid> -o snap.rdump
```

Workers serve many requests in a loop; the heap is the whole-process
heap. The "warm vs soak" diff in step 2 of the playbook is the
canonical workflow. RoadRunner can recycle workers on a request count
or memory limit (`max_jobs`, `max_memory` in the `.rr.yaml`); if a
leak only shows up between recycles, raise those limits temporarily
while diagnosing or you'll keep losing the worker mid-investigation.

### Laravel Octane

- **FrankenPHP backend** — same as the FrankenPHP section above.
- **Swoole / OpenSwoole backend** — ZTS-style runtime; multiple PHP
  worker threads inside one process, similar shape to FrankenPHP
  worker mode. Match the worker threads with `--target-thread-regex`
  and use `--php-regex` / `--libpthread-regex` if PHP is loaded as
  a shared library; otherwise default flags work.
- **RoadRunner backend** — see the RoadRunner section.

Octane-specific bleed paths to look for: the **`OctaneServiceProvider`
flush list**, the request-scoped `Auth` / `Session` / `Translator`
managers (these *are* explicitly reset by Octane, but custom services
registered as singletons that wrap them are not), and any
`Container::singleton` registration that captures `Request` /
`Response`.

### Symfony Runtime / Symfony with FrankenPHP

The runtime component resets the kernel between requests, but only
for services it knows about. Custom singletons (private services that
hold a reference to the request stack, the current user, the current
firewall) leak the same way as in any other long-running runtime.
Step 3 of the playbook (peek-var on the suspect service) is the
fastest confirmation.

### Swoole HTTP server (without Octane)

Same shape as the Octane-on-Swoole case; coroutines complicate things
because the "current request" can switch on every I/O point. Static
state that's safe in a worker-mode runtime can race in a coroutine
runtime. `inspector:trace --trace-var` of a coroutine-local
identifier is the right tool for tracking which sample belongs to
which logical request.

### ReactPHP / AMPHP

Single-threaded event loop, multiple in-flight requests via promises
/ fibres. Unbounded promise chains, listener registrations on global
emitters, and never-resolved deferreds are the typical leaks. Step 2
of the playbook applies; expect to see `Closure` and
`React\Promise\*` instances dominating the diff.

## See also

- [recipes.md](recipes.md) — copy-paste workflows for the common
  capture / analysis commands
- [memory/memory-dump.md](memory/memory-dump.md),
  [memory/memory-report.md](memory/memory-report.md),
  [memory/rmem-explore-and-serve.md](memory/rmem-explore-and-serve.md)
  — memory capture and analysis
- [monitoring/watch-command.md](monitoring/watch-command.md),
  [monitoring/sidecar.md](monitoring/sidecar.md) — automated capture
- [inspection/peek-var-command.md](inspection/peek-var-command.md),
  [inspection/trace-var-command.md](inspection/trace-var-command.md)
  — variable inspection
- [tracing/frankenphp.md](tracing/frankenphp.md) — FrankenPHP-specific
  attach flags and worker-vs-regular-mode caveat
- [troubleshooting.md](troubleshooting.md) — generic attach failures
