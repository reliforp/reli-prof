# Design Notes: Launcher Mode (Unprivileged Subprocess Monitoring)

Working design doc for an unprivileged monitoring mode where reli
launches the PHP target itself (or accepts self-initiated requests),
exposes a Unix-socket RPC, and lets existing reli capabilities be
driven over the wire. In the spirit of `future-ideas.md` — opinionated
design discussion rather than a contract.

The design crystallised by deliberately *removing* features as we
went. Most of this doc is the trail of "we thought we needed X, we
don't" decisions; readers picking up the implementation should pay
attention to the rejected branches because each rejection is load-
bearing for what's left.

## Motivation

reli's existing attach mode (`inspector:trace -p $PID`,
`inspector:memory:dump -p $PID`, etc.) requires either:

- **Same UID + Yama `ptrace_scope=0`** — uncommon outside dev boxes.
- **Yama `ptrace_scope=1` AND ancestor relationship** — practical only
  when the developer happens to be the parent of the process they
  want to inspect.
- **`CAP_SYS_PTRACE`** — privileged, often unavailable in
  production-shaped environments.

The "ancestor relationship" branch of Yama scope=1 is the interesting
one: it's the kernel's expression of the principle "you can introspect
things you started". We can satisfy it on demand by **having reli
launch the target itself**, after which the entire descendant tree is
trivially in scope without any privilege escalation.

Combined with an RPC surface that exposes existing reli operations,
this gives us a clean answer to:

> "How do I reli-monitor a PHP-FPM master + workers (or Roadrunner,
> queue dispatcher, `composer` running sub-PHPs, etc.) without
> CAP_SYS_PTRACE and without arranging same-UID-+-scope=0 ahead of
> time?"

Answer: launch it under reli, attach over RPC.

## Conceptual model: launcher = sidecar with extended trust

The existing `inspector:sidecar` already exposes a Unix-socket RPC
where PHP targets connect and ask reli to operate on themselves. Its
trust model is "**peer == target**": `SO_PEERCRED` identifies the
calling process, and reli only operates on that PID. The target's
voluntary connection is the consent.

Launcher mode is the same RPC server with an **extended trust
circle**: in addition to "peer == target" (sidecar's `self_*`
methods), it also accepts "**operate on a descendant of mine**". The
consent here is structural — the user started reli, reli started the
target, so by transitivity the user permits inspection. Yama's
ancestor rule is the kernel's machine-readable form of the same
intuition.

Two routes to "we are not arbitrarily snooping on unrelated
processes":

| Mode | Target consent | Implementation |
|---|---|---|
| sidecar (`--no-target`) | target opts in by connecting | `SO_PEERCRED` |
| launcher (target launched by reli) | structural — you started it | Yama ancestor (kernel-enforced) |

The implementation is **one RPC server class** that accepts both
patterns. CLI presents two subcommands as facades for clarity:

- `reli inspector:sidecar` — RPC server, no target launch
  (backwards-compatible)
- `reli launcher -- cmd args...` — RPC server + target launch

Internally the only difference is whether `fork+exec(target)` ran at
startup. Same protocol, same wire format, same handlers.

## Scope (what's in for v1)

### In scope

- userns-only, optionally
- target launched by reli (or omitted — sidecar shape)
- `PR_SET_CHILD_SUBREAPER` so descendants reparent to launcher when
  intermediate ancestors die
- Unix-socket RPC, JSON line-delimited control plane,
  SCM_RIGHTS for bulk output fd-passing
- Coverage of all reli-internal-tracer commands
  (`inspector:trace`, `:eg_address`, `:peek-var`, `:top`,
  `:coredump`, `:memory:dump`, `:watch`; `rmem:live`, `:explore`)
- `self_*` method family for sidecar-style self-requesting targets
- Reactive descendant model — kernel knows the ancestry, launcher
  doesn't mirror it

### Out of scope (deliberately)

- **PID namespace, mount namespace, cgroup placement** — explored
  and rejected for v1; see "Rejected branches" below.
