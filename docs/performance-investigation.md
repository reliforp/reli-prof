# Sampling Profiler Performance Investigation

Performance investigation of reli-prof's sampling profiler (`inspector:trace`), benchmarked against phpspy.

## Environment

- PHP 8.4.18 (NTS), FFI / pcntl enabled
- phpspy v0.7.0 (native C)
- Targets: simple loop (depth=4) and Laravel-like 70-class deep stack (depth=70)

## Baseline Measurements

### Comparison with phpspy (default 10ms interval, 10 seconds)

| Metric | reli (before) | reli (after) | phpspy |
|--------|:---:|:---:|:---:|
| CPU% (depth=4) | 7% | 3% | 1% |
| CPU% (depth=70) | 14% | 9% | 4% |
| User (depth=4) | 0.55s | 0.23s | 0.08s |
| User (depth=70) | 1.19s | 0.78s | 0.12s |
| Sys (depth=70) | 0.30s | 0.19s | 0.28s |
| Memory (RSS) | 55MB | 55MB | 4.7MB |
| Samples/10s | ~955 | ~965 | ~975 |

### Impact on Target Process

Both reli and phpspy show zero measurable impact on the target process CPU (1000 ticks/10s, no deviation).

## Improvements Made

### 1. Template eval cache (high impact)

**Change**: `TemplatedCallTraceFormatter::format()` was calling `include` on the template file every sample. Replaced with a one-time `file_get_contents` + `eval` to compile the template into a cached closure.

**Effect**: CPU 7% → 3% at depth=4 (58% reduction)

**Root cause**: Without CLI opcache, `include` issues `openat` → `fstat` → `read` → `close` (4 syscalls) every call. At ~950 samples/10s, that's 3,800 unnecessary file I/O operations.

**Caveat**: Embedding `?>` in a PHP string literal (even inside a comment) causes the PHP parser to interpret it as end-of-PHP-block. Must split as `'?' . '>'`.

### 2. StreamOutputChannel (Symfony Console bypass)

**Change**: Route trace output directly to `fwrite()` instead of Symfony Console's `OutputInterface::write()`.

**Effect**: Eliminates `normalizer_is_normalized` (6%) + `grapheme_strlen` (2%) = 8% of active CPU samples shown in profiling. The `-o /dev/null` benchmark already used the file output path, so the direct numerical improvement is small there, but stdout output benefits.

**Root cause**: Symfony Console's `Output::write()` → `OutputFormatter::format()` runs Unicode normalization on every call. Trace output is ASCII-only, making this unnecessary.

## Approaches Tried Without Net Benefit

### JIT (opcache.jit=tracing)

**Conclusion**: No benefit. Actively harmful.

| Setting | User (depth=4) |
|---------|:---:|
| No JIT | 0.23s |
| tracing (default thresholds) | 0.31s (+35%) |
| tracing (hot_loop=1, hot_func=1) | 0.38s (+65%) |
| function (hot_func=1) | 0.88s (+283%) |

**Root causes**:
- JIT buffer usage: 2,533 bytes out of 64MB — virtually no native code generated
- `Loop::invoke()`'s `while(1) { $this->process->invoke() }` is a virtual method call (interface dispatch) that the JIT tracer cannot follow
- FFI calls (`process_vm_readv`, `FFI::cast`, `FFI::new`) abort JIT trace recording
- Profiling counter overhead remains without any compilation benefit
- Lower thresholds increase counter update frequency, worsening performance

### opcache (without JIT)

**Conclusion**: No effect after the template fix.

The template `include` was the main source of per-sample file I/O. After switching to eval-cached closures, there are no remaining `include` calls in the hot path for opcache to optimize.

### PHP 8.4 Property Hooks

**Conclusion**: Significant microbenchmark improvement (`__get` + `match` → hooks: -46%), but zero effect in the real application.

**Root cause**: TraceCache caches ZendFunction / ZendString objects across samples. Cached objects have their properties already resolved, so neither `__get` nor hooks fire. Only ZendExecuteData (created fresh each sample) benefits from hooks, but its cost is ~2.5% of total.

### Batch readv (prefetch)

