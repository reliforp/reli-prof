# Sidecar Improvements (Working Notes)

> **Status:** Working scratchpad, similar in spirit to `future-ideas.md`.
> Captures concrete improvement ideas that came out of an end-to-end
> check of `reli inspector:sidecar` + `reliforp/reli-prof-sidecar-client`
> (installed via Packagist) on 2026-04-27. Nothing here is implemented
> yet; intent is to split into per-PR design notes when each item lands.

---

## A. Dump pipeline memory efficiency (the main one)

### Current behaviour

`MemoryDumper.php:591-611` reads every region with `process_vm_readv`
into PHP strings appended to `$regions_data[]`, and only after that
loop completes does `MemoryDumpWriter::write()` flush the regions to
disk. The target stop window in `SidecarDumpHandler::doDump()` covers
the whole `memory_dumper->dump()` call, including the disk write.

Measured against a 1 GB target heap:

- Sidecar baseline RSS: ~88 MB
- Peak sidecar RSS during dump: ~1109 MB (≈ target heap + baseline)
- Target stop window: read + write combined (~9 s)

So the sidecar is paying both costs at once — RSS scales with target
heap size, and the target is stopped throughout the disk write phase.
There is room to improve on either axis.

### Proposal

Three modes are distinguishable. The current implementation is
effectively the worst combination of both axes:

| Mode | Sidecar peak RSS | Target stop window |
|---|---|---|
| Current: full buffer + late resume | ≈ target heap | read + write |
| **A: fast-resume** (buffer all → resume → write) | ≈ target heap | read only |
| **B: streaming** (per-region read → write → free) | a few MB | read + write |

`MemoryDumpWriter::writeStreaming()` (lines 74-128) already exists.
`SidecarDumpHandler` simply does not call it.

### Subtasks

- [ ] **A1** — Switch `MemoryDumper::dump` to fast-resume by default.
      Move the `resume()` in `SidecarDumpHandler::doDump`'s `try/finally`
      so it runs immediately after the read phase, before disk write.
      Strict win, no API change.
- [ ] **A2** — Add `--dump-mode={fast-resume,low-memory}` to
      `inspector:sidecar`.
- [ ] **A3** — Add an additive `mode` field to the IPC protocol
      (no `protocol_version` bump):
      - `SidecarRequest::$mode`
      - `SidecarClient::requestDump(..., mode: 'low-memory')`
      - Older sidecars ignore the unknown field; newer sidecars receiving
        a request without `mode` fall back to the server default
        (`--dump-mode`).
- [ ] **A4** — Add `MemoryLimitHandler::register(... dump_mode: 'low-memory')`
      so the OOM-handler path can opt into streaming and avoid taking
      the sidecar down with itself when `target_heap ≈ sidecar memory_limit`.

Priority: A1 > A2 > A3 > A4. A1 alone makes a clean, standalone PR.

---

## B. Sidecar self-defence (don't die on oversized requests)

### Current behaviour

If `target heap > sidecar --memory-limit`, the dump request kills the
sidecar. The fatal lands at `MemoryDumper.php:603` on
`\FFI::string($data, $entry['size'])` with
`Allowed memory size exhausted`. The whole sidecar process exits.

- The target is safe — `ProcessStopper` uses `ptrace(PTRACE_ATTACH)`,
  and the kernel auto-detaches when the tracer dies, so the target
  resumes.
- The socket file is left stale, but the next sidecar start unlinks
  it (`SidecarServer.php:161`).
- No partial dump is written — the sidecar dies before the writer is
  invoked.
- The client gets `null`. Subsequent requests get instant ECONNREFUSED
  until a supervisor restarts the sidecar.
- The design implicitly assumes a supervisor (systemd, Kubernetes) is
  in place to bring the sidecar back up.

The dump that was in flight when the sidecar died is lost forever.
For the OOM-handler use case that is exactly the snapshot the user
wanted. Supervisor restarts handle service recovery, but they cannot
recover the lost dump request.

### Proposal

`SidecarDumpHandler::doDump()` reads `heap_stats_reader->read()`
**before** calling `process_stopper->stop()`. At that point we know
the rough size we are about to allocate, so we can pre-flight against
our own `memory_limit` and bail out with a structured error response,
keeping the sidecar alive.

```php
$memory_limit = self::parseIniBytes(ini_get('memory_limit'));
if ($memory_limit > 0) {
    $needed = (int)($heap_stats->size * 1.15) + 16 * 1024 * 1024;
    $available = $memory_limit - memory_get_usage(true);
    if ($needed > $available) {
        return SidecarResponse::error(sprintf(
            'dump would need ~%d MB but only %d MB available '
            . '(sidecar memory_limit=%d MB). Increase --memory-limit '
            . 'or use --dump-mode=low-memory.',
            $needed >> 20, $available >> 20, $memory_limit >> 20,
        ));
    }
}
```