- **Proactive descendant tracking + auto-attach policies** — moved
  to client-side `--follow` if needed. See same.
- **`phpspy:trace` / `phpspy:daemon` over launcher** — phpspy is an
  external tracer process; as a sibling of the target it can't
  satisfy Yama scope=1 without target-side `PR_SET_PTRACER`
  cooperation. Out of structural reach.
- **`inspector:sidecar` standalone command in launcher's
  unprivileged story** — the sidecar shape still works but doesn't
  inherit launcher's "unprivileged via ancestry" property; that
  branch retains its existing requirements (same UID + dumpable, or
  CAP).
- **`rmem:serve` / `rmem:mcp`** — these are reli-as-server in their
  own right; they may *consume* launcher RPC, but live in a
  separate axis.
- **Threaded worker decomposition via `ffi-zts-parallel`** — see
  "Worker model" below. Single-process v1, threads are a future
  optimisation.

## Architecture

### Server lifecycle

```
launcher start:
  prctl(PR_SET_CHILD_SUBREAPER, 1)
  if --userns: unshare(CLONE_NEWUSER), set up uid/gid maps
  bind Unix socket (mode 0700, SO_PEERCRED enforced)
  if target_argv: fork + exec(target_argv)
  enter main loop (epoll: socket accept, pidfd POLLIN, signals)

per-connection:
  recvmsg loop: parse line-delimited JSON, dispatch handlers
  on close: stop_action() for every action started on this conn

per-attach (lazily on first attach <pid> or self_attach):
  pidfd_open(pid) → register with epoll
  record start_time for PID-reuse detection
  cold-attach (parse ELF, find _executor_globals, etc.)

per-action:
  receive request + optional fd via SCM_RIGHTS
  validate fd count against action kind's schema
  spawn handler (coroutine / fiber / thread depending on impl)
  emit events on the conn, write bulk output to provided fd
```

### Why no descendant tracking

We discovered partway through that launcher does **not** need to
maintain a known set of descendants. The kernel already knows the
ancestor relationship and enforces it via Yama; userspace mirroring
it is double work.

What's actually needed:

- **`PR_SET_CHILD_SUBREAPER`**: not just a cleanup safety net — it's
  what keeps the kernel-side ancestor relationship intact when
  intermediate ancestors die (without it, an orphaned grandchild
  reparents to PID 1 and Yama no longer sees launcher as its
  ancestor → ptrace fails).
- **`/proc/<pid>/stat:start_time`** at attach time, re-checked on
  each subsequent op — guards against PID reuse across the request
  window.
- **`pidfd_open(pid)`** at attach time — lets epoll deliver
  exit-detection without polling or signal handling.

