# Sidecar — Improvement Notes

Design and follow-up notes for `inspector:sidecar` and the standalone
`reliforp/reli-prof-sidecar-client` package, accumulated around the
0.12.0 launch. Items marked `[x]` shipped in 0.12.0; the rest are
tracked candidates for 0.12.x.

This document is in the spirit of `future-ideas.md` — opinionated
design discussion rather than a contract — and is organised around
the failure modes and trade-offs surfaced while bringing the
sidecar to release quality. Each section motivates the change in
terms of *what currently happens* and *what we want instead*, so
future revisions can re-evaluate the trade-off when the surrounding
code shifts.

## Shipped in 0.12.0 (release-blocker batch)

The following landed together as the "0.12.0 launch polish" change:

- [x] **F1** — Quick Start in `docs/monitoring/sidecar.md` rewritten to
      use the default `$XDG_RUNTIME_DIR/reli/sidecar.sock`, with an
      explicit `mkdir + chmod 0700` snippet for hosts where
      `XDG_RUNTIME_DIR` is unset. Server Options table fixed to show
      the actual default.
- [x] **B1** — Pre-flight memory check in
      `SidecarDumpHandler::doDump()`: rejects with a structured
      `status=error` response when `target heap × 1.15 + 16 MiB`
      exceeds the sidecar's available `memory_limit` headroom, instead
      of taking the daemon down via PHP Fatal in
      `MemoryDumper.php:603`.
- [x] **E1** — Replaced the unguarded `fwrite()` reply in
      `SidecarServer::handleConnection` with a centralised
      `writeResponse()` helper that swallows `EPIPE`, logs a single
      structured `sidecar client disconnected before reply` info line
      with `pid`/`label`/`response_path`, and prints a one-line
      operator hint when an orphaned dump remains on disk. No more
      raw `PHP Notice: fwrite(): … Broken pipe`.
- [x] **A1** — `MemoryDumper::dump()` gained a `?\Closure
      $on_read_complete` parameter; `SidecarDumpHandler::doDump()`
      passes a closure that detaches `ptrace` immediately after the
      read phase. Target stop window is now ≈ read-only (verified
      ≈ 0.65 s for a 256 MB target where the full roundtrip was
      1.78 s; previously the whole 1.78 s was inside the stop
      window). Other callers of `MemoryDumper::dump()` are unchanged
      because the new parameter defaults to `null`.
- [x] **C1** — `MemoryLimitHandler::register()` accepts
      `int $timeout_seconds = 30` and forwards it to the inner
      `SidecarClient`. Default preserves prior behaviour; OOM-path
      callers no longer have to drop down to `new SidecarClient(...)`
      to size the timeout against their `memory_limit`.
- [x] **B2 + E2 + F3 + concurrency model** — New "Operations"
      section in `docs/monitoring/sidecar.md` covering
      `--memory-limit` sizing, supervisor expectation
      (systemd unit + Kubernetes `restartPolicy: Always`),
      timeout-sizing table, orphan dump recovery, and the FIFO
      single-worker concurrency model with kernel-backlog queueing.

The sections below are kept verbatim for context; the items marked
`[x]` reference the shipped change above. Items still marked `[ ]`
are 0.12.x candidates.

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

- [x] **A1** — Switch `MemoryDumper::dump` to fast-resume by default.
      Shipped: added `?\Closure $on_read_complete` parameter (default
      null preserves the old behaviour for `inspector:memory:dump` and
      other one-shot callers); `SidecarDumpHandler::doDump()` passes a
      closure that flips a `$stopped` flag and calls
      `process_stopper->resume()` from `on_read_complete`, with a
      safety-net call in the surrounding `finally`. Verified target
      stop window dropped from `read+write` (≈ full roundtrip) to
      `read` only.
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

- [x] **B1** — Pre-flight check landed in `SidecarDumpHandler` as
      `preflightMemoryCheck($heap_stats->size)`, called between
      `heap_stats_reader->read()` and `process_stopper->stop()`. Uses
      `ini_parse_quantity(ini_get('memory_limit'))`; returns
      `SidecarResponse::error(...)` with concrete bytes when the heap
      cannot fit, and logs an info line so operators have a grep
      target. Verified end-to-end: 512 MB target against a 128 M
      sidecar now returns
      `sidecar memory_limit too small for this target: need ~601 MiB,
      116 MiB available …` instead of crashing the daemon.
- [x] **B2** — "Operations" section in `docs/monitoring/sidecar.md`
      now covers the "must run under a supervisor" assumption with a
      sample systemd unit and a Kubernetes pointer.

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

