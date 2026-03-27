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

### Expected Impact

- **Consistency**: window shrinks from ~3,480us to ~1-4us, potentially making
  `-S` unnecessary and eliminating broken samples without stopping the target
- **Throughput**: syscall count drops from ~174 to 2 per trace. However, the
  bottleneck is PHP-side processing, so the improvement may be modest unless
  the Pointer/FieldReader layer is also optimized
- **Sampling bias**: if `-S` becomes unnecessary, the SIGSTOP delivery bias
  is avoided entirely, producing accurate native trace distributions