That's it. No periodic `/proc` walks, no policy engine, no `(pid,
start_time)` cache, no `--scan-interval` tuning. The design got
much smaller once we stopped trying to know things the kernel
already tracks.

`list_descendants` as an RPC isn't provided; users compose `pgrep
-P` / `ps --ppid` and feed PIDs into RPC calls. `list_attached`
*is* provided — it's launcher-internal state that the kernel
doesn't surface.

### Worker model

reli's existing daemon commands use `amphp/parallel` 2.x, which
spawns workers as **separate child processes** via `proc_open`. In
launcher mode, those workers would be **siblings of the target**
(both are children of launcher main), not ancestors — Yama scope=1
forbids siblings from ptracing each other.

Three available answers, ranked by current preference:

1. **Single-process** (v1 default): all RPC handlers run inside
   launcher main. No worker fan-out. Simple, ships now. Limit: one
   target's heavy operation can starve another's, but PHP-FPM-style
   workloads are mostly low-rate sampling so this rarely matters.

2. **`ffi-zts-parallel` threads** (future): boot a ZTS PHP runtime
   inside the NTS host via the
   [ffi-zts-parallel](https://github.com/sj-i/ffi-zts-parallel)
   shim, run workers as `parallel\Runtime` threads of launcher
   main. Threads share TGID with launcher, so the kernel's
   `task_is_descendant` check uses `same_thread_group()` and walks
   to the target's true ancestor. **Yama-clean without target-side
   cooperation.** Real concurrency, no IPC marshalling for hot
   paths. Cost: PHP 8.4+/8.5+ requirement, parallel's
   no-shared-mutable-state constraint, FFI CData lifetime across
   thread boundaries needs care (already a known footgun — see
   `docs/internals/ffi-cdata-lifetime.md`).

3. **`PR_SET_PTRACER_ANY` injection** (rejected): inject a tiny
   prelude (LD_PRELOAD or `auto_prepend_file`) into the target that
   calls `prctl(PR_SET_PTRACER, PR_SET_PTRACER_ANY)`. Lets sibling
   workers attach. Rejected because it requires touching the target
   environment, which the rest of launcher mode is careful to
   avoid.

v1 ships (1). (2) is the planned upgrade path once ffi-zts-parallel
matures and we have a real workload that strains the single-process
model.

## RPC protocol

### Transport

- Unix domain socket, mode `0700`, owned by launcher's UID.
- Connection-time check: `getsockopt(SO_PEERCRED)` → reject if peer
  UID doesn't match launcher UID.
- Line-delimited JSON for the control plane.
- SCM_RIGHTS for bulk output fd-passing (out-of-band on the same
  socket, attached to the request that needs it).

### Message types

```jsonc
// client → daemon
{"type": "request", "id": <int>, "method": "<name>", "params": {...}}

// daemon → client (response to a specific request)
{"type": "response", "id": <int>, "ok": <bool>,
 "result": {...}        // when ok
 "error": {"code": "...", "message": "..."}  // when not ok
}

// daemon → client (unsolicited, action progress / lifecycle)
{"type": "event", "kind": "<event_kind>",
 "action_id": "<id>"?, "pid": <int>?, ... payload ...}