Effect:

- Sidecar stays up.
- Other clients keep getting served.
- The requesting client receives `status=error` with a specific
  `message` instead of just `null`.
- No crash loop.

### Subtasks

- [ ] **B1** — Add the pre-flight check at the top of
      `SidecarDumpHandler::doDump()` (about 10 lines).
- [ ] **B2** — Add an "Operations" section to the docs covering the
      "you must run under a supervisor" assumption, with a sample
      systemd unit and Kubernetes `restartPolicy: Always`.

Once A's streaming mode lands, B1's constraint is structurally gone,
but B1 is still worth keeping — a daemon that knows its own limits is
easier to reason about than one that crashes silently.

---

## C. Timeout handling

### Current behaviour

- Default constructor: `SidecarClient::__construct(timeout_seconds: 30)`.
- `stream_set_timeout` covers the **response read**, so the client
  blocks until the sidecar finishes the dump and writes its JSON
  reply.
- Measured dump throughput is roughly 110 MB/s (process_vm_readv +
  disk write). The 30 s default therefore covers ~3 GB heaps, which
  is a sensible default.
- However, `MemoryLimitHandler::register()` does not expose a way to
  set the timeout — it builds `new SidecarClient($socket_path)`
  internally with the default.

### Proposal

- [ ] **C1** — Add `timeout_seconds` to
      `MemoryLimitHandler::register(...)`. Forward it to the inner
      `new SidecarClient($socket_path, $timeout_seconds)`.
- [ ] **C2** — Add a timeout-sizing table to the docs:
      ```
      memory_limit  →  recommended timeout
      128 M             5  s
      256 M            10  s
      512 M            15  s
      1   G            30  s (default)
      2   G            60  s
      ```
      Spell out: "do not lower the default; raise it as needed."
- [ ] **C3** (optional) — Consider raising the default to 60 s. 30 s
      caps out around ~3 GB, which is a realistic ceiling for modern
      PHP-FPM workers but not a generous one.

---

## D. Client API ergonomics

### Current behaviour

`SidecarClient::send()`:

```php
$sock = @stream_socket_client(... $errno, $errstr, ...);  // @-suppressed
if ($sock === false) return null;                         // errno/errstr discarded
...
if ($response === false || $response === '') return null; // timeout / EOF
return SidecarClientResponse::fromJson(trim($response));   // bad JSON also null
```

"Connection refused", "read timeout", and "malformed JSON" are all
indistinguishable to the caller — every failure is `null`.
`MemoryLimitHandler` reports a fixed string,
`'failed to connect to reli sidecar'`.

### Proposal

- [ ] **D1** — Extend the `on_error` signature to forward `errno`,
      `errstr`, and a cause classification. Use optional parameters
      so existing callers stay compatible:
      ```
      on_error(string $message, ?int $errno = null, ?string $detail = null)
      ```
- [ ] **D2** (optional) — Introduce a sentinel for error categories,
      either a dedicated exception type or a non-null
      `SidecarClientResponse::error(string $reason)` form. Has to be
      weighed against the shutdown-handler memory budget (the whole
      reason `MemoryLimitHandler` pre-allocates a reserve).

Lower priority. D1 alone is enough to make ops logs separable.

---

## E. Broken-pipe noise on client timeout

### Current behaviour

When a client hits its read timeout and closes the socket while the
sidecar is still working, the sidecar finishes the dump (it writes
to `--output-dir`, not to the socket), then tries to write the JSON
reply. The reply `fwrite()` hits EPIPE, and PHP emits:

```
PHP Notice: fwrite(): Send of 761 bytes failed with errno=32 Broken pipe
            in src/Inspector/Sidecar/SidecarServer.php on line 134
```

The dump file itself is fully written and analyzable — it just
becomes an "orphan" from the client's point of view.

### Proposal

- [ ] **E1** — Replace the bare `fwrite` near
      `SidecarServer.php:134` with `@fwrite` + return-value check, and
      log a structured message such as
      `Log::info('client disconnected before reply (dump still saved at <path>)')`.
      Removes the noisy `Notice` and makes orphans observable.
- [ ] **E2** — Document the "timed-out client → dump still on disk"
      safety net. Currently the only way to learn about it is to
      experience it.

---

## H. Worker hold time / queueing

### Current behaviour

