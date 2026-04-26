# inspector:sidecar — On-Demand Memory Dump Daemon

`inspector:sidecar` runs a daemon that listens on a Unix domain socket for memory dump requests from PHP processes. When a process needs a memory snapshot — for example, when hitting `memory_limit` — it sends a lightweight request over the socket, and the sidecar takes the dump from outside using `process_vm_readv`.

Unlike `inspector:watch` (polling-based monitoring), the sidecar responds to **explicit requests from the application itself**, so it captures snapshots at the exact moment the application needs them.

## Quick Start

**Start the sidecar:**

```bash
reli inspector:sidecar \
  --socket=/tmp/reli-sidecar.sock \
  --output-dir=/tmp/reli-dumps
```

**In your PHP application (one line in bootstrap):**

```php
\Reli\Sidecar\Client\MemoryLimitHandler::register();
```

When your application hits `memory_limit`, the handler automatically requests a dump from the sidecar. The dump file and a `.meta.json` with the call trace and memory stats are written to `--output-dir`.

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

The classes under `Reli\Sidecar\Client\` (`MemoryLimitHandler`, `SidecarClient`, `SidecarClientResponse`) must be available in the target application's autoloader. There are two practical options today:

- **Composer dependency** — add `reliforp/reli-prof` as a dependency
  in the application (`composer require reliforp/reli-prof`). The
  client classes are pulled in along with the rest of the package.
  The client side has no FFI / PCNTL requirement, so this works on
  any modern PHP runtime that hosts your application.
- **Vendoring the three files** — for applications that don't want
  the full dependency, copy `src/Sidecar/Client/MemoryLimitHandler.php`,
  `src/Sidecar/Client/SidecarClient.php`, and
  `src/Sidecar/Client/SidecarClientResponse.php` into your project
  and wire them into your autoloader (PSR-4 under the
  `Reli\Sidecar\Client\` namespace, or any namespace as long as you
  update the `use` statements).

Either way, only the `Reli\Sidecar\Client\` namespace is needed
application-side; the rest of reli runs in the sidecar process.

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
    socket_path: '/tmp/reli-sidecar.sock',
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

$client = new SidecarClient('/tmp/reli-sidecar.sock');
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
3. Default: `/var/run/reli-sidecar.sock`

```bash
export RELI_SIDECAR_SOCKET=/tmp/reli-sidecar.sock
```

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
                                   [default: /var/run/reli-sidecar.sock]
  -o, --output-dir=OUTPUT-DIR      Directory for dump output files [default: .]
      --disk-usage-limit=LIMIT     Max total disk usage for dumps (e.g., 1G, 512M)
                                   [default: 1G]
      --include-binary             Include read-only binary segments in dumps
  -t, --tag=TAG                    Session-level tag applied to every snapshot
                                   (key=value, repeatable)
      --memory-limit=LIMIT         Set PHP memory_limit for the sidecar process
      --no-cache                   Disable the binary analysis cache
```

## Session Tags

Tags set at server startup are automatically included in every snapshot's `.meta.json`. Use them to identify what software and version is being profiled:

```bash
reli inspector:sidecar \
  --tag product=my-app \
  --tag version=2.4.0 \
  --tag commit=$(git rev-parse --short HEAD) \
  --socket=/tmp/reli.sock \
  --output-dir=/tmp/dumps
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
  -f binary -o result.rmem
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

### docker compose

```yaml
services:
  app:
    image: my-php-app
    volumes:
      - reli-sock:/var/run/reli
      - reli-dumps:/tmp/reli-dumps

  reli-sidecar:
    image: reliforp/reli-prof
    command: >
      inspector:sidecar
        --socket=/var/run/reli/sidecar.sock
        --output-dir=/tmp/reli-dumps
        --tag product=my-app
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

Set the socket path in the application container:

```yaml
services:
  app:
    environment:
      RELI_SIDECAR_SOCKET: /var/run/reli/sidecar.sock
```

### Kubernetes (sidecar container)

```yaml
spec:
  shareProcessNamespace: true
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

## CI Workflow: Cross-Release Memory Comparison

The sidecar enables CI workflows that capture memory snapshots at specific points in a benchmark, then compare across releases to detect regressions.

### Pipeline Overview

```
v2.3.0 release                         v2.4.0 PR
┌─────────────────────┐                ┌─────────────────────┐
│ sidecar + benchmark  │                │ sidecar + benchmark  │
│ → snapshot(baseline) │                │ → snapshot(baseline) │
│ → snapshot(loaded)   │                │ → snapshot(loaded)   │
│ → analyze → v2.3.db  │                │ → analyze → v2.4.db  │
│ → upload artifact    │                │ → download v2.3.db   │
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

      # Start sidecar
      - name: Start sidecar
        run: |
          reli inspector:sidecar \
            --socket=/tmp/reli.sock \
            --output-dir=/tmp/dumps \
            --tag version=${{ github.head_ref }} \
            --tag commit=${{ github.sha }} &

      # Run benchmark
      - name: Run benchmark
        env:
          RELI_SIDECAR_SOCKET: /tmp/reli.sock
        run: php bench/memory_trend.php

      # Analyze dumps. Either .rmem or SQLite is fine — both are
      # accepted by inspector:memory:compare. .rmem is the fastest
      # default and is what the rest of the docs use; switch to
      # `-f sqlite3 -o ....db` if your CI also runs ad-hoc SQL queries
      # against the snapshots.
      - name: Analyze snapshots
        run: |
          for f in /tmp/dumps/sidecar-*.dump; do
            reli inspector:memory:analyze "$f" \
              -f binary \
              -o "/tmp/analyzed/$(basename "$f" .dump).rmem"
          done

      # Compare with baseline (if available)
      - name: Compare with baseline
        if: hashFiles('baseline/*.rmem') != ''
        run: |
          reli inspector:memory:compare \
            baseline/*.rmem /tmp/analyzed/*.rmem \
            --threshold 5%

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