```

### Methods

Core set (intentionally small):

| method | params | returns |
|---|---|---|
| `attach` | `{pid}` | `{php_version, mode, ...cold-attach metadata}` |
| `detach` | `{pid}` | `{}` |
| `list_attached` | `{}` | `{attached: [{pid, start_time, php_version, ...}]}` |
| `start_action` | `{pid, kind, params}` (+ SCM_RIGHTS fds per kind schema) | `{action_id, result?}` |
| `stop_action` | `{action_id}` | `{summary: ...}` |
| `get_action_status` | `{action_id}` | `{state, started_at, ...}` |
| `subscribe_attach` | `{pid}` | `{}` |
| `unsubscribe_attach` | `{pid}` | `{}` |
| `self_attach`, `self_detach`, `self_start_action`, `self_stop_action` | (no `pid`; uses `SO_PEERCRED`) | as above |

`start_action` is the universal "do something" verb; the `kind`
field selects per-kind params, fd count, output channel, and event
schema.

### Action kinds

| kind | corresponding command | fds | output channel | duration |
|---|---|---|---|---|
| `eg-address` | `inspector:eg_address` | 0 | response.result | one-shot |
| `peek-var` | `inspector:peek-var` | 0 | response.result | one-shot |
| `top-snapshot` | `inspector:top` (1 sample) | 0 | response.result | one-shot |
| `top` | `inspector:top` (TUI feed) | 0 | events (periodic snapshots) | long |
| `memory-dump` | `inspector:memory:dump` | 1 | bulk fd | one-shot (large) |
| `coredump` | `inspector:coredump` | 1 | bulk fd | one-shot (large) |
| `trace` | `inspector:trace` (text/json/rbt) | 1 | bulk fd (streamed) | long |
| `watch` | `inspector:watch` | 0 | events + sub-action outputs to auto-output-dir | long |
| `rmem-live` | `rmem:live` | 0 | events | long |
| `rmem-explore` | `rmem:explore` | 0 | events | long |

`memory:compare`, `memory:analyze`, `memory:report` are
client-side analyses over one or more `memory-dump` outputs; no
new action kinds needed.

### Output model

The "where do bytes go" question splits along a clean line:

- **Control plane** (commands, responses, lifecycle events,
  diagnostics): RPC stream, structured JSON.
- **Bulk output** (rbt streams, heap dump bytes, coredump bytes):
  fd passed by client via SCM_RIGHTS. **Daemon writes directly to
  client's fd. Daemon never sees the path.**

This punts permission/cwd/path-resolution concerns entirely:

- Client opens the file (or uses its own stdout for piping) under
  *its* credentials and *its* cwd. Sends fd.
- Daemon writes bytes. Doesn't know the path. Can't write
  somewhere the user didn't ask for.
- Pipes (`reli inspector:trace --launcher=sock -p X | flamegraph.pl`)
  work transparently — the fd passed is client's stdout, daemon
  writes there, output flows through the pipe.

`watch`'s sub-actions are the one exception: they fire from inside
a long-running parent action with no client fd available. Their
output goes to a launcher-startup-configured directory
(`--auto-output-dir=PATH`). User opted into that path explicitly at
launcher start, so cwd/permission ambiguity is bounded.

Requires `ext-sockets` for SCM_RIGHTS (`socket_cmsg_*`); this is a
launcher-mode-only dependency, attach mode is unaffected.

### Lifecycle and connection semantics

- Actions are **bound to the connection that started them**. If the
  connection drops (client Ctrl-C, network glitch on the unix
  socket — yes that happens), the launcher auto-stops every action
  on that connection. Trace flushes, file-passed fds get closed,
  rbt finalisation marker is written before close.
- `stop_action` response semantics: the response only fires after
  the action has finished flushing. Clients can rely on
  "response received → bulk output is complete on disk".
- Per-target lifecycle events (`attach_lost`, `action_aborted`)
  push to all subscribed connections.

### Error model

Stable string error codes for client-side branching:

- `PERM_DENIED` — kernel rejected ptrace/process_vm_readv (caller
  not an ancestor, target not dumpable, UID mismatch).
- `NO_SUCH_PID` / `TARGET_EXITED`
- `PID_REUSED` — start_time mismatch on subsequent op
- `NOT_ATTACHED`
- `BAD_PARAMS` — schema mismatch, including SCM_RIGHTS fd count
  mismatch
- `COLD_ATTACH_FAILED` — generic catch-all for the binary-analysis
  pipeline failures, with `message` carrying details

## Existing CLI surface, mapped

```bash
# Attach mode (existing, unchanged)
reli inspector:trace -p 1234 -o /tmp/foo.rbt

