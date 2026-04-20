# Trace Consistency and Sampling Bias Investigation

Investigation of how the `--stop-process` (`-S`) option affects trace consistency and
sampling distribution. Findings obtained through reli-on-reli self-profiling and
comparison with phpspy.

## Background

reli's sampling profiler has two modes:

- **Without `-S`**: reads target memory via `process_vm_readv` while the target keeps running
- **With `-S`**: pauses the target via `PTRACE_ATTACH` (`SIGSTOP`) before reading memory

## Finding 1: `-S` Flag VALUE_OPTIONAL Bug (Fixed)

When `-S` was passed without an explicit value, Symfony Console's `VALUE_OPTIONAL`
returned `null`, which the code converted to `false` — **silently ignoring the flag**.

```
-S               → false (bug: no effect)
-S=1             → true
--stop-process=1 → true
not specified    → false
```

Fixed by using `hasParameterOption()` to detect the flag's presence without a value.
After the fix, `-S` eliminates truncated frames (internal functions appearing as
single-frame samples) as expected.

## Finding 2: PTRACE_ATTACH SIGSTOP Sampling Bias

### Observation

When profiling inner reli with `-S` enabled, the measured user:sys ratio from
`/proc/pid/stat` and the sample distribution diverge significantly.

```
Actual (bash time / /proc/pid/stat):
  user: 81%,  sys: 19%

Sample distribution (-S on, PHP frames):
  process_vm_readv (FFI/syscall): 68%
  PHP userland:                   30%

Sample distribution (-S on, native frames):
  libc::process_vm_readv:          92%
  PHP userland:                     6%
```

### Cause

`PTRACE_ATTACH` sends `SIGSTOP` to the target, but the kernel delivers `SIGSTOP`
at **syscall boundaries or scheduler preemption points**. While the target is in
user mode executing PHP bytecode, `SIGSTOP` delivery is deferred until the next
syscall entry (e.g., FFI-based `process_vm_readv`).

This causes **systematic oversampling of syscall-internal states**.

### Verification with phpspy

phpspy's `-S` (pause-process) option exhibits the same bias:

```
phpspy without -S:  process_vm_readv = 12%,  PHP userland = 88%
phpspy with -S:     process_vm_readv = 92%,  PHP userland =  8%
```

Enabling `-S` immediately shifts the distribution to match reli-on-reli results.
This confirms the bias is inherent to the `PTRACE_ATTACH` + `SIGSTOP` mechanism,
not specific to reli.

## Finding 3: Without `-S`, reli-on-reli Shows Reverse Bias

### Observation

Profiling inner reli without `-S` causes `process_vm_readv` to **completely
disappear** from frame 0.

```
reli without -S:
  process_vm_readv:   0%
  PHP userland:      61%
  broken (<unknown>/<internal>/truncated): 23%
```

### Cause

When inner reli is inside an FFI call (C code), `current_execute_data` points to
the FFI call frame. However, outer reli's PHP-based memory reading is too slow —
by the time it finishes reading, inner has already returned from FFI and
`current_execute_data` has changed. The FFI frame is either read inconsistently
(appearing as `<internal>` or `<unknown>`) or missed entirely.

**Slow reads cause fast operations (syscalls) to be systematically dropped.**

## Finding 4: Only phpspy Without `-S` Achieves Both Accuracy and Consistency

```
                    Distribution accuracy    Stack consistency    Broken rate
phpspy without -S        Accurate                High               ~0%
phpspy with -S        Biased to syscalls          High               ~0%
reli with -S          Biased to syscalls          High               ~2%
reli without -S       Biased to userland          Low               ~23%
```

phpspy without `-S` is the only configuration that achieves both accurate
distribution and high consistency. This is because C-native reads complete fast
enough to snapshot the stack before the target's state changes.

## Throughput and Cost Comparison

### Throughput by Stack Depth (sleep=0, no -S)

