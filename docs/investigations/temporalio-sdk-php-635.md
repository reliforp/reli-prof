# Investigation: temporalio/sdk-php #635 (workflow worker memory leak)

Issue: https://github.com/temporalio/sdk-php/issues/635

Reporter: re-running workflows from the Temporal UI causes the PHP workflow
worker to accumulate ~1 GB of memory and leave zombie processes. Reported
against Temporal 1.24.2 / sdk-php v2.14.1.

## Setup

We avoid the full Temporal server stack and instead drive the SDK through
its existing test harness (`tests/Fixtures/WorkerMock` + `Splitter`), which
replays a recorded RoadRunner frame log. That makes it possible to fire
StartWorkflow/Timer/DestroyWorkflow cycles from a single long-lived PHP
process whose PID we can hand to reli.

Repro script: `/tmp/leak-repro.php` (see git history). It

1. warms up 5 cycles, then captures a baseline `memory_get_usage()`,
2. sleeps so reli can dump,
3. disables PHP cycle GC (`gc_disable()`),
4. runs N start+timer+destroy cycles back-to-back,
5. sleeps again so reli can dump,
6. reports the in-process delta and runs `gc_collect_cycles()` for comparison.

Disabling cycle GC is deliberate — it lets us see whether the cleanup paths
in the SDK rely on `gc_collect_cycles()` to do their work, or whether plain
refcounting is enough. (The SDK's own `DestroyWorkflow` route calls
`gc_collect_cycles()` every 1000 destroys / 30 s via
`Internal\Support\GarbageCollector`.)

## reli measurement

Workflow: `AwaitWithTimeoutWorkflow` (start → NewTimer → DestroyWorkflow).

```
# baseline dump (after warm-up + GC, gc_disable())
php reli inspector:memory:dump   -p <pid> -o /tmp/sdk635-baseline.rmem
php reli inspector:memory:analyze /tmp/sdk635-baseline.rmem \
    -o /tmp/sdk635-baseline.real.rmem --memory-limit=2G

# run 5000 start/destroy cycles, then a second dump
php reli inspector:memory:dump   -p <pid> -o /tmp/sdk635-after.rmem
php reli inspector:memory:analyze /tmp/sdk635-after.rmem \
    -o /tmp/sdk635-after.real.rmem --memory-limit=2G

# diff
php reli inspector:memory:compare \
    /tmp/sdk635-baseline.real.rmem /tmp/sdk635-after.real.rmem
```

In-process numbers across 5000 cycles with cycle GC disabled the entire time:

```
Baseline mem (after warm-up + GC):        11,061,616
Mem after 5000 cycles (GC-disabled):      11,057,960    (-3,656 B)
Mem after gc_collect_cycles:              11,062,024    (+408 B)
```

`inspector:memory:compare` of the two snapshots:

```
Nodes: 110,856 → 110,863 (+7)
Edges: 162,024 → 162,031 (+7)

memory_get_usage()      10.55 MB → 10.55 MB    +408 B
RSS                     46.99 MB → 47.03 MB   +40 KB
```

The only delta is one new RuntimeCache entry (compilation cache, a one-shot
warm-up effect). No workflow-shaped objects — no `WorkflowInstance`, no
`Process`, no `Deferred`, no `SignalQueue` consumers — survive a destroy
cycle for this workflow shape.

**Conclusion for the simple case: no leak in v2.14.1.** Plain refcounting
in the destroy path is enough; cycle GC isn't even necessary.

## Where the v2.14.1 destroy path could still leak

The minimal repro doesn't trip a leak, but reading the v2.14.1 destroy code
shows there are clear gaps that *are* fixed on master. Specifically:

- `Internal/Declaration/WorkflowInstance::destroy()` calls
  `$this->signalQueue->clear()` (v2.14.1 line 303). `SignalQueue::clear()`
  *only empties `$this->queue`* — `$consumers`, `$dynamicConsumer`, and the
  `$onSignal` closure are kept. `$onSignal` is set in
  `Process::__construct` to a closure that captures `$this` (the Process),
  forming a cycle Process → WorkflowContext → WorkflowInstance →
  SignalQueue → onSignal-closure → Process.