# Launcher mode
reli launcher --rpc=/tmp/reli.sock -- php-fpm
# in another terminal, same UID:
reli inspector:trace --launcher=/tmp/reli.sock -p $WORKER_PID -o /tmp/foo.rbt
reli inspector:memory:dump --launcher=/tmp/reli.sock -p $WORKER_PID -o /tmp/heap.bin
reli inspector:watch --launcher=/tmp/reli.sock -p $WORKER_PID --condition='...'
```

The `--launcher=<sock>` flag turns each existing reli subcommand
into an RPC client of the launcher. The user-visible CLI is
identical to attach mode; the difference is who actually executes
the heavy work.

`pgrep -P $LAUNCHER_PID` (or `ps --ppid`, etc.) is the canonical
way to discover available descendant PIDs. Launcher does not
provide a `list_descendants` RPC; the kernel via /proc is the
authoritative source.

## Rejected branches (with reasoning)

These came up during design discussions and were ruled out. Each
rejection is load-bearing — re-introducing one of these requires
revisiting downstream simplifications that depend on it being out.

### PID namespace

**Rejected for default**, possibly opt-in later via `--isolate`.

Adding `CLONE_NEWPID` cleans up after launcher crashes (kernel
SIGKILLs the entire pidns when its PID 1 dies) and gives us a
stable PID space. **But it ties target lifecycle to launcher
lifecycle**, which is wrong for an observability tool. reli is
known to crash (FFI CData lifetime, ELF parsing edge cases — see
`docs/internals/ffi-cdata-lifetime.md` and CLAUDE.md for examples);
killing the user's target tree because *the monitor* SEGV'd
destroys the reproduction state the user was trying to capture.

Default = userns only. Target survives launcher crashes, gets
reparented up. PID-ns isolation can be opt-in later for use cases
(CI, benchmarks) where lifecycle coupling is desired.

### Mount ns + custom rootfs (PHP-version sandboxing)

**Rejected outright.** The temptation is "reli launches a target
against a known PHP build without docker". The reality is this
turns reli into a mini container runtime: OCI rootfs handling,
`pivot_root`, bind mount setup, default seccomp, capability drops.
Several thousand lines of code that duplicates `runc`/`crun`/`bwrap`
without their maturity.

If users need PHP-version sandboxing under launcher, the recommended
path is to launch reli under an existing runtime (`bwrap --bind ...
reli launcher -- php script.php`) rather than reimplement.

### cgroup v2 placement (freezer, PSI, accounting)

**Rejected for v1**, candidate for opt-in v2.

Initial pitch: atomic multi-worker memory snapshot via
`cgroup.freeze`. On reflection, **PHP worker-mode runtimes don't
share interesting state across workers** (each worker owns its
ZendMM heap; opcache/APCu are SHM accessed atomically per op), so
"atomic" doesn't actually deliver new analytical capability.
Sequential per-worker dumps with timestamps are functionally
equivalent.

What does survive scrutiny:
- **PSI** (cgroup-only signal): useful for "PHP frame looks idle
  but kernel was IO-stalled" diagnoses.
- **Per-target RSS/CPU accounting**: `/proc/<pid>/status`
  alternatives exist; cgroup is just neater for sums.

Net: cgroup integration is a nice-to-have for diagnostics, not a
killer feature. v1 omits it to keep launcher minimal; v2 can add
optional placement if PSI/accounting demand surfaces.

### Proactive descendant tracking + auto-attach policies

**Rejected.** Original design had a `/proc` polling loop, a
known-descendant set with `(pid, start_time)` identity, and
policies like `--auto-attach=all --on-attach=trace` that fire on
new descendants automatically.

Killer realisation: **the kernel already knows ancestry; we don't
need to mirror it**. `attach <pid>` can let the kernel reject
non-descendants via Yama's natural EPERM. `list_attached` is the
only launcher-side state worth tracking.

The auto-attach use case ("trace every new php-fpm worker as it
spawns") moves to **client-side `--follow`** logic — a client
subcommand polls `pgrep -P` (or hits `list_attached` + diffs) and
fires `start_action` per new worker. Different clients can layer
different policies without bloating the launcher.

This collapse — from "policy engine + scan loop + descendant cache
+ auto-output-dir for unattended actions" to "reactive only,
kernel decides" — is the largest single simplification in the
design.

(Auto-output-dir survives in narrow form: only `watch`'s
sub-actions, which fire inside an in-flight client action without a
new fd available, write there. Everything else uses client-passed
fds.)

### `cn_proc` netlink, eBPF tracepoints

**Not accessible unprivileged.** `PROC_CONNECTOR` requires
`CAP_NET_ADMIN`; eBPF requires `CAP_BPF` or `CAP_SYS_ADMIN`. Both
would give us push-style descendant events but defeat the
"unprivileged" property. Listed only to record they were
considered.

### `PTRACE_SEIZE` with `TRACECLONE` etc. on the target tree

**Rejected for v1.** Would give us perfect descendant tracking
fidelity (every fork/exec/exit as a kernel event) but requires
seizing the target, which conflicts with reli's own
`process_vm_readv`-based sampling and stops the target on every
fork. Mentioned as a future `--ptrace-tree` opt-in for users who
genuinely need every short-lived descendant captured.

### setuid-target horizon

The unprivileged story breaks if target calls `setuid()` to drop
to a different UID: `process_vm_readv` cross-UID needs
`CAP_SYS_PTRACE`, and dumpable bit clearing kills it for non-root
even when ancestry is fine.

**Accepted opt-in answer: `--userns`**. launcher unshares
`CLONE_NEWUSER`, becomes ns-root with `CAP_SYS_PTRACE` *inside the
ns*. Target's setuid happens within the ns, launcher remains
privileged-in-ns and can trace across the UID change. Host sees
only the launcher's real UID, no privilege boundary crossed.

Caveats: needs `kernel.unprivileged_userns_clone=1` (default on
modern distros, but Docker default seccomp profile blocks
`unshare`, and some Kubernetes pod policies disallow it). target
sees mapped UIDs which can confuse apps that introspect their own
UID.

Default: not enabled. Opt-in via `--userns` for users who actually
hit setuid'ing targets.

## Open implementation questions

### `inspector:watch` condition language serialisation

watch's condition DSL is currently a string parsed inside the
process. RPC can carry it as a string (no change). But sub-action
*results* — when `on_trigger: [{kind: "memory-dump", ...}]` fires —
need to surface back to the watch's RPC client over the event
stream, including paths in `--auto-output-dir`. Schema:

```jsonc
{"type": "event", "kind": "subaction_finished",
 "action_id": "watch_id", "subaction_id": "act-N",
 "kind_fired": "memory-dump",
 "output_path": "/var/log/reli/dump-1234-act-N.bin",
 "summary": {"bytes_written": ..., "elapsed_ms": ...}}