`SidecarServer::run` is a single-threaded loop:
`stream_select → accept → handleConnection (synchronous dump) → next iteration`.
Concurrent dump requests are strictly serialized via the kernel's
listen backlog. Verified by sending a 1024 MB dump alongside two
16 MB requests with 1 s timeouts: the second and third clients sat
blocked in `fgets()` until either the in-flight dump finished or
their own client-side timeout expired. Sidecar logs confirmed FIFO
order; kernel-buffered request bytes were still processed by the
server even after the clients had given up, producing orphan dumps
plus `PHP Notice: fwrite(): ... Broken pipe`.

The actual operational pain isn't dropped dumps (those land on disk
and are recoverable). The pain is that the **PHP-FPM worker is
held in `fgets()`** while it waits, holding its `pm.max_children`
slot, heap, file descriptors, and DB connections for the full
queue-plus-dump duration. With realistic queue depths against
hundreds-of-MB targets, that easily exceeds the default 30 s
client timeout — so tail clients pay the full timeout cost in
worker hold time before getting `null` back.

### Proposal: ack-and-go reply mode

Add an additive protocol field that lets the client opt into
"acknowledge-only" replies. Server reads the request, sends a
short ack JSON immediately, closes the connection, and *then*
runs the dump. The client returns from `requestDump()` in a few
ms regardless of in-flight work elsewhere.

Request additive field:
```json
{"command": "dump", "pid": 1234, "wait": false, ...}
```

- `wait` defaults to `true` (existing behaviour, BC preserved).
- `wait: false` ⇒ ack-only reply.
- Old sidecars ignore `wait` and reply normally — clients on
  ack-and-go that hit an old sidecar still work, they just block
  the way they do today.
- New sidecars receiving requests without `wait` keep the current
  full-response behaviour.
- No `protocol_version` bump (additive change only).

Ack response shape:
```json
{"protocol_version": 1, "status": "accepted",
 "queue_position": 2,
 "predicted_path": "/tmp/dumps/sidecar-1234-20260427-...-label.dump"}
```

`predicted_path` is fine because the path is deterministic from
PID + timestamp + label — `SidecarDumpHandler::doDump()` already
builds it; it just needs to move *before* the work starts.

Client API:
```php
$client->requestDump(pid: getmypid(), wait: false);
$client->snapshot('after-fixtures', wait: false);
```

`MemoryLimitHandler::register(... wait_for_completion: false)`,
defaulting to `false`. Shutdown handlers don't care about the
response and very much do want to release the worker slot.

### Effects

- Worker hold time becomes O(network roundtrip) instead of
  O(queue depth × dump time).
- Queue back-pressure becomes explicit (`queue_position` lets
  clients log "I'm 12th in line" rather than infer from wall-clock).
- The `Broken pipe` `Notice` (E1) goes away naturally — the server
  is no longer trying to write to a socket the client has closed.
- Composes cleanly with A (dump-mode) and B (pre-flight): each
  request can be `{"wait": false, "mode": "low-memory"}`.
- Doesn't add server-side concurrency. One dump at a time, still
  FIFO. Just stops billing the wait to the client's worker slot.

### Caveats