```
                    reli                    phpspy
              readv/loop  loops/s     readv/loop  loops/s     ratio
Shallow(3)          9       735            ~3    10,240       x14
Deep(22)           37       225            ~4     6,947       x31
Laravel(75)       174        53            ~5     1,892       x36
```

Total readv call counts are similar (~27,000-29,000/3s).
The throughput gap is dominated by PHP overhead between readv calls
(FFI invocation, Pointer construction, type resolution, etc.).

### CPU Time per Trace (Laravel 75 frames)

```
              user/trace    sys/trace    total/trace    user:sys
phpspy         0.19ms        0.30ms        0.50ms       0.65:1 (sys-dominated)
reli           0.81ms        0.15ms        0.96ms       5.3:1  (user-dominated)
```

CPU per trace differs by ~2x. Much smaller than the throughput gap (36x).

### readv Cost by Transfer Size (C benchmark, 100K iterations)

```
      8 bytes:     423 ns
     64 bytes:     424 ns
    256 bytes:     425 ns
   1024 bytes:     445 ns
   4096 bytes:     460 ns  (1 page)
  16384 bytes:   1,000 ns  (4 pages)
  65536 bytes:   3,721 ns  (16 pages)
 262144 bytes:  14,044 ns  (64 pages)
1048576 bytes:  94,695 ns  (256 pages)
```

`process_vm_readv` cost is nearly constant below one page (~430ns), then
scales roughly linearly with the number of pages crossed (page table walk cost).

## Proposed Improvement: Bulk VM Stack Copy

### Current Consistency Windows

```
reli (current):   first readv to last readv = ~3,480 us
phpspy:           first readv to last readv = ~500 us
single bulk readv (64KB): ~3.7 us
single bulk readv (16KB): ~1.0 us
```

### 2-Pass Scatter-Gather Approach

1. **Pass 1**: bulk-copy the VM stack in a single `process_vm_readv`
   - Actual stack usage is typically a few KB to tens of KB
   - ~1-4us depending on size (16KB = ~1us, 64KB = ~3.7us)
   - Parse `execute_data` chain locally
   - Collect all heap pointers (`func`, `opline`, string addresses)
2. **Pass 2**: scatter-gather read all heap data in a single `process_vm_readv`
   - `IOV_MAX` = 1024; 75 frames need ~300-450 iovecs, well within the limit
   - Scattered across many pages, so cost depends on the number of distinct pages touched

Total: 2 syscalls, consistency window = ~1-4us for stack snapshot + local processing
before Pass 2. Much smaller than the current ~3,480us window.

### Implementation Outlook

`MemoryReaderInterface` is cleanly abstracted, so a **buffered decorator** may
be sufficient with no changes to existing types:

```php
class BufferedMemoryReader implements MemoryReaderInterface {
    public function prefetch(int $pid, int $address, int $size): void {
        // Bulk-copy VM stack in one process_vm_readv call
    }

    public function read(int $pid, int $address, int $size): CData {
        if (/* address falls within prefetched buffer */) {
            return /* slice from local buffer */;
        }
        return $this->inner->read($pid, $address, $size);
    }
}
```

Existing `ZendExecuteData`, `FieldReader`, `Pointer`, `LazyDereferencer`, etc.
require no changes. The buffer is transparent — callers see the same
`MemoryReaderInterface` regardless of whether data comes from local memory
or a remote process.

### VM Stack Size Measurements

Measured via `inspector:memory --pretty-print` on PHP 7.4 mod_php workers:

```
                    vm_stack_usage    vm_stack_total    readv cost (bulk)
Shallow (3 frames):      448 bytes      262,144 bytes     ~430 ns (< 1 page)
Deep (22 frames):      1,912 bytes      262,144 bytes     ~430 ns (< 1 page)
Laravel (75 frames):   9,232 bytes      262,144 bytes     ~460 ns (~2 pages)
Inner reli itself:    12,128 bytes      262,144 bytes     ~460 ns (~3 pages)
```

