# inspector:sidecar — On-Demand Memory Dump Daemon

`inspector:sidecar` runs a daemon that listens on a Unix domain socket for memory dump requests from PHP processes. When a process needs a memory snapshot — for example, when hitting `memory_limit` — it sends a lightweight request over the socket, and the sidecar takes the dump from outside using `process_vm_readv`.

Unlike `inspector:watch` (polling-based monitoring), the sidecar responds to **explicit requests from the application itself**, so it captures snapshots at the exact moment the application needs them.

## Quick Start

**Start the sidecar.** When `$XDG_RUNTIME_DIR` is set (interactive
desktop / SSH login session under systemd), the default socket path
sits inside the per-user runtime dir, which is already mode 0700 — no
setup needed:

```bash
mkdir -p /tmp/reli-dumps
reli inspector:sidecar --output-dir=/tmp/reli-dumps
# → [sidecar] Listening on /run/user/<uid>/reli/sidecar.sock
```

If `$XDG_RUNTIME_DIR` isn't set (root shells outside a user session,
basic Docker / systemd-nspawn containers, sandboxed CI environments),
this command exits with `Cannot resolve default sidecar socket path`.
Pre-create a 0700 parent directory you own and pass `--socket`
explicitly — see the override block below — or set
`$RELI_SIDECAR_SOCKET` to the same path so the application-side
`MemoryLimitHandler` finds the daemon without further configuration.

**In your PHP application (one line in bootstrap):**

```php
\Reli\Sidecar\Client\MemoryLimitHandler::register();
```

When your application hits `memory_limit`, the handler automatically
requests a dump from the sidecar. The dump file and a `.meta.json` with
the call trace and memory stats are written to `--output-dir`.

> [!NOTE]
> The sidecar refuses to bind to a socket whose parent directory is not
> mode 0700, owned by the current uid, and not a symlink — without that
> guard a co-tenant could pre-create the socket path on a shared host
> like `/tmp` and intercept dump requests, since the IPC has no
> on-the-wire authentication. The default
> `$XDG_RUNTIME_DIR/reli/sidecar.sock` (typically
> `/run/user/<uid>/reli/sidecar.sock`) satisfies this. If you must
> override `--socket` on a host without `XDG_RUNTIME_DIR`, prepare a
> dedicated parent directory first, e.g.
>
> ```bash
> mkdir -p /var/run/reli && chmod 0700 /var/run/reli
> reli inspector:sidecar \
>   --socket=/var/run/reli/sidecar.sock \
>   --output-dir=/tmp/reli-dumps
> ```

## Requirements