- For CI-snapshot-style use cases that need to *use* the resulting
  dump path, `wait: true` is still the right choice — `predicted_path`
  is a hint, not a guarantee (a subsequent failure isn't reported).
- A queue-depth limit (`status: "rejected"` when full) is a separate
  concern but rides on the same protocol change.
- Server crashes after ack ⇒ dump lost. Same as `wait: true` from
  the *recoverability* angle (a `wait: true` client also can't tell
  whether a `null` means "never started" or "started and lost"); the
  difference is purely in observability.

### Subtasks

- [ ] **H1** — Add `wait` field to `SidecarRequest`, plumb into
      `SidecarServer::handleConnection`. Move
      `output_path` construction in `SidecarDumpHandler::doDump`
      ahead of the stop+dump phase so it can be returned in the ack.
- [ ] **H2** — Add `SidecarClient::requestDump(..., wait: bool = true)`
      and `SidecarClient::snapshot(..., wait: bool = true)`.
      Return a `SidecarClientResponse` with `status='accepted'` and
      `predicted_path` populated when `wait: false`.
- [ ] **H3** — Add `wait_for_completion: bool = false` to
      `MemoryLimitHandler::register()`; forward to the inner client.
      Default-false because the whole point of the OOM path is "don't
      hold the worker while shutting down".
- [ ] **H4** — Optional: include `queue_position` in both ack and
      full responses so synchronous clients can also log queue
      depth. Cheap to add since the server can count pending
      `accept()`s on the listening socket.
- [ ] **H5** — Optional sibling to H4: `--max-queue-depth=N` server
      flag; once N is reached, ack with `status: 'rejected', reason:
      'queue full'` instead of accepting. Reuses the H1 plumbing.

---

## F. Documentation gaps

### F1. Socket parent directory requirement in Quick Start

`docs/monitoring/sidecar.md` Quick Start uses
`--socket=/tmp/reli-sidecar.sock`, but
`SocketPathResolver::assertParentSafe()` requires the parent directory
to be mode 0700. `/tmp` is typically 0777, so the example crashes:

```
[RuntimeException]
Sidecar socket parent directory /tmp has mode 0777, expected 0700.
Run: chmod 0700 '/tmp'
```

Fix: rewrite the example as
`mkdir -p /tmp/reli-run && chmod 0700 /tmp/reli-run` followed by
`--socket=/tmp/reli-run/sidecar.sock`, or steer users toward the
default `$XDG_RUNTIME_DIR/reli/sidecar.sock`.

### F2. Packagist install path

The current docs offer two installation routes — `composer require
reliforp/reli-prof` (the full package) or vendoring the three client
files manually. There is in fact a third path: the
`reliforp/reli-prof-sidecar-client` standalone package on Packagist
(currently `dev-main` only, no tag yet). It should be the recommended
route:

```bash
composer require reliforp/reli-prof-sidecar-client:dev-main
```

This requires `minimum-stability: dev` until the package is tagged.
The tagging policy itself needs sorting (semver, starting at 0.1.0?).

### F3. Operations section

A single "Operations" section in `sidecar.md` to consolidate:

- Sizing `--memory-limit` (≥ max target `memory_limit` + ~100 MB).
- Supervisor expectation, with a systemd unit and a Kubernetes
  `restartPolicy: Always` example.
- Timeout sizing table (folds C2 in).
- Orphan dump recovery (folds E2 in).
- Sizing under streaming mode, once that lands.

---

## G. Tests / CI

### G1. End-to-end smoke test

Add `tests/e2e/sidecar-client/` containing:

1. `composer.json` — either a path repository pointing to
   `../../reli-prof-sidecar-client`, or pinning Packagist's `dev-main`.
2. A bootstrap + benchmark fixture (the moral equivalent of the
   `bench.php` we used during this check).
3. A PHPUnit case that boots `dockerd`, starts the sidecar in the
   background, runs the fixture, and asserts on the produced dump
   files.

This catches downgrade slip-ups (e.g. named arguments leaking into
PHP 7.0-targeted client code) and protocol-version drift on a
per-PR basis.

### G2. `bench/sidecar-roundtrip.php`

A minimal demo committed to the repo. The scratch scripts created
during this check
(`/home/user/demo-app/{bench,oom,timing,rss_watch}.php`) can be
cleaned up and dropped under `bench/` so anyone can reproduce the
roundtrip with a single `docker compose up`.

---

## Suggested PR ordering

1. **A1** — fast-resume default. Standalone, no protocol change.
2. **B1** — pre-flight memory check. Standalone, ~10 lines. Order
   relative to A1 doesn't matter.
3. **F1 + E2** — doc fixes; bundle F2 with them as one PR.
4. **H1 + H2 + H3** — ack-and-go reply mode. Protocol change
   (additive). Removes the worker-hold-time problem and obsoletes
   E1 as a side effect; can be sequenced before or after A2-A4
   since both are additive protocol fields and don't conflict.
5. **A2 + A3 + A4** — dump-mode plumbing. Touches the protocol,
   so review carefully.
6. **C1 + C2** — `MemoryLimitHandler` parameter and timeout docs.
7. **E1** — broken-pipe log cleanup. Small, standalone. Skip if H
   has already landed (the symptom is gone).
8. **H4 + H5** — queue depth reporting / rejection. Optional.
9. **D1** — `on_error` signature extension. Can wait.
10. **G1 + G2** — E2E test and demo. After the above stabilise.

---

## Verification notes (kept for future reference)

- Host PHP is 8.4; reli requires `^8.5`. Used
  `composer install --ignore-platform-req=php` to install the dev
  dependencies.
- Packagist's `dev-main` tracks the actual head of main on the
  generated `reliforp/reli-prof-sidecar-client` repository
  (commit `423ee89` at time of writing). The GitHub → Packagist
  webhook is in place.
- `/tmp/reli-dumps/` is auto-rotated by `--disk-usage-limit=1G`.
  When the verification left ~1.4 GB of dumps behind, manual
  `rm -rf` was needed.
- Verification scripts live at `/home/user/demo-app/`. They can be
  cleaned up and re-used as the basis for G2.