**Conclusion**: Counterproductive — TraceCache already caches metadata, making prefetch redundant.

- `process_vm_readv` itself takes ~841ns regardless of iov count (10x speedup potential from batching)
- However, PHP/FFI setup cost for prefetch (`FFI::cast` × N, address computation) exceeds the savings
- A C helper (`libbatch_readv.so`) that handles iov setup natively, combined with offset-based lazy casting, reached break-even
- **Without prefetch: depth=70 user=0.78s; with prefetch: user=1.00s (+22% worse)**

**When prefetch would help**: Cases where TraceCache is ineffective (e.g., web servers with frequently changing request times). For long-running CLI scripts, TraceCache covers all metadata.

### Flat loop (middleware chain elimination)

**Conclusion**: 15% improvement at depth=70 (user 1.17s → 1.00s). Reverted due to maintainability tradeoffs.

Collapses 6 levels of virtual dispatch (Loop → ExitOnException → Retry → KeyboardCancel → NanoSleep → Callable) into a single flat `while` loop. More effective at deeper stacks where per-sample loop time is longer.

**Future consideration**: A mechanism that inspects the middleware configuration and "compiles" standard configurations into a flat loop could preserve both flexibility and performance. The daemon-side AsyncLoop could benefit from the same pattern.

## Understanding the Bottleneck Structure

### readCallTrace Internal Profile (depth=4, 967 samples)

| Phase | Time | us/sample | Share | Description |
|-------|:---:|:---:|:---:|-------------|
| setup | 32ms | 33us | 32% | getDereferencer + getCurrentEDP + cacheSetup |
| walkStack | 19ms | 20us | 19% | prev_execute_data traversal (process_vm_readv) |
| buildFrames | 44ms | 46us | 45% | Frame metadata reading + CallFrame construction |

### Self-profiling with reli (306 active samples, sleep excluded)

| Function | Exclusive % | Category |
|----------|:---:|----------|
| `<unknown>` + FFI::cast/new/addr | 23% | FFI bridge (not optimizable from PHP) |
| `CallableMiddleware::invoke` | 12% | Middleware dispatch overhead |
| `MemoryReader::read` | 9% | process_vm_readv syscall |
| `normalizer_is_normalized` + `grapheme_strlen` | 8% | Symfony Console Unicode processing |
| `is_null` | 6% | Null checks (high call count) |
| `prefetchFrameData` | 5% | Prefetch logic |
| `getFunctionName` | 5% | Function name resolution chain |

Note: `is_null()` is compiled to the same opcode as `=== null` in PHP 8.4, so replacing it yields no improvement. Its visibility in the profile is due to call frequency, not per-call overhead.

### Behavior at Different Stack Depths

As stack depth increases:
- **reli's sys time approaches phpspy's** — TraceCache eliminates metadata readv calls effectively
- **reli's user time grows linearly** — PHP method dispatch, object creation, and TraceCache lookups scale with depth
- **phpspy's sys grows with depth** but user stays nearly flat — C struct operations are negligible

At depth=70: reli sys=0.19s vs phpspy sys=0.28s — **reli actually wins on kernel time** thanks to TraceCache. The entire gap is in user time (0.78s vs 0.12s).

### Fundamental Constraints of the PHP + FFI Architecture

- `FFI::new` ~400ns, `FFI::cast` ~200ns, `process_vm_readv` ~841ns — none optimizable by JIT
- PHP method call overhead (~50-100ns) × depth × calls/frame dominates user time
- Even with TraceCache hits, PHP array lookup + object return overhead remains
- `new CallFrame` cost (103ns/frame) is only ~0.6% of total — not a bottleneck
- `__get` cost is only ~2.5% of total — not a bottleneck either
- The real cost is distributed across many small PHP operations that individually are cheap but accumulate at high depth

### Constraint on Self-profiling reli with reli

When profiling a process that calls `process_vm_readv` at high frequency, the profiler's own `process_vm_readv` calls contend for the kernel's `mm_struct` lock, causing extreme sample loss (normal: 4,300 samples/5s → against readv-spamming process: 1-21 samples/5s). Profiling reli running at the normal 10ms interval works fine (4,300 samples/5s).