See [Getting started § Requirements](../getting-started.md#requirements) for the
common runtime and target requirements.

Command-specific notes:

- The sidecar process must share the PID namespace with target processes
  (important for Docker / Kubernetes — see [§ Docker / Kubernetes Setup](#docker--kubernetes-setup)).

## Why Use a Sidecar?

When a PHP process hits `memory_limit`, the shutdown handler has very limited capabilities:

| Approach | FFI needed in app? | Call trace? | Memory dump? | Timing |
|----------|-------------------|-------------|-------------|--------|
| `error_get_last()` in shutdown handler | No | **No** (PHP ≤ 8.4) | No | At crash |
| exec reli from shutdown handler | **Yes** | Yes | Yes | At crash |
| `inspector:watch --memory-usage` | No | Yes | Yes | Polling (may miss) |
| **`inspector:sidecar`** | **No** | **Yes** | **Yes** | **At crash (exact)** |

The sidecar approach:
- **No FFI in the application** — the client library uses only `stream_socket_client` + `fwrite`
- **Exact timing** — the dump happens when the process reports it's dying, not on a poll interval
- **Call trace from outside** — reli reads `EG(current_execute_data)` via `process_vm_readv`, so it gets the full call stack even though `debug_backtrace()` fails in a `memory_limit` shutdown handler on PHP ≤ 8.4

## Client Library

The client library requires **no FFI** and has **no heavy dependencies**. It's designed to work in shutdown handlers with minimal memory overhead.

### Installing the client code in your application

The classes under `Reli\Sidecar\Client\` (`MemoryLimitHandler`,
`SidecarClient`, `SidecarClientResponse`) must be available in the
target application's autoloader. There are three practical options
today; the recommended one is the standalone client package because
it keeps the application's dependency graph free of FFI / PCNTL.

- **Composer, standalone client (recommended)** —
  [`reliforp/reli-prof-sidecar-client`](https://packagist.org/packages/reliforp/reli-prof-sidecar-client)
  on Packagist is a generated, FFI-free mirror of the
  `Reli\Sidecar\Client\` namespace, downgraded to target PHP 7.0+
  so it runs anywhere your application does:

  ```bash
  composer require reliforp/reli-prof-sidecar-client
  ```

  Until the first tagged release, the package is published as
  `dev-main` only. Pin it explicitly and allow dev stability:

  ```bash
  composer require reliforp/reli-prof-sidecar-client:dev-main
  ```

  with `"minimum-stability": "dev"` and `"prefer-stable": true` in
  `composer.json`. **Do not open PRs against the mirror repo** — its
  contents are regenerated from `src/Sidecar/Client/` in
  `reliforp/reli-prof` by the Rector-based downgrade pipeline.

- **Composer, full reli-prof** — strongly discouraged for
  application-only deploys. `reliforp/reli-prof`'s `composer.json`
  declares `php: ^8.4`, `ext-ffi`, `ext-pcntl` as hard requirements
  (they are needed by the `reli` CLI itself, not by the client
  classes), and a typical PHP-FPM application image has none of
  them. `composer require reliforp/reli-prof` therefore fails the
  platform check, and bypassing it means asserting at install
  time that the runtime constraints are someone else's problem:

  ```bash
  composer require reliforp/reli-prof \
      --ignore-platform-req=php \
      --ignore-platform-req=ext-ffi \
      --ignore-platform-req=ext-pcntl
  ```

  Even when this works, you still ship the entire reli analysis
  toolchain into the application image. Use this option only when
  the project ALSO runs `reli` CLI commands (analysis tooling, CI
  step, dev-only container). For application code, prefer the
  standalone client package above.

- **Vendoring the three files** — for applications that cannot or
  will not take a Composer dependency, copy
  `src/Sidecar/Client/MemoryLimitHandler.php`,
  `src/Sidecar/Client/SidecarClient.php`, and
  `src/Sidecar/Client/SidecarClientResponse.php` (plus
  `SocketPathResolver.php` for the default-path resolution) into
  your project and wire them into your autoloader (PSR-4 under the
  `Reli\Sidecar\Client\` namespace, or any namespace as long as you
  update the `use` statements).

Whichever option you pick, only the `Reli\Sidecar\Client\` namespace
is needed application-side; the rest of reli runs in the sidecar
process.

### Emergency Memory Reserve

When PHP hits `memory_limit`, the shutdown handler runs with almost no free memory — even `stream_socket_client()` can fail because it needs to allocate internal buffers. To handle this, `MemoryLimitHandler` pre-allocates a block of memory (default 256 KB) at `register()` time and releases it at the very start of the shutdown handler, freeing enough headroom for socket operations.

```php
// Default: 256 KB reserve
\Reli\Sidecar\Client\MemoryLimitHandler::register();

// Custom reserve size (e.g., 512 KB for applications with heavy shutdown hooks)
\Reli\Sidecar\Client\MemoryLimitHandler::register(reserve_bytes: 512 * 1024);
```

### Automatic memory_limit Handler

```php
// In your application bootstrap (e.g., index.php, bin/console)
\Reli\Sidecar\Client\MemoryLimitHandler::register();
```

With custom callbacks:

```php
use Reli\Sidecar\Client\SidecarClientResponse;

\Reli\Sidecar\Client\MemoryLimitHandler::register(
    socket_path: '/var/run/reli/sidecar.sock',
    on_response: function (SidecarClientResponse $r) {
        error_log("reli dump: {$r->path} ({$r->bytes} bytes)");
        foreach ($r->trace as $frame) {
            error_log("  {$frame}");
        }
    },
);
```

### Manual Dump Requests

```php
use Reli\Sidecar\Client\SidecarClient;

$client = new SidecarClient('/var/run/reli/sidecar.sock');
$response = $client->requestDump(
    pid: getmypid(),
    error_file: __FILE__,
    error_line: __LINE__,
);

if ($response !== null && $response->isOk()) {
    echo "Dump saved to: {$response->path}\n";
}
```

### CI / Benchmark Snapshots

```php
$client = new SidecarClient();

$client->snapshot('baseline');

loadFixtures();
$client->snapshot('after-fixtures');

runHeavyProcess();
$client->snapshot('after-processing');
```

### Socket Path Resolution

The socket path is resolved in this order:

1. Constructor argument (`$socket_path`)
2. `RELI_SIDECAR_SOCKET` environment variable
3. Default: `$XDG_RUNTIME_DIR/reli/sidecar.sock` (typically
   `/run/user/<uid>/reli/sidecar.sock` on systemd hosts). The
   resolver throws if `XDG_RUNTIME_DIR` is unset — there is no
   `/tmp` fallback because the path's parent directory must be
   `0700` and owned by the current uid for the sidecar to bind
   safely (see [§ Security model](#security-model)).

```bash
export RELI_SIDECAR_SOCKET=/var/run/reli/sidecar.sock
```

### Security model

The sidecar IPC has no on-the-wire authentication: anyone who can
`connect()` to the socket can request a dump of any PID in the same
PID namespace. Access control is therefore done entirely through
filesystem permissions on the socket and its parent directory.

`SocketPathResolver::assertParentSafe()` enforces that the parent
directory:

- is **owned by the current uid**,
- has mode **`0700`**, and
- is **not a symlink**.

If any of these fail the sidecar refuses to bind. This is the only
thing that prevents a local attacker on a shared host from racing the
sidecar to pre-create a socket at the same path and impersonate it.

A consequence is that **the sidecar and every client must run under
the same uid**, because a 0700 parent directory cannot be traversed
by other uids regardless of the socket file's own mode. The socket
itself is `chmod 0660` for forward-compatibility with a future
"shared group" mode, but with the current resolver that bit is
effectively unused.

For Docker / Kubernetes, the practical implication is to run the
sidecar container with the same `runAsUser` / `USER` as the
application container, or to mount the socket directory with
matching ownership; see [§ Docker / Kubernetes Setup](#docker--kubernetes-setup).

### Default Metadata

Pass `default_metadata` to the constructor to include key-value pairs in every request:

```php
$client = new SidecarClient(
    default_metadata: ['branch' => 'main', 'runner' => 'ci-1'],
);
$client->snapshot('baseline'); // metadata is automatically included
```

## Server Options

```
Usage:
  inspector:sidecar [options]

Options:
  -s, --socket=SOCKET              Unix domain socket path
                                   [default: $XDG_RUNTIME_DIR/reli/sidecar.sock]
  -o, --output-dir=OUTPUT-DIR      Directory for dump output files [default: .]
      --disk-usage-limit=LIMIT     Max total disk usage for dumps (e.g., 1G, 512M)
                                   [default: 1G]
      --include-binary             Include read-only binary segments in dumps
  -t, --tag=TAG                    Session-level tag applied to every snapshot
                                   (key=value, repeatable)
      --memory-limit=LIMIT         Set PHP memory_limit for the sidecar process
      --no-cache                   Disable the binary analysis cache
```

## Operations

The sidecar is a single PHP process: one server, one socket, no internal
parallelism. The notes below cover the things you need to size and
operate it sensibly under that model.

### Sizing `--memory-limit`

The dumper buffers every region the target exposes — the ZendMM heap,
opcache SHM, the VM stack and compiler arena, and (with
`--include-binary`) read-only binary segments — as PHP strings before
flushing the dump file, so the sidecar's peak resident memory during
a dump is roughly `everything the dump file ends up containing` plus
an `~80 MB` baseline. As a starting point, size `--memory-limit` (and
the matching cgroup / pod limit) at least to:

```
--memory-limit  ≥  max(target memory_limit across all clients)
                + opcache SHM (if opcache is enabled in targets)
                + 100 MB headroom
```

The sidecar pre-flights every request against this limit using the
target's heap size and refuses with a structured error like
`sidecar memory_limit too small for the target heap alone: need ~512
MiB, …` — the daemon stays up, only that one request fails. The
pre-flight only inspects the **heap**, so the dump can still OOM the
sidecar later if the non-heap regions push the working set over
`--memory-limit`; treat the check as a fast-fail for the obvious
case, not a guarantee. Size the limit conservatively (see the formula
above), and watch sidecar RSS in production until you have a real
upper bound for your workload.

### Run under a supervisor

The sidecar can still die from things outside its pre-flight check
(unexpected crashes, OOM kills from the cgroup, kernel signals, etc.).
Always run it under a supervisor that restarts it. A systemd unit
that creates its own runtime / state directories with the right
permissions:

```ini
# /etc/systemd/system/reli-sidecar.service
[Unit]
Description=reli-prof sidecar
After=network.target

[Service]
Type=simple
User=reli
Group=reli
# %t = runtime directory root (/run for system services).
# %S = state directory root   (/var/lib by default).
# RuntimeDirectory=reli creates /run/reli, and StateDirectory=reli
# creates /var/lib/reli, both with the User/Group above and the
# specified modes, so the sidecar's 0700 parent-dir check passes
# without any pre-create dance.
RuntimeDirectory=reli
RuntimeDirectoryMode=0700
StateDirectory=reli
StateDirectoryMode=0700
ExecStart=/usr/local/bin/reli inspector:sidecar \
  --socket=%t/reli/sidecar.sock \
  --output-dir=%S/reli \
  --memory-limit=2G
Restart=always
RestartSec=2s
StartLimitBurst=5
StartLimitIntervalSec=60

[Install]
WantedBy=multi-user.target
```

The application processes need to run as the same uid (see
[§ Security model](#security-model)) and read `RELI_SIDECAR_SOCKET`
from the environment, e.g.

```ini
# in the app unit
[Service]
User=reli
Environment=RELI_SIDECAR_SOCKET=/run/reli/sidecar.sock
```

Create the `reli` system user once at install time
(`useradd --system --no-create-home --shell /usr/sbin/nologin reli`),
or replace `User=reli` / `Group=reli` with `DynamicUser=yes` if no
non-systemd processes on the host need to share the uid.

In Kubernetes, the sidecar container's spec needs `restartPolicy: Always`
(the pod default) plus enough `resources.limits.memory` to cover the
`--memory-limit` value above; see [§ Docker / Kubernetes Setup](#docker--kubernetes-setup)
for the rest of the deployment shape.

### Client timeout sizing

`SidecarClient` blocks on the response read for the full dump duration
because the protocol delivers a single JSON reply at the end. Measured
dump throughput is roughly 100 MB/s (`process_vm_readv` + disk write),
so size `timeout_seconds` against the target's `memory_limit`:

| Target `memory_limit` | Dump time at ~100 MB/s | Recommended `timeout_seconds` |
|---|---|---|
| 128 M | ~1.3 s | 5  |
| 256 M | ~2.5 s | 10 |
| 512 M | ~5  s  | 15 |
| 1 G   | ~10 s  | 30 (default) |
| 2 G   | ~20 s  | 60 |

Do **not** lower the default to make the shutdown handler "feel
faster". Raise it if your `memory_limit` exceeds the value the default
covers. `MemoryLimitHandler::register(...)` accepts `timeout_seconds`
directly:

```php
\Reli\Sidecar\Client\MemoryLimitHandler::register(
    timeout_seconds: 60, // memory_limit ~ 2G
);
```

### Concurrency model and queue behaviour

Requests are served strictly first-in / first-out by a single worker.
While one dump is in flight, additional client connections sit in the
kernel's listen backlog (`SOMAXCONN`, typically 4096 on Linux) and
**block in `fgets()` on the client side until the daemon gets to
them**. The protocol does not expose queue depth, so a client cannot
distinguish "queued for 8 seconds" from "actively being dumped for 8
seconds".

Practical implications:

- If a burst of N clients arrives at once, the last client waits
  roughly N × *previous-dump duration* for its response. Size
  `timeout_seconds` accordingly when you expect bursts (e.g. many
  PHP-FPM workers OOM-ing at once).
- If a queued client times out and closes its socket, the request
  it already wrote to the kernel buffer is **still served** when
  its turn arrives; the sidecar produces the dump and writes the
  file to `--output-dir`. The client never sees the response, but
  the dump itself is recoverable — see § Orphan dump recovery.
- If the queued client's *target process* dies before the sidecar
  reaches its request (common when the trigger was an OOM
  shutdown and the supervisor took longer than expected), the
  sidecar replies with `process <pid> not found`. No work
  performed.

### Orphan dump recovery

A client can give up — typically because its `timeout_seconds`
expired, or the OOM-handler PHP process exited — while the sidecar is
still running its dump. When that happens the dump file is **already
fully written** to `--output-dir`; the only thing missing is the JSON
reply that would have told the client where it landed.

So:

- Successful dumps land at `--output-dir/sidecar-<pid>-<datetime>[-<label>].dump`
  with their `.meta.json` sibling regardless of whether the client
  was still listening.
- After a client `on_error` ("`failed to connect to reli sidecar`",
  null result, etc.), an operator can usually find the dump by
  matching `<pid>` in the output directory.
- The sidecar logs a single `sidecar client disconnected before reply`
  line per orphan, with the response path, so grep-based audits work
  without ploughing through PHP `Notice` output.

This is a useful safety net for OOM diagnosis: even if the client's
shutdown handler ran out of time, the snapshot you actually wanted is
still on disk.

## Session Tags

Tags set at server startup are automatically included in every snapshot's `.meta.json`. Use them to identify what software and version is being profiled:

```bash
reli inspector:sidecar \
  --tag product=my-app \
  --tag version=2.4.0 \
  --tag commit=$(git rev-parse --short HEAD) \
  --output-dir=/tmp/reli-dumps
```

Tags from three sources are merged (later wins on key conflict):

1. Server `--tag` options (session-level)
2. Client `default_metadata` (client-level)
3. Per-call `metadata` in `snapshot()` / `requestDump()` (call-level)

## Output Files

Each dump request produces two files:

### Dump File (`sidecar-<pid>-<datetime>[-<label>].dump`)

Binary memory dump in the same format as `inspector:memory:dump`. Can be analyzed with:

```bash
reli inspector:memory:analyze /tmp/dumps/sidecar-1234-20260403-120000-after-fixtures.dump \
  -f rmem -o result.rmem
```

`-f sqlite3 -o result.db` is also supported (`inspector:memory:report`
and `inspector:memory:compare` accept either format); `rmem:explore`
and friends require `.rmem`.

### Metadata File (`.meta.json`)

```json
{
    "pid": 1234,
    "timestamp": "2026-04-03T12:00:00+09:00",
    "trigger": "sidecar_request",
    "php_version": "v84",
    "memory_stats": {
        "memory_usage": 52428800,
        "memory_real_usage": 67108864,
        "memory_peak_usage": 67108864,
        "memory_limit": 134217728,
        "rss": 89128960
    },
    "call_trace": [
        "App\\Service\\HeavyProcessor::process /app/src/Service/HeavyProcessor.php:142",
        "App\\Controller\\ApiController::handle /app/src/Controller/ApiController.php:58"
    ],
    "label": "after-fixtures",
    "metadata": {
        "product": "my-app",
        "version": "2.4.0",
        "commit": "abc123"
    }
}
```

When triggered by a `memory_limit` error, the file and line are also included:

```json
{
    "memory_limit_error_file": "/app/src/Service/HeavyProcessor.php",
    "memory_limit_error_line": 142
}
```

This information can be passed to `inspector:memory:analyze` with `--memory-limit-error-file` and `--memory-limit-error-line` for targeted analysis.

## Docker / Kubernetes Setup

> [!CAUTION]
> The sidecar must share the PID namespace with the target processes. Without this, `process_vm_readv` cannot read the target's memory.

> [!IMPORTANT]
> The sidecar and the application **must run as the same uid**. The
> sidecar refuses to bind unless the socket's parent directory is mode
> `0700` and owned by the current uid (see [§ Security model](#security-model)),
> and a `0700` directory cannot be traversed from a different uid even
> if the volume is shared. Either set both containers to the same
> `runAsUser` / `USER`, or run a single uid for the whole pod. Running
> the sidecar as root and the app as a non-root user (or vice versa)
> will not work.

### docker compose

```yaml
services:
  # One-shot helper: prepare /var/run/reli with the right ownership +
  # mode 0700 *before* either app or sidecar starts. Without it the
  # sidecar refuses to bind (parent dir must be 0700 + owned by uid 1000)
  # and the app cannot traverse into the volume.
  reli-sock-init:
    image: busybox:1
    command: >
      sh -c "mkdir -p /var/run/reli && chmod 0700 /var/run/reli
             && chown 1000:1000 /var/run/reli"
    user: "0:0"  # root so the chown works
    volumes:
      - reli-sock:/var/run/reli

  app:
    image: my-php-app
    user: "1000:1000"
    depends_on:
      reli-sock-init:
        condition: service_completed_successfully
    environment:
      RELI_SIDECAR_SOCKET: /var/run/reli/sidecar.sock
    volumes:
      - reli-sock:/var/run/reli
      - reli-dumps:/tmp/reli-dumps

  reli-sidecar:
    image: reliforp/reli-prof
    user: "1000:1000"  # must match `app` so the 0700 parent dir works
    command: >
      inspector:sidecar
        --socket=/var/run/reli/sidecar.sock
        --output-dir=/tmp/reli-dumps
        --memory-limit=2G
        --tag product=my-app
    depends_on:
      reli-sock-init:
        condition: service_completed_successfully
    pid: "service:app"
    cap_add:
      - SYS_PTRACE
    security_opt:
      - apparmor:unconfined
    volumes:
      - reli-sock:/var/run/reli
      - reli-dumps:/tmp/reli-dumps

volumes:
  reli-sock:
  reli-dumps:
```

If your application image already pins `USER 1000` in the Dockerfile,
drop the `user:` line on `app`; the `reli-sidecar` line still has to
match. To run everything as root instead, drop both `user:` lines and
the `reli-sock-init` `chown` step — it just needs to be consistent
across all three.

### Kubernetes (sidecar container)

```yaml
spec:
  shareProcessNamespace: true
  securityContext:
    runAsUser: 1000
    runAsGroup: 1000
    fsGroup: 1000
  initContainers:
    # Ensure the shared socket dir is 0700 before the sidecar tries to
    # bind. fsGroup gives 1000 write access to the emptyDir, but the
    # sidecar's parent-dir checks require mode 0700 specifically.
    - name: reli-sock-init
      image: busybox:1
      command: ["sh", "-c", "chmod 0700 /var/run/reli"]
      volumeMounts:
        - name: reli-sock
          mountPath: /var/run/reli
  containers:
    - name: app
      image: my-php-app
      env:
        - name: RELI_SIDECAR_SOCKET
          value: /var/run/reli/sidecar.sock
      volumeMounts:
        - name: reli-sock
          mountPath: /var/run/reli
    - name: reli-sidecar
      image: reliforp/reli-prof
      args:
        - inspector:sidecar
        - --socket=/var/run/reli/sidecar.sock
        - --output-dir=/tmp/reli-dumps
        - --memory-limit=2G
      securityContext:
        capabilities:
          add: ["SYS_PTRACE"]
      volumeMounts:
        - name: reli-sock
          mountPath: /var/run/reli
        - name: reli-dumps
          mountPath: /tmp/reli-dumps
  volumes:
    - name: reli-sock
      emptyDir: {}
    - name: reli-dumps
      emptyDir: {}
```

The pod-level `securityContext.runAsUser` ensures both containers
share uid `1000`; `fsGroup` makes the `emptyDir` volumes writable by
that uid. The init container narrows the socket directory to mode
`0700`, which is what the sidecar's parent-dir check requires.

## CI Workflow: Cross-Release Memory Comparison

The sidecar enables CI workflows that capture memory snapshots at specific points in a benchmark, then compare across releases to detect regressions.

### Pipeline Overview

```
v2.3.0 release                         v2.4.0 PR
┌─────────────────────┐                ┌─────────────────────┐
│ sidecar + benchmark  │                │ sidecar + benchmark  │
│ → snapshot(baseline) │                │ → snapshot(baseline) │
│ → snapshot(loaded)   │                │ → snapshot(loaded)   │
│ → analyze → v2.3.rmem│                │ → analyze → v2.4.rmem│
│ → upload artifact    │                │ → download v2.3.rmem │
└─────────────────────┘                │ → compare → pass/fail│
                                        └─────────────────────┘
```

### GitHub Actions Example

```yaml
name: Memory Regression Check
on: [pull_request]

jobs:
  memory-check:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      # Download baseline from the previous release
      - uses: actions/download-artifact@v4
        with:
          name: memory-baseline
          path: baseline/
        continue-on-error: true

      # Prepare a 0700 parent dir for the sidecar socket. GitHub Actions
      # runners do not set XDG_RUNTIME_DIR, so we have to pick the path
      # explicitly and lock down its parent.
      - name: Prepare runtime dir
        run: mkdir -p /tmp/reli-run && chmod 0700 /tmp/reli-run

      # Start sidecar
      - name: Start sidecar
        run: |
          reli inspector:sidecar \
            --socket=/tmp/reli-run/sidecar.sock \
            --output-dir=/tmp/dumps \
            --tag version=${{ github.head_ref }} \
            --tag commit=${{ github.sha }} &

      # Run benchmark
      - name: Run benchmark
        env:
          RELI_SIDECAR_SOCKET: /tmp/reli-run/sidecar.sock
        run: php bench/memory_trend.php

      # Analyze dumps. Either .rmem or SQLite is fine — both are
      # accepted by inspector:memory:compare. .rmem is the fastest
      # default and is what the rest of the docs use; switch to
      # `-f sqlite3 -o ....db` if your CI also runs ad-hoc SQL queries
      # against the snapshots.
      - name: Analyze snapshots
        run: |
          mkdir -p /tmp/analyzed
          for f in /tmp/dumps/sidecar-*.dump; do
            reli inspector:memory:analyze "$f" \
              -f rmem \
              -o "/tmp/analyzed/$(basename "$f" .dump).rmem"
          done

      # Compare with baseline (if available). inspector:memory:compare
      # takes one baseline + one target snapshot, so iterate over the
      # labels (baseline, after-fixtures, …) and pair each one up.
      - name: Compare with baseline
        if: hashFiles('baseline/*.rmem') != ''
        run: |
          for target in /tmp/analyzed/sidecar-*.rmem; do
            label="$(basename "$target" .rmem | sed -E 's/^sidecar-[0-9]+-[0-9-]+(-(.+))?$/\2/')"
            baseline="$(ls baseline/sidecar-*-"${label:-baseline}".rmem 2>/dev/null | head -n1)"
            [ -n "$baseline" ] || { echo "no baseline for ${label:-baseline}"; continue; }
            echo "=== ${label:-baseline} ==="
            reli inspector:memory:compare "$baseline" "$target" --threshold 5
          done

      # Save current results as new baseline
      - uses: actions/upload-artifact@v4
        with:
          name: memory-baseline
          path: /tmp/analyzed/
```

### Benchmark Script

```php
<?php
// bench/memory_trend.php
use Reli\Sidecar\Client\SidecarClient;

$client = new SidecarClient();

// Initial state
$client->snapshot('baseline');

// Simulate workload
loadFixtures();
$client->snapshot('after-fixtures');

processOrders();
$client->snapshot('after-orders');

generateReport();
$client->snapshot('after-report');
```

## IPC Protocol

The sidecar uses a simple newline-delimited JSON protocol over the Unix domain socket.

**Request:**

```json
{"command": "dump", "pid": 1234, "file": "/app/Foo.php", "line": 42, "label": "my-label", "metadata": {"key": "value"}}
```

Only `command` and `pid` are required. All other fields are optional.

**Response:**

```json
{"protocol_version": 1, "status": "ok", "path": "/tmp/dumps/sidecar-1234-20260403-120000.dump", "bytes": 52428800, "trace": ["..."], "memory_stats": {"memory_usage": 52428800, "rss": 89128960}}
```

```json
{"protocol_version": 1, "status": "error", "message": "process 1234 not found"}
```

### Protocol Versioning

Every response includes `protocol_version` (integer). The compatibility contract:

- **Additive changes** (new optional fields) do **not** bump the version. Both sides ignore unknown JSON keys.
- **Breaking changes** (removed/renamed fields, semantic changes) **must** bump the version.

Clients can check `$response->isCompatible()` to detect whether the server's version exceeds what the client understands. A response without `protocol_version` (from a pre-versioning server) is treated as compatible.

## Relationship with Other Commands

| Command | Use Case | Trigger |
|---------|----------|---------|
| `inspector:memory:dump` | One-shot dump of a known process | Manual (CLI) |
| `inspector:watch` | Passive monitoring with threshold triggers | Polling (automatic) |
| **`inspector:sidecar`** | **Application-initiated dump on error/at specific points** | **On-demand (IPC)** |
| `inspector:memory:analyze` | Offline analysis of dump files | Post-hoc (CLI) |
| `inspector:memory:compare` | Diff two analysis snapshots | Post-hoc (CLI) |