- [x] **C1** — `MemoryLimitHandler::register(...)` now accepts
      `int $timeout_seconds = 30` and forwards it to the inner
      `SidecarClient`.
- [x] **C2** — Timeout-sizing table is in the new "Operations"
      section of `docs/monitoring/sidecar.md`, with the
      "do not lower the default; raise it as needed" rule called out.
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

- [x] **E1** — All three `fwrite` reply sites now go through a single
      `SidecarServer::writeResponse()` helper. It does
      `@fwrite` + length check, logs a structured
      `sidecar client disconnected before reply` info line with
      `pid`/`label`/`response_status`/`response_path`/`response_bytes`,
      and emits `[sidecar] client disconnected; orphan dump still
      saved: <path>` to the operator-facing console output when the
      orphaned dump succeeded.
- [x] **E2** — The "Operations" section now has an "Orphan dump
      recovery" subsection describing the safety net.

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

> **Status update:** F1, F2, and F3 all shipped in 0.12.0. The mirror
> is tagged on the same `0.12.0` version as upstream and the docs
> recommend the `^0.12` install constraint.

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

[x] **Shipped in 0.12.0.** "Installing the client code in your
application" in `docs/monitoring/sidecar.md` lists three options and
recommends the standalone `reliforp/reli-prof-sidecar-client` package
over the full `reliforp/reli-prof` dependency or hand-vendoring. The
mirror is tagged on the same `0.12.0` version as upstream (policy:
mirror tag = upstream tag, exactly — see
`docs/internals/sidecar-release-process.md`), and the install snippet
uses the `^0.12` constraint without `minimum-stability: dev`.

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
   `../../reli-prof-sidecar-client`, or pinning Packagist's `^0.12`.
2. A bootstrap + benchmark fixture (the moral equivalent of the
   `bench.php` we used during this check).
3. A PHPUnit case that boots `dockerd`, starts the sidecar in the
   background, runs the fixture, and asserts on the produced dump
   files.

This catches downgrade slip-ups (e.g. named arguments leaking into
PHP 7.0-targeted client code) and protocol-version drift on a
per-PR basis.

### G2. `bench/sidecar-roundtrip.php`

A minimal demo committed to the repo so anyone can reproduce the
roundtrip with a single `docker compose up`. Should cover both the
manual-snapshot path (`SidecarClient::snapshot`) and the OOM path
(`MemoryLimitHandler::register`) since the failure modes around
each are different. Useful as a fixture for G1.

---

## Suggested PR ordering

**Shipped in 0.12.0 (release-blocker batch):**
A1, B1, B2, C1, C2, E1, E2, F1, F2, F3.

**Remaining for 0.12.x:**

1. **H1 + H2 + H3** — ack-and-go reply mode. Protocol change
   (additive `wait` field). Removes the worker-hold-time problem;
   E1 is now a structured log line rather than a Notice, so this PR
   no longer "obsoletes" E1 — it just reduces how often the
   disconnect path fires.
2. **A2 + A3 + A4** — dump-mode plumbing
   (`--dump-mode={fast-resume,low-memory}` + protocol field +
   `MemoryLimitHandler` argument). Mostly relevant once someone hits
   the B1 pre-flight wall on a sidecar they cannot reasonably
   resize.
3. **H4 + H5** — queue depth reporting / rejection. Optional.
4. **D1** — `on_error` signature extension. Optional-parameter
   addition, fully BC. Useful but not urgent.
5. **G1 + G2** — E2E test infrastructure and bench demo. Best after
   A2-A4 land so the test fixture covers all dump modes.

C3 (raising the default timeout to 60 s) and D2 (sentinel error
type) remain open discussion items rather than tracked tasks.

---

## Operational notes that informed this document

- The Packagist mirror at `reliforp/reli-prof-sidecar-client`
  tracks the upstream maintenance branch via the GitHub → Packagist
  webhook and is tagged in lockstep with upstream
  (`reli 0.12.x ⇒ client 0.12.x`); see
  `docs/internals/sidecar-release-process.md`.
- `/tmp/reli-dumps/` (or whatever `--output-dir` points at) is
  auto-rotated by `--disk-usage-limit` (default `1G`). Stress
  testing for the H series should keep an eye on this — when the
  rotator deletes a dump that an orphan reference still mentions
  in logs, the recovery story in § Operations gets murkier.
- The current `MemoryDumper` runs the read phase entirely under
  ptrace stop, which is what makes A1's resume-after-read
  rearrangement safe. Any future refactor that lets the read phase
  yield back to the event loop must preserve that invariant
  (regions read at different ptrace stops are not a consistent
  snapshot).