```

Need to confirm what watch's existing event shape looks like and
align.

### rbt finalisation under client disconnect

rbt streams have a terminating marker that's written on graceful
shutdown. If the client disconnects mid-trace, the daemon needs to:
1. Detect the disconnect (epoll EPOLLHUP/RDHUP on the connection).
2. Stop the trace action.
3. Flush the rbt finaliser to the **already-passed bulk output fd**
   (which is independent of the disconnected control connection —
   the fd was dup'd into the daemon).
4. close the fd.

Step 3 works because SCM_RIGHTS gave the daemon its own dup of the
fd. The bulk fd outlives the control connection. Worth confirming
this in implementation; if it doesn't hold, we need to buffer the
finaliser bytes somewhere reliable.

### PID reuse race window

Reuse is rare in practice (Linux PID space is large, PID allocation
is slow-cycle), but: between `attach <pid>` returning and the next
`start_action <pid>`, theoretically `pid` could die and get
reused. We mitigate by recording `start_time` at attach and
re-checking on each op, returning `PID_REUSED` on mismatch.

Edge case: `pidfd_open` returns a stable pidfd even across PID
reuse, so the pidfd remains tied to the original task. Polling
the pidfd for POLLIN gives us reliable "your attached process is
gone" notification regardless of PID reuse.

### `self_*` from non-launched targets

A PHP target running outside launcher mode can still connect to a
sidecar-shape RPC and use `self_*`. In that case Yama might
disallow the actual ptrace if the launcher isn't an ancestor. We
let the kernel reject with EPERM and surface as `PERM_DENIED` —
this matches today's sidecar behaviour (which just relies on
same-UID + dumpable). No new policy needed.

## Summary

Launcher mode is a small extension to reli, not a new product:

1. A Unix-socket RPC server (shared with sidecar's existing
   server, conceptually unified).
2. Optional `fork+exec(target)` at startup, plus
   `PR_SET_CHILD_SUBREAPER` to keep ancestry intact.
3. Existing reli subcommands gain `--launcher=<sock>` to become
   RPC clients.
4. SCM_RIGHTS fd-passing for bulk output keeps permission
   semantics clean.
5. No descendant tracking, no policy engine, no auto-attach loops
   — the kernel knows the ancestry and the user composes
   `pgrep`/`ps` for discovery.

The unprivileged unlock comes from Yama's "ancestor" rule, which
launcher mode satisfies by construction. Setuid'ing targets are
covered by an opt-in `--userns` mode. PID-ns / mount-ns / cgroup
extensions were considered and rejected as misaligned with
"observability tool" rather than "container runtime".