- For workflows that actually attach signal handlers via
  `SignalQueue::attach`, the consumer closure binds the user workflow
  instance. That entry is never cleared either.

These cycles are recovered by `gc_collect_cycles()`, which the SDK fires
every 1000 destroys or 30 seconds. Under the user's described pattern
(many UI-triggered restarts in a short window) they will pile up between
GC ticks; with a fat workflow object that is enough to look like a 1 GB
leak even though `gc_collect_cycles` would eventually free it. Workers
killed by RoadRunner's memory limiter while still reachable from the
supervisor would show up as zombie processes, matching the report.

This exact gap is closed by the **Destroyable** rework on master (PR #650
and follow-ups), in particular:

- `0c31984` Implement Destroyable interface in SignalQueue
  (clears `consumers`, `dynamicConsumer`, unsets `onSignal`)
- `82b3e24` feat: Call `destroy()` method on Workflows
  (Workflow user objects can opt into cleanup; `WorkflowContext::destroy()`
  is reordered to destroy dispatchers before unsetting the instance)
- `5a2762c` fix: make Instance::destroy() idempotent

`git tag --contains 0c31984 82b3e24` — none of these are in v2.14.1; they
ship on master only.

## Why the simple repro doesn't trip the cycle

`AwaitWithTimeoutWorkflow` doesn't `attach` a signal handler, so
`SignalQueue::$consumers` stays empty. `SignalQueue::$onSignal` *is* set
(by `Process::__construct`), but in v2.14.1 the only inbound reference to
`WorkflowInstance` from outside is `WorkflowContext::$workflowInstance`,
which `WorkflowContext::destroy()` unsets — so the WorkflowInstance becomes
unreachable, refcount drops to zero, and the SignalQueue (with its
onSignal closure) is freed along with it. The closure's reference to
Process clears at the same moment. No cycle has time to form.

A repro that *does* trip the cycle needs a workflow that binds a signal
consumer that captures the workflow object back to its WorkflowInstance,
**and** a destroy path that runs while at least one Deferred or scope
holds a strong external ref into that graph. The Destroyable acceptance
test on master (`tests/Acceptance/Extra/Stability/DestroyableTest`) is
shaped exactly that way. We didn't replay it here because the recorded
frame logs in `tests/Fixtures/data` don't cover it, but it's the obvious
next step if someone wants reli to point at the cycle directly.

## Recommendations for reporters

1. Backport the Destroyable PR (#650) — it eliminates the cycles outright,
   so workers stop depending on `gc_collect_cycles()` timing.
2. As a stop-gap on v2.14.x, lower
   `Internal\Support\GarbageCollector::GC_THRESHOLD` (currently 1000) and
   `GC_TIMEOUT_SECONDS` (currently 30) so the SDK runs cycle GC more
   often. This trades CPU for RSS, which is the right trade for the
   reported workload.
3. If the workflow object holds large state, implement
   `Internal\Destroy\Destroyable::destroy()` on it (master only) and
   clear those fields explicitly. On v2.14.1 the same effect can be had
   by `unset()`-ing in the workflow handler's `finally`.

## reli command reference used

- `inspector:memory:dump -p <pid> -o <RDUMP>` — capture target process
  memory.
- `inspector:memory:analyze <RDUMP> -o <X.rmem>` — convert the raw dump
  into the queryable `.rmem` format.
- `inspector:memory:compare <baseline.rmem> <target.rmem>` — node/edge
  diff with per-type and per-bin deltas.

The `inspector:memory:dump` output is *not* the `.rmem` magic file
(`5244554d` = `RDUM`) — it's a raw RDUMP. `inspector:memory:compare`
expects the analyzed `.rmem` produced by `inspector:memory:analyze`.