Key observations:
- `vm_stack_total` is always 256KB (pre-allocated), but `vm_stack_usage` is tiny
- 75 frames = ~9KB. Per frame ~120 bytes (9232 / 76 call frame headers)
- **Only the used portion needs to be copied** (`top` to current stack position),
  not the entire 256KB allocation
- At ~9KB, the bulk copy fits in 2-3 pages → ~460ns, versus the current
  174 individual readv calls via FFI

### Prerequisites

- Need to locate the VM stack: read `EG(vm_stack)` to get the current stack
  segment's base address and size
  - reli already has this infrastructure: `ZendExecutorGlobals::$vm_stack`
    → `ZendVmStack` with `$top`, `$end`, `$prev`, and `getSize()`
  - The memory profiler (`inspector:memory`) already reads and analyzes
    VM stack segments via `VmStackMemoryLocation`
  - Prefetch range: from `ZendVmStack` header address to `$end` covers
    all `execute_data` frames in the current segment
  - Multiple segments (linked via `$prev`) may exist but the current
    segment typically contains all active frames
- VM stack covers `execute_data` frames; heap-resident data (`zend_function`,
  `zend_string` for function/file/class names) still requires remote reads
  - Pass 2 scatter-gather can batch these into a single syscall
- `zend_function` and `zend_string` are stable during a request (not GC'd),
  so the inconsistency risk between Pass 1 and Pass 2 is negligible

### Configuration Considerations

The bulk copy approach should be opt-in or configurable, because:

- **Large VM stacks**: deeply recursive code, heavily nested generators, or
  frameworks with very deep call chains can push `vm_stack_usage` well beyond
  the typical ~9KB. If the used portion spans many pages, the bulk copy cost
  grows linearly (~430ns per page) and could exceed the benefit
- **Multiple stack segments**: `ZendVmStack` segments are linked via `prev`.
  Usually only the current segment matters, but edge cases may span multiple
  segments
- **Trade-off awareness**: the benefit (consistency without `-S`) vs cost
  (extra memory copy per sample) is only meaningful at high sampling rates.
  At the default 100Hz, even 174 individual readv calls fit within the 10ms
  budget with room to spare

Possible option design:

```
--bulk-stack-copy[=<max-size>]
```

- Not specified: current per-field behavior (safe default)
- `--bulk-stack-copy`: enable with a sensible default max (e.g., 64KB)
- `--bulk-stack-copy=256K`: enable with explicit max size; if actual usage
  exceeds this, fall back to per-field reads

This way advanced users who understand the consistency trade-offs can enable
it, while the default behavior remains unchanged.

### Expected Impact

- **Consistency**: window shrinks from ~3,480us to ~1-4us, potentially making
  `-S` unnecessary and eliminating broken samples without stopping the target
- **Throughput**: syscall count drops from ~174 to 2 per trace. However, the
  bottleneck is PHP-side processing, so the improvement may be modest unless
  the Pointer/FieldReader layer is also optimized
- **Sampling bias**: if `-S` becomes unnecessary, the SIGSTOP delivery bias
  is avoided entirely, producing accurate native trace distributions

## Implementation Results

The `--bulk-stack-copy` option has been implemented with scatter-gather readv,
along with several hot-path optimizations. Below are the measured results.

### Architecture

```
Step 1: Read EG(vm_stack), EG(vm_stack_top), EG(vm_stack_end) individually
        → determine prefetch range (vm_stack to vm_stack_top + 16KB margin)
Step 2: Scatter-gather process_vm_readv in ONE syscall:
          iov[0] = EG(current_execute_data)   — 8 bytes
          iov[1] = VM stack with margin        — ~10-25 KB
Step 3: Walk execute_data chain from local buffer (readRawInt64 + unpack)
Step 4: Resolve function names from TraceCache (heap reads cached across traces)
```

Key components:
- `BufferedMemoryReader`: decorator with scatter-gather readv and two fixed
  buffer slots. `readRawInt64()` bypasses FFI entirely using `unpack('P', ...)`
- `CallTraceReader`: pre-computed field offsets, direct buffer reads for chain
  walk and frame field access (func, opline), func=NULL frame skip, partial
  trace on read failure instead of full retry

### Finding 5: func=NULL Frames at Stack Top

Debug analysis revealed that the `<unknown>` frames (previously ~30% in
reli-on-reli without `-S`) are caused by **partially-initialized execute_data
frames** on the VM stack, not by heap read failures.

```
func=NULL: ced=0x7f2a686188c0 in_buf=Y func=0x0 prev=0x7f2a64201690
```

The VM stack snapshot captures a frame mid-push where `func` has not yet been
written. The `prev_execute_data` pointer is always valid. Skipping the
func=NULL frame and using `prev_execute_data` as the effective stack top
eliminates `<unknown>` entirely — the prev frame is the function that was
actually executing when the call was initiated.

### Reli-on-Reli Benchmark (inner reli profiling FFI target)

```
Actual CPU ratio: user=81%, sys=18%

                       user/tr  sys/tr  total/tr  traces/s
reli normal (before)    0.83ms   5.92ms   6.75ms       147
reli --bulk-stack-copy  0.12ms   0.23ms   0.35ms     2,841
reli -S                 0.22ms   0.04ms   0.26ms     3,415
reli --bulk + -S        0.09ms   0.02ms   0.12ms     7,946
phpspy (no -S)          0.01ms   0.08ms   0.09ms     6,053
phpspy -S               0.01ms   0.09ms   0.12ms     5,258
```

### Optimization Progression (reli --bulk + -S, reli-on-reli)

```
                            user/tr  total/tr  traces/s  vs phpspy-S
bulk-stack-copy only         0.15ms    0.18ms    4,960      -6%
+ unpack fast path           0.12ms    0.14ms    5,940     +12%
+ fast chain walk            0.11ms    0.13ms    6,367     +21%
+ inline field reads         0.09ms    0.12ms    7,013     +33%
```

### Per-Trace CPU Comparison

```
              user     sys      total    traces/s   strategy
reli bulk+-S   0.09    0.02     0.12      7,946     scatter-gather + TraceCache
phpspy -S      0.01    0.10     0.12      5,258     per-field readv (C native)
```

reli and phpspy arrive at the same total cost (0.12ms/trace) via opposite
strategies: reli's user cost is higher (PHP overhead) but sys cost is 5x
lower (one scatter-gather readv + TraceCache eliminates most syscalls).
phpspy's C-native user cost is negligible but it issues more readv calls
per trace.

### Why `-S` + bulk Outperforms phpspy

1. **Scatter-gather readv**: one syscall reads `current_execute_data` + the
   entire VM stack, vs phpspy's ~5 individual readv calls per trace
2. **TraceCache**: `zend_function` / `zend_string` are immutable during a
   request. After the first trace, heap reads drop to near zero. phpspy
   re-reads function names every trace
3. **Fewer kernel entries**: ~5,000 syscalls/s for reli vs ~20,000 for phpspy,
   reducing kernel-side overhead (page table walks, context switches)

### Remaining Limitations

- **Without `-S`**: reli 2,841/s vs phpspy 6,053/s. PHP-side processing
  takes long enough that the target's state changes between reads, causing
  partial traces and wasted work. C-native phpspy completes reads before
  the target moves
- **CPU saturation**: without `-S`, three processes (target + inner reli +
  outer reli) compete for CPU. With `-S`, the target is paused, leaving
  more CPU for the profiler
- **JIT**: PHP 8.4 tracing JIT provides only ~6% improvement. The remaining
  PHP overhead (method dispatch, object creation, array operations) is not
  amenable to JIT optimization
- **Further optimization**: would require either a C extension for the core
  readCallTrace loop, or a C extension wrapping the FFI syscall layer
  (process_vm_readv, ptrace) to eliminate per-call FFI overhead
