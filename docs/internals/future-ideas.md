# Future-work ideas for reli

Notes from a long discussion that came out of the comparison-page
work. This is a mix of:

- **Bugs found** — concrete things to fix in current reli.
- **Design ideas** — new features that would extend reli's
  reach. These are *not* committed roadmap items; they're
  speculation we want a record of so they don't get lost.

The page is opinionated — a working scratchpad, not a
specification.

## Bugs found while benchmarking

### Frame identity via `op_array` pointer + post-hoc resolution

A more thorough fix for the FFI exception below — and a generally
useful property — is to make rbt's frame identity the
`op_array` pointer (PHP's stable per-function-definition handle)
rather than the resolved function name. Sketch:

- Each frame in a sample is recorded as `(op_array_ptr, lineno)`.
- A separate dictionary maps `op_array_ptr → (function_name_id,
  file_id)`. Filled in lazily as samples succeed.
- If a sample fails to read the function name (e.g. the underlying
  `zend_string` was being mid-modified), the frame still gets
  recorded with its op_array pointer; the dictionary just doesn't
  have a name for it yet.
- A subsequent sample that *does* read the name successfully
  populates the dictionary. The earlier unresolved frames
  retroactively pick up the name through the shared key.
- `rbt:analyze` does a final post-process pass: any op_array still
  unresolved at end of trace gets a synthetic
  `<unresolved@0xABC>` placeholder, which is at least
  identifiable in the report.

Properties this gives:

- **Lossless degradation on corrupt read.** A single bad sample
  is survivable; the rest of the trace is unaffected.
- **No more `FFI\ParserException` aborts** in `inspector:trace`.
- **Identity for unloaded functions.** If a function definition
  goes away mid-trace (rare but possible with autoloaded
  closures, eval'd code), its frames stay distinguishable rather
  than being silently merged into whatever `op_array` slot got
  reused.

Caveats:

- rbt format-version bump is needed; old `.rbt` files don't carry
  op_array pointers.
- `TraceCache` key changes from function-name to
  op_array-pointer; small refactor.

(`op_array` itself doesn't get *recycled* by PHP for a different
function — there's no slot-reuse mechanism, an `op_array` *is*
the function definition. So pointer identity is stable within
a process. Plain memory recycling after free is the only
ambiguity case, and the freed function is gone by then anyway,
so practically a non-issue.)

Worth doing because the current behaviour — crashing on a
transient corrupt read — is the most disruptive outcome for a
tool that's supposed to be unobtrusive when attached to a
production target.

### `FFI\ParserException: Negative array index` on fast-moving targets

Reproducer: attach reli's `inspector:trace` to a target that's
churning through diverse zend_string objects (e.g. a Laravel
route handling 2000 requests in a tight loop). Roughly 1 in 5
runs:

```
PHP Fatal error:  Uncaught FFI\ParserException: Negative array index at line 1
  in src/Lib/Process/MemoryReader/MemoryReader.php:61
```

Trace path:

1. `CallTraceReader::readCallTrace` walks frames
2. `ZendExecuteData::getFunctionName` reads the call's function
3. `ZendFunction::getFunctionName` returns the function-name
   `ZendString`
4. `ZendString::toString` calls `Dereferencer::deref` with the
   string's `len` field as the requested byte count
5. The target was mid-modifying that `zend_string` between our
   reads, so `len` came back as garbage (negative or huge)
6. `MemoryReader::read` calls `FFI::new("unsigned char[$size]")`
   which throws

`RetryOnExceptionMiddleware` doesn't catch `FFI\ParserException`,
so the whole `inspector:trace` invocation dies.

Suggested fixes (small, independent):

1. Bounds-check `$this->len` in
   `ZendString::toString` — if it's outside `[0, sizeof(zend_string * 2^16)]`
   or so, return a placeholder like `'<garbage>'` instead of
   reading.
2. Add `FFI\ParserException` to the
   `ExitLoopOnSpecificExceptionMiddleware` retry-recoverable
   set, so a single bad sample doesn't kill the whole run.

The first fix is the right place; the second is a
defence-in-depth fallback.

### Cache invalidation tied to `sapi_globals` timestamp

reli's `TraceCache` keys on a sapi_globals timestamp to detect
"new request started" and invalidate stale entries. Limitations
of the polling approach:

- Detection lag ≤ sample period (1 ms by default).
- Misses the SAPI startup/shutdown *event* itself — only
  observes the after-effect.
- Race with the SAPI write: reading a half-updated timestamp
  is possible.
- Particularly painful for short-lived FPM workers that finish
  a request faster than two sample periods.

A cleaner mechanism would be to drive cache invalidation off
explicit `php_request_startup` / `php_request_shutdown` events.
With ptrace alone there's no good way; with eBPF uprobes (see
below) it's a single hook.

## Design ideas: eBPF-augmented reli

reli is currently strict about "no extension in the target". The
purest external-only mechanism we have is `process_vm_readv` +
ptrace. eBPF uprobes are a *second* purely-external mechanism
(kernel sets a breakpoint at a userspace symbol; the eBPF
program runs in kernel context; reli reads from a perf ring
buffer). The key property: **target binary needs no
modification, no extension load, no recompilation**.

This opens the door to event-driven observation of things that
are currently invisible to a polling-only external sampler.

### Easiest first hooks

In rough order of (value × ease):

1. **`php_request_startup` / `php_request_shutdown`** —
   - Replaces the current `sapi_globals`-timestamp polling for
     cache invalidation with an event push.
   - Enables per-request trace bucketing (sample stream tagged
     with request ID).
   - Useful side-effect: very short FPM requests stop being
     invisible.
2. **`zend_throw_exception` / `zend_throw_exception_ex` /
   `zend_throw_error`** —
   - First-class support for `inspector:watch
     --on-throw=<class>` predicates that *can't* miss events
     the way polling does.
   - Use cases: "snapshot when `App\PaymentFailedException` is
     thrown", "trigger memory dump on uncaught exceptions
     before SAPI's abort handler runs", "detect exception
     storms".
   - Current watch can't reliably trigger on short-lived
     exception flows because the exception object is freed
     before the next polled sample.
### Medium-term hooks

3. **PDO `prepare` / `execute` pairing** —
   - uprobe `zim_PDO_prepare` to capture SQL, `zim_PDOStatement_execute`
     to capture bindings + duration. Pair by PDOStatement
     pointer.
   - Same shape as APM auto-instrumentation (Datadog / NewRelic
     / Tideways) but external.
   - Watch use cases: "snapshot when any PDO query > 100 ms",
     "alert on N+1 query patterns", "dump heap when DEADLOCK
     error returns".
4. **Other I/O hot points** — `curl_exec`, `fopen` / `fwrite` /
   `fread`, `apcu_*`, `memcached_*`, `redis_*`, `session_*`.
   Same pattern: pair entry / return uprobes for duration,
   capture the arg of interest.
5. **Statistical allocation profiling (Datadog category B1)** —
   uprobe Zend memory allocator entry, sample every N MB
   allocated, push perf event to reli for aggregation. Adds
   reli to category B1 (allocation flow) without giving up the
   "no extension" stance.

### Hooks we considered but ruled out

- **`zend_string_init_*` / `zend_string_release_*`.** Could in
  principle let reli know when a `zend_string` is being
  re-initialised, side-stepping the FFI corrupt-read race. But
  PHP creates strings constantly — every variable name, every
  literal, every string-op result — so the event volume is many
  millions per second on a busy app. Even a kernel-side filter
  would have trouble keeping up with that uprobe rate, and the
  per-event cost would dominate target wall time.
  The `op_array`-keyed frame identity + post-hoc resolution
  approach in the "Bugs found" section is the right fix for
  the underlying race; we don't need the string-lifecycle
  uprobes.

### What this would cost reli

- A new `CAP_BPF` (or `CAP_SYS_ADMIN`) requirement on top of
  the current `CAP_SYS_PTRACE`.
- Linux 5.x+ with libbpf available. Older CentOS / RHEL needs
  a fallback.
- A new code path (libbpf via FFI, or out-of-process bpf
  helper) that's ~peer to the current ptrace path rather than a
  replacement for it. Existing functionality should keep
  working without `CAP_BPF` available.

### Watch as the natural integration point

`inspector:watch` is the place where the sample-vs-event
distinction matters most. Today watch is polling-driven over a
small set of predicates (memory threshold, function entry,
variable match). With uprobe events as a second predicate
source, watch becomes:

```
predicate sources:
  poll-based (existing)
    - memory threshold
    - variable change
    - function-entry-via-sampling
  event-based (new, via uprobes)
    - request start/end
    - exception throw (filtered by class)
    - PDO query (filtered by SQL pattern, duration threshold)
    - allocation rate threshold

actions:
  - capture trace
  - capture memory dump
  - run a custom callback
```

The "capture state at the *exact instant* the predicate fires"
guarantee is the killer feature here. Polling can't do it for
short-lived events.

## A note on the "process_vm_writev injection" alternative

We considered (briefly, mostly for fun) using `process_vm_writev`
+ ptrace to inject a small shellcode that calls
`zend_mm_set_custom_handlers` from outside, giving reli the
allocation hooks it would need without an extension or eBPF.

Technically possible. Operationally indistinguishable from
malware injection, requires per-arch shellcode and per-PHP-version
ABI knowledge, fragile against `yama.ptrace_scope`, and segfaults
the target if anything goes wrong. We're not pursuing it; eBPF
uprobes give the same external-only property without the
sharp edges.

## phpspy upstream contribution candidate

phpspy's per-frame `process_vm_readv` syscall pattern means
sustained sample rate falls off with stack depth (in our bench:
~230 samples/s sustainable at depth=200, vs. reli's ~700+). reli
takes a different approach — single bulk read of the VM stack
region, then walk in user space — which doesn't have that
depth dependency. The implementation is small enough that
contributing the same pattern back to phpspy upstream is
plausible.

Worth proposing rather than treating as a reli-only advantage.
A faster phpspy is also good for the ecosystem reli sits in.
