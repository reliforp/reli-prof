# Profiler benchmark results

> [!NOTE]
> **Most users do not need this page.** If you're picking a profiler
> for an operational decision, start with
> [`docs/comparison.md`](../comparison.md) and only come back here
> when you need raw numbers, methodology, or one-off findings to back
> up a specific call.

Numerical comparison data for the PHP profilers covered in
[`docs/comparison.md`](../comparison.md). The doc you're
reading is the heavy version — full tables, methodology notes, and
one-off findings encountered during the runs.
[`docs/comparison.md`](../comparison.md) is the lighter,
selection-oriented companion.

> Numbers are from the suite in [`docs/bench/`](.). PHP 8.4 NTS on a
> single Linux host, Docker not used (the sandbox couldn't reach
> public registries; see commit `5d1c8e9`). Wall times are the
> *target script's* own self-reported `microtime(true)` delta. CPU
> and RSS come from `/usr/bin/time -v`. All numbers are medians.

## Three classes of stack-walking cost

A useful organising idea before the numbers: a profiler reads the
PHP call stack at each sample, and *how* it reads it determines
how the cost scales with stack depth.

| Class | How the stack is read | Depth scaling | Examples |
|---|---|---|---|
| **In-process** | Sampler runs inside the PHP process; the stack is in the same address space, walked by following pointers in normal C | **Invariant** — depth doesn't matter much, no copy | Excimer (timer-spawned thread), Datadog (long-lived background pthread) |
| **External, bulk read** | Separate process; copies the entire VM stack region in one `process_vm_readv` and walks it in user space; binary analysis is cached | **Invariant** — depth doesn't matter, single (cheap) copy | reli |
| **External, per-frame syscall** | Separate process; one `process_vm_readv` per frame, chasing pointers across the address-space boundary | **Linear in depth** — N frames = N syscalls = N×latency | phpspy |

Per-sample cost of a 200-frame walk on this hardware:

```
in-process:           ~ µs       (cache-warm pointer chase)
external bulk read:   ~100 µs    (1 syscall + user-space walk)
external per-frame:   ~5 ms      (~200 syscalls × ~25 µs each)
```

The implications cascade through every other measurement here:
phpspy is great at typical stack depths (≤ 50) where the syscall
fan-out hasn't built up; reli's design pays off at deep stacks
(framework code with heavy DI, 100+ frames); in-process samplers
sit in their own niche where the stack walk is essentially free
because there's no copy.

## Time-axis overhead

### Headline: opcache + JIT enabled

This is the configuration most production CLI / FPM is in. Wall
times are medians; multipliers are vs. baseline (no profiler).
Sampling tools were measured at 1 ms sampling on long benches
(~1–3 s baseline, n=10); full-instrumentation tools on the
short benches (0.04–0.07 s baseline, n=3) since each cell of the
heavy ones takes minutes.

| Profiler | fib (recursive) | sort 100k×N | mandelbrot (no PHP fn calls) | depth-30 × N | depth-100 × N |
|---|---|---|---|---|---|
| **External samplers** (long, n=10) | | | | | |
| reli | 1.01× | 1.01× | 1.01× | 1.00× | 1.01× |
| reli — own PHP also JIT | 1.01× | 1.00× | 0.98× | 0.98× | 1.00× |
| phpspy | 1.01× | 1.00× | 1.01× | 0.99× | 1.01× |
| **In-process samplers** (long, n=10) | | | | | |
| Excimer (1 ms wall) | 1.01× | 1.01× | 1.01× | 1.00× | 0.98× |
| Datadog Profiler | 0.95× | 1.03× | 1.00× | 1.00× | 1.01× |
| **Full instrumentation** (short, n=3) | | | | | |
| Xdebug (profile mode) | 182× | 1.00× | 10.4× | 165× | 160× |
| xhprof (CPU+MEM) | 1640× | 1.02× | 0.98× | 1071× | 1271× |

Source: [`results/jit.csv`](results/jit.csv) and
[`results/jit-summary.txt`](results/jit-summary.txt).

Sampling tools sit within measurement noise of baseline regardless
of workload. Full-instrumentation tools show much larger
multipliers, and they grow further when comparing against a JIT-on
baseline because the instrumentation tool's own hook disables JIT
while baseline retains it (so the absolute instrumented run-time
is similar across JIT-on/off, but the baseline it's measured
against is faster under JIT-on). xhprof on a deep-stack workload
reaches 1271× in our suite; Xdebug profile mode reaches 160-180×.

> **Caveat for the heavy-tool multipliers.** Numbers like "xhprof
> 1640×" don't translate one-to-one across hosts. Across the
> sandbox these were measured on and a modern x86 desktop the
> JIT-on baseline only got ~1.5× faster, but xhprof on the same
> workload got ~27× faster — dropping the `fib-32` multiplier
> from 1640× to ~70-90×. So the multiplier is not simply a
> reflection of overall CPU speed (in that case both numbers
> would scale together and the multiplier would stay roughly
> constant); xhprof's per-call hook is doing some kind of work
> whose cost ratio against a baseline interpreter op shifts
> across hosts. One plausible mechanism is the cost of xhprof's
> timing calls: `strace -c` on this host shows xhprof issuing
> exactly two `clock_gettime` calls per PHP function entry/exit
> (43798 for fib(20)'s 21891 calls). Timing-call cost can vary
> substantially with libc / vDSO / kernel-syscall-path
> configuration, virtualisation overhead, and kernel mitigation
> settings, and that variation multiplies through xhprof's call
> density. Cache behaviour against xhprof's per-function hashmap
> is another candidate we haven't ruled out. We haven't measured
> the timing-call cost on the original sandbox host, so treat
> the *direction* (multiplier shrinks dramatically on faster /
> less-virtualised hosts) as established and the *exact mechanism*
> as still-needing-measurement. Xdebug profile mode shows the
> same multiplier-shrinkage shape (180× → ~95×). The qualitative ranking — sampling
> tools at baseline, full-instrumentation tools two-to-three
> orders of magnitude heavier — is robust; the absolute
> multiplier headline is not. Reproduction on a faster host is
> in [`results/COMPARE-vs-RESULTS.md`](results/COMPARE-vs-RESULTS.md).

(SPX is excluded from this row: under PHP 8.4 tracing JIT it
returns visibly wrong values — `fib(25)` returns `1407295`
instead of `75025` — and exits in ~1 ms. See "Notes & quirks"
below.)

### Reference: same workloads with JIT off

For a tools-only comparison without JIT in the picture, run the
same benches with `php -n` (which prevents opcache from loading).
This is also roughly the regime the older Tideways "Profiling
Overhead and PHP 7" blog post measured.

| Profiler | fib(32) | sort 100k×10 | mandelbrot | depth-30 | depth-100 |
|---|---|---|---|---|---|
| baseline | 0.110 s | 0.230 s | 0.086 s | 0.193 s | 0.211 s |
| reli | 1.0× | 1.0× | 1.0× | 1.3× | 1.1× |
| phpspy | 1.0× | 1.0× | 1.0× | 1.3× | 1.1× |
| Excimer | 1.0× | 1.0× | 0.8× | 1.0× | 1.0× |
| Datadog Profiler | 1.0× | 1.1× | 1.0× | 1.3× | 1.0× |
| SPX (sampling-period 1 ms) | 2.6× | 1.0× | 1.0× | 1.6× | 2.2× |
| Xdebug (profile mode) | 62× | 1.0× | 4.8× | 43× | 52× |
| SPX (default) | 41× | 1.0× | 1.0× | 19× | 30× |
| xhprof (CPU+MEM) | 635× | 0.9× | 1.0× | 285× | 401× |

Source: [`results/raw.csv`](results/raw.csv),
[`results/long.csv`](results/long.csv).

Same-shape findings as the JIT-on table: sampling tools at
~baseline, instrumentation tools scale with PHP-call density.
mandelbrot is essentially free for everything because it has no
PHP function calls in the hot path. Multipliers are 2-3× smaller
than the JIT-on row because the baseline is also 2-3× slower.

### A specific observation: SPX's two modes

SPX has a `SPX_SAMPLING_PERIOD` option that thins what the
per-call hook records (per the SPX README). The hook still fires
on every call, so per-call cost remains; the report-thinning
effect is what shows up in the wall-time numbers:

|  | fib-32 | depth-30 | depth-100 |
|---|---|---|---|
| SPX default (full instrumentation) | 41× | 19× | 30× |
| SPX `SPX_SAMPLING_PERIOD=1000` | 2.6× | 1.6× | 2.2× |

So the "sampling" mode reduces *I/O* overhead substantially but
doesn't eliminate the per-call hook cost. On call-light workloads
(sort, mandelbrot) both modes look about the same as baseline; on
call-dense workloads (fib, depth) the per-call cost still
dominates the residual.

## Sample capture at depth and rate

Wall-time stays at baseline (table above) for both phpspy and
reli at any rate or depth — the sampler can always *try* to
sample at the requested rate. The interesting question is how
many of those samples actually land. We swept rate ∈ {49, 99,
200, 500, 1000} Hz × depth ∈ {30, 100, 200} on the same
synthetic depth target (~5 s baseline at each cell). Numbers are
percent of *requested* samples that were captured — i.e.,
samples-actually-recorded / (rate × wall):

```
                49 Hz   99 Hz   200 Hz  500 Hz  1000 Hz
depth=30:
  phpspy        100%    95%     93%     92%     87%
  reli           98%    95%     92%     89%     78%

depth=100:
  phpspy         66%    66%     64%     62%     41%
  reli           97%    93%     91%     89%     71%

depth=200:
  phpspy         44%    40%     37%     29%     23%
  reli           98%    93%     91%     84%     58%
```

Source: [`results/cpu-rates.csv`](results/cpu-rates.csv).

Three operationally useful observations:

1. **At depth-30 stacks (typical Laravel-shaped frame counts) the
   two are indistinguishable** across the whole rate range, both
   ≥ 87%. Operational experience that "phpspy is the lighter
   tool at typical PHP depths" matches the data.

2. **phpspy's sustainable rate has a depth-dependent ceiling**
   that doesn't change by lowering the requested rate. Implied
   from captured-samples-per-second:

   | Depth | phpspy max sustainable rate |
   |---|---|
   | 30  | ≈ 870 samples/s |
   | 100 | ≈ 430 samples/s |
   | 200 | ≈ 230 samples/s |

   Above the ceiling, requested-but-not-captured samples are
   silently dropped (no record in the output stream). The
   ceiling falls out of the per-frame `process_vm_readv` design
   — N frames × per-syscall latency adds up.

3. **reli's bulk read sustains near-requested rate even at
   depth=200**: 98% at 49 Hz, 91% at 200 Hz. This is the
   workload range the bulk-read design targets.

### Profiler-side CPU at 1 kHz

> [!IMPORTANT]
> **The absolute CPU figures in this section are the most
> host-dependent numbers in this document, and the relative
> ordering of phpspy vs reli per-sample cost can flip on faster
> hardware.** phpspy's per-sample cost is dominated by `N ×
> process_vm_readv` syscall latency (linear in depth). reli's is
> dominated by PHP-side stack-walk execution (mostly flat in
> depth). On the slow sandbox where these numbers were measured,
> phpspy was the more expensive sampler at typical depths; on a
> modern fast x86 host phpspy is the *cheaper* sampler at depths
> ≤ 100 because syscall latency drops faster than PHP execution
> speeds up. The depth-scaling **shape** (phpspy linear, reli
> flat) is robust across hosts; the absolute numbers and the
> crossover depth are not. See
> [`results/COMPARE-vs-RESULTS.md`](results/COMPARE-vs-RESULTS.md)
> for a side-by-side reproduction on a different host.

Same depth sweep, this time looking at CPU consumed by the
sampler. At 1 ms sampling against a ~5 s ~5 s baseline target:

```
                  depth=10   depth=30   depth=100  depth=200
Excimer (Δ)        +2.05      +2.26      +2.08      +2.46
Datadog (Δ)        +0.77      +0.62      +0.69      +0.69
phpspy              3.85       3.89       4.91       5.03
reli (Δ)           +3.79      +3.81      +3.57      +3.92

RSS:
phpspy              8 MB
baseline target    28 MB
Excimer            30 MB
Datadog            43 MB
reli               53 MB
```

(Excimer / Datadog / reli columns are CPU above baseline; phpspy
is its own process so its CPU column is the pure sampler cost.
Source: [`results/cpu.csv`](results/cpu.csv).)

Per-sample cost (CPU ÷ samples actually captured) puts the
depth-scaling story in sharper focus:

|  | depth=10 | depth=30 | depth=100 | depth=200 |
|---|---|---|---|---|
| phpspy | 0.81 ms/sample | 0.80 | 2.21 | 4.25 |
| reli | 0.87 ms/sample | 0.85 | 0.79 | 1.18 |

phpspy's per-sample cost grows roughly linearly with depth, as
predicted by the per-frame syscall design. reli's is essentially
flat. At typical framework depths the two are within 7 % of each
other; at depth-200 reli is ~3.6× cheaper per sample.

In-process samplers don't appear in the per-sample column above
because their CPU shows up on a *separate kernel thread* in the
target process, not on a separate process — Excimer's
`SIGEV_THREAD` timer spawns a kernel thread on each tick, and
Datadog runs a long-lived background pthread. Either way the
target's main thread isn't blocked, which is why target wall
time stays at 1.0× even though +2 s of CPU is being burned. On a
multi-core host that's "free" in the sense of not affecting
request latency; on a single-core host it would compete for CPU.

### `--pause-process` (`-S`) trade-offs

Both phpspy and reli have a `-S` flag that pauses the target
during each sample read. phpspy's help text labels the flag as
"unsafe for production!"; reli's docs describe it as a precision
option. Wall time for the same depth target with `-S`:

```
                            depth=10   depth=30   depth=100  depth=200
phpspy default               5.16 s     5.43 s     5.34 s     5.13 s
phpspy -S                   10.06 s    18.71 s    52.08 s    78.59 s
phpspy -S -b 65536           9.57 s    18.95 s    59.05 s   107.32 s
reli default                 5.27 s     5.30 s     5.35 s     5.16 s
reli -S                      7.79 s     8.63 s    10.15 s    13.63 s
```

phpspy's `-S` extends target wall to 15× at depth=200 while
capturing ~15 % of the requested rate; reli's `-S` extends it to
2.6× and captures 72 %. The mechanism is the same as the
depth-scaling story above, amplified: with `-S` every sample's
read holds the target paused, so per-frame syscall latency ×
stack depth maps directly into target wall time. Increasing
phpspy's `-b` did not improve `-S` cells in our bench (the
107 s row above) — possibly because larger buffer flushes
extend the per-pause window, but we didn't dig further.

The takeaway: `-S` is a precision-vs-overhead dial, and the
underlying depth-scaling differences carry over in amplified
form. For most uses the default (non-blocking) is the right
choice — accept some sample drop, keep the target wall close
to baseline.

Source: [`results/cpu-samples.csv`](results/cpu-samples.csv).

## Real-world workloads

The previous sections used synthetic targets (fib / depth /
mandelbrot / sort). Real PHP applications run hundreds of distinct
functions across many files, with framework boilerplate, I/O, and
realistic stack shapes. Two such targets sanity-check the
synthetic numbers:

- **Laravel route**: bootstrap a Laravel skeleton (created at
  `/tmp/bench-laravel`) and dispatch 2000 `/` requests through
  the HTTP kernel. Real routing / middleware / view-rendering
  paths.
- **Composer install**: `composer install` from a primed cache
  against a Laravel-core composer.json (~28 packages). Mostly
  PHP-side logic with significant file I/O.

Numbers below are from the **interleaved-order** rerun
(`docs/bench/run-realworld-interleaved.sh`) where each round runs
baseline → phpspy → reli back-to-back. The first cut measured
each profiler's full set of runs sequentially, which let host
state drift between cells and produced misleading numbers
(2× cells in one direction, < 1× cells in the other from page
cache warming). Interleaving is necessary for stable real-world
comparisons.

### Headline (n=5, target JIT-on)

|  | Laravel route 2000 | Composer install |
|---|---|---|
| baseline | **4.67 s** | **8.24 s** |
| Excimer (in-process) | 1.06× | 1.04× |
| Datadog (in-process) | 1.04× | 1.08× |
| phpspy (external, 200 Hz) | 1.00× | 1.01× |
| reli (external, 200 Hz) | 1.01× | 1.06× |

(Heavy instrumentation tools — xhprof, Xdebug profile — were also
run on a 50-iter Laravel route variant; same shape as the
synthetic JIT-on row at hundreds-of-times overhead. See
[`results/realworld.csv`](results/realworld.csv) for the
raw numbers.)

The headline matches what the synthetic suite predicted:

- **In-process samplers (Excimer, Datadog) at ~1.04–1.08×** on
  both workloads, ~5 % cost. Same shape as the JIT-on synthetic
  numbers.
- **External samplers (phpspy, reli) at ~1.00–1.06×.** No
  significant deviation from baseline at 200 Hz on workloads with
  typical PHP stack depths (Laravel routes hover around 30–80
  frames). reli's slightly higher Composer cell (1.06×) may be
  its launch-via-`--` startup not fully amortising over an 8 s
  target — worth a longer composer target before reading
  too much into it.

The real-world cells essentially confirm the synthetic
extrapolation: at typical operational rates (≤ 200 Hz) and
typical PHP stack depths, all four sampling tools sit within
measurement noise of baseline.

### Methodology note: interleaved order matters

The non-interleaved cells in the same CSV (rows without the
`-il` suffix) show wildly different multipliers — phpspy and
reli at 1.86–1.91× on Laravel and 0.78–0.81× on Composer. Those
were run as a long sequential block of "baseline N times, then
phpspy N times, then reli N times" — and over the half-hour the
host's state changed (page cache warming, sandbox load shifts).
The phpspy/reli rows ran *later* than the baseline, on a slightly
slower host, so target wall came out higher than it would have
in a fresh comparison.

Interleaved rounds (`baseline → phpspy → reli` per round, five
rounds) put all three measurements within seconds of each other,
so any drift cancels. We left the non-interleaved rows in the
CSV (as `phpspy-200hz` / `reli-200hz` without `-il`) for
transparency about the contamination, but the `-il` cells are
the ones to cite.

Source: [`results/realworld.csv`](results/realworld.csv).

## Line-level resolution

A separate axis from overhead: when a profiler reports a frame as
`function_name file:line`, *which* line is it reporting? The
actual current opcode being executed in that function
(`opline->lineno`) or the function definition's first line
(`op_array->line_start`)? They look identical in the output but
mean very different things — the former tells you "execution is
on *this* line right now", the latter only tells you "execution
is somewhere in this function".

Verified empirically on a synthetic two-hot-loops-in-one-function
target:

```php
function hot(): int {
    $a = 0;
    for ($i = 0; $i < 100000000; $i++) {
        $a += $i;     // line 10
    }
    $b = 0;
    for ($j = 0; $j < 100000000; $j++) {
        $b ^= $j;     // line 14
    }
    return $a + $b;
}
```

A profiler that reads `opline->lineno` will report two distinct
lines inside `hot()` (10 and 14) at roughly even split. A
profiler that uses `op_array->line_start` (the function
definition line) reports line 7 for every sample.

| Profiler | Line shown for samples inside `hot()` | Source of truth |
|---|---|---|
| reli | 10 / 14 split, opline-correct | reads `opline->lineno`; verified |
| Excimer | opline-correct via `getTrace`; the `formatCollapsed` text format collapses to function-level | extension API |
| Datadog Continuous Profiler | opline-correct | `safely_get_opline(execute_data) ... opline.lineno` in [`dd-trace-php` Rust source](https://github.com/DataDog/dd-trace-php/tree/master/profiling/src) |
| nikic/sample_prof | opline-correct | per the README |
| phpspy | line 7 every sample | `op_array->line_start` only; verified empirically |
| Xdebug (profile mode → cachegrind) | function-entry only | empirical |
| xhprof / SPX / Tideways / New Relic / classic Blackfire | no per-frame line at all (function-level identifier) | output formats |

Practical consequence: on workloads where the hot code lives
inside one big function (a render loop in `<main>`, a method
that does a lot of inline work without delegating), only the
opline-level tools above can pinpoint which line is hot.
Function-only tools report the function name, which is fine
when the hot spot is the function itself. phpspy and Xdebug
profile mode include a line column in their output, but the
value comes from `op_array->line_start`, so it stays at the
function-definition line for every sample within that function.

For typical OO PHP, where work is spread across many small
methods, this matters less — the function name often pinpoints
the hot spot finely enough. For numerical code, code golf'd
controllers, or any pattern where one function does a lot, the
distinction is significant.

## Output volume

Two passes:

### Per workload (Laravel route × 2000)

Output size for each tool with on-disk output, on a single fixed
workload — Laravel route × 2000 dispatches. Tools that ship
straight to a SaaS backend without a local file aren't measured
here. Sample counts are noted where relevant; both reli and
phpspy at 200 Hz hit their respective sustainable-rate ceilings
on this workload, so the rows marked `-S` use `--pause-process`
to capture every requested sample (at the cost of higher target
wall — fine here because we're measuring output size, not
runtime overhead).

| Tool | Output | Samples | Format |
|---|---|---|---|
| **reli** `--rbt-compress -S` | 33 KB | 1369 | rbt binary, gzipped |
| **reli** `-S` | 108 KB | 1475 | rbt binary |
| **xhprof** | 800 KB | (per-call) | serialized PHP array |
| **excimer** | 847 KB | ~1900 (1 kHz) | collapsed text, uncompressed |
| **SPX** with `SPX_SAMPLING_PERIOD=1000` | 1.4 MB | (per-call hooked) | SPX text, gzipped |
| **phpspy** `-S` | 553 KB | 304 | phpspy text |
| **SPX** default | 121 MB | (per-call hooked) | SPX text, gzipped |
| **Xdebug** profile mode | 960 MB | (per-call hooked) | Cachegrind, uncompressed |

Source: [`results/output-sizes.csv`](results/output-sizes.csv),
generated by [`run-output-sizes.sh`](run-output-sizes.sh).
SPX is run with JIT off because of the JIT correctness bug; the
others run with JIT on.

### Per-sample (apples-to-apples)

Same tools at the same rate (200 Hz) on a stable single-PHP-CLI
target — `depth-100-1M`
(`docs/bench/scripts/depth.php 100 1000000 50`, ~2 s baseline) — so
the per-sample cost factors out from sample-count differences:

| Tool | Samples | Bytes | Bytes / sample |
|---|---|---|---|
| reli `--rbt-compress` | 114 | 720 | **6** |
| reli (rbt) | 117 | 4.3 KB | **37** |
| Excimer (collapsed) | 130 | 12 KB | 95 |
| phpspy (text) | 77 | 150 KB | 1,950 |

Per sample on a 100-frame stack, reli's binary trace is roughly
50× more compact than phpspy text and ~2.5× more compact than
Excimer's collapsed text (which interns shared stack prefixes by
joining with `;` but doesn't dedupe individual frame strings).
Adding `--rbt-compress` (gzip on top of the rbt binary) brings
reli down another ~6× to 6 bytes/sample on this workload.

phpspy here captured fewer samples than reli/Excimer because the
per-frame `process_vm_readv` design has trouble keeping up with
a 100-frame stack at 200 Hz (the ceiling discussed in "Sample
capture at depth and rate"); reli's bulk-read design keeps up.

The numbers in the previous table will move with the workload —
an app with a smaller stack and a more diverse function-name
dictionary will narrow the reli/phpspy gap, since reli's
interning relies on repeated names paying off.

A few observations:

- **`SPX_SAMPLING_PERIOD=1000` shrinks recorded SPX data by
  about 90× on this workload** (121 MB → 1.4 MB). The per-call
  hook still fires, so the *cost* doesn't drop proportionally;
  what shrinks is the on-disk representation. SPX writes the
  actual call data as `.txt.gz` (gzip-compressed) with a
  separate plaintext `.json` for metadata, so the SPX numbers
  above are already post-compression.
- **Xdebug profile mode produces ~1 GB of Cachegrind for ~9 s
  of Laravel.** Combined with the runtime cost (xdebug-profile
  at 165–180× under JIT-on), this is a development-time tool;
  in production CI artefacts the disk cost alone would be
  prohibitive at scale.
- **reli's `--rbt-compress` adds gzip on top of the binary
  trace.** It works cleanly when the rbt stream finalises
  successfully; on workloads where reli currently hits the
  documented `FFI\ParserException` race the gzip flush at end
  of run can leave a 0-byte file. The `-S` rows above
  side-step the race by pausing the target during reads — a
  reasonable trade-off when you only care about the output
  size, less so for production attach.

reli's `.rbt` is a compact binary trace format designed for
sampling-shaped data; converters to speedscope / pprof / folded
/ callgrind / flamegraph / phpspy text run on demand off the hot
path. The size numbers in the CPU section and these tables are
for `-f rbt`; other reli output formats shift more formatting
work onto the PHP-side hot path and produce larger files (target
wall time is unaffected by the choice of output format).

Selective instrumentation tools (Tideways Timeline, New Relic,
classic Blackfire) and Datadog don't appear above because their
collected data is shipped to a SaaS backend rather than to a
local file. Per Tideways' own docs, their callgraph mode produces
roughly 3–4× the data volume of Timeline mode; we don't have
direct numbers for Datadog or New Relic on-the-wire payload.

## Notes & quirks observed during the runs

### SPX produces wrong values under PHP 8.4 tracing JIT

With `opcache.jit=tracing`, SPX (in either default or
`SPX_SAMPLING_PERIOD=1000` mode) produces values inconsistent
with the script's intended computation, and the script exits
early. `fib(25)` returns `1407295` instead of `75025`; `fib(37)`
returns `11456770687` instead of `24157817`. The script reports
`exit 0` and writes a profile, but neither the profile nor the
script's printed results match what the source code computes.

PHP doesn't print the usual "JIT is incompatible with third
party extensions that override `zend_execute_ex()`" warning that
xhprof / Xdebug trigger. SPX presumably hooks the engine via a
path that doesn't trip PHP's JIT-disable check. The other
profilers tested (Excimer, Datadog, xhprof, Xdebug profile)
return correct values under JIT.

The likely cause is that SPX hasn't migrated to the **Zend
Observer API** (the JIT-aware hook mechanism PHP 8.0 introduced
specifically for profilers / tracers). Tools that hook
`zend_execute_ex` directly trigger PHP's JIT auto-disable check;
tools that hook neither `zend_execute_ex` nor go through Observer
API can wedge themselves between PHP's "JIT thinks it's safe"
and the runtime semantics it was assuming. Modern `dd-trace-php`
(Datadog) moved to the Observer API; the New Relic agent moved
to it on PHP 8.0+ as a prerequisite for the JIT support it
finally shipped at agent 10.18.0.8. xhprof and Xdebug stay on
the older `zend_execute_ex` path but PHP catches that and
disables JIT. SPX seems to fall in the gap.

This matches an open upstream report,
[NoiseByNorthwest/php-spx#215](https://github.com/NoiseByNorthwest/php-spx/issues/215),
which observes that the corruption happens "*even if not active
(i.e. `auto_start=0`)*, with JIT enabled (`opcache.jit=tracing`)".
That makes it even stronger evidence than our reproducer alone:
just *loading* SPX is enough to wedge JIT-compiled code, so the
incompatibility is in the hook installation itself, not in
SPX's per-sample bookkeeping.

Workaround: pair SPX with `opcache.jit=disable` on PHP 8.x. The
JIT-on suite excludes SPX as a result. Possible upstream
directions, depending on what fits SPX's design: an Observer-API
migration, or a self-imposed JIT detection that refuses to start
or forces `opcache.jit=off` when JIT is enabled.

### Datadog must be loaded as `extension=`, not `zend_extension=`

The sury Debian package ships
`extension = datadog-profiling.so` in `98-ddtrace.ini`. Loading
it as `zend_extension=` causes PHP to print "doesn't appear to
be a valid Zend extension" on stderr while still running the
script. An earlier version of `docs/bench/run.sh` used the wrong
syntax and therefore measured baseline in every datadog-prof
cell. The current bench scripts use `extension=`; for older
revisions in git history the datadog rows of `raw.csv` and
`long.csv` reflect that mistake.

### Sample drop is silent

phpspy prints a `calc_sleep_time: Expected sleep_ns>0; decrease
sample rate` warning to stderr when its requested rate exceeds
what it can sustain, but the dropped samples themselves don't
show up in the output — you only see the captured stacks. reli's
default mode behaves similarly.

The implication is that "no warning" is not the same as "all
samples captured". Always sanity-check captured count vs
expected (rate × wall) when interpreting results, especially at
high rates and / or deep stacks. The fields are right there in
[`results/cpu-samples.csv`](results/cpu-samples.csv) and
[`results/cpu-rates.csv`](results/cpu-rates.csv) for
reference.

### `-S` / `--pause-process` differs in implementation cost

Both phpspy and reli have a `-S` flag that pauses the target
during the read. The bench numbers map cleanly to the underlying
mechanism: phpspy's `-S` holds the target paused across many
per-frame syscalls (× stack depth), so target wall extends to
78 s at depth=200 vs. a 5 s baseline (15× slowdown — consistent
with phpspy's own "unsafe for production!" labelling for this
flag). reli's `-S` holds the target paused for a single bulk
read of the stack region, so target wall extends to 13.6 s at
the same depth (2.6×).

## Limitations of these benchmarks

Numbers here should be read as "what the suite measured on this
machine on this PHP build", not as universal constants. Two
specific caveats worth flagging:

1. **`docs/bench/scripts/depth.php` is monomorphic.** It calls a
   single function (`deep`) recursively to a configurable depth.
   For reli specifically this is *flattering* because cached
   binary analysis hits one function-name lookup per sample
   regardless of stack depth. The "Real-world workloads" section
   above adds Laravel-route and Composer-install runs which
   exercise hundreds of distinct functions; the in-process
   sampler cells there match the synthetic numbers (~1.04–1.08×),
   and external samplers stay at ~1.00–1.06× too — so the
   monomorphic-cache concern doesn't actually invalidate the
   directional finding, but the synthetic per-sample cost figures
   should still be read as best-case for reli.

2. **Synthetic CPU loops, no I/O.** Most synthetic targets are
   pure-PHP compute. Real PHP requests spend most wall time in
   I/O (database, cache, HTTP). The Composer-install workload
   has substantial file I/O (~30 % sys time) and the
   sampler-overhead numbers there are similar to the pure-CPU
   cells, suggesting the I/O-vs-CPU split doesn't change the
   sampling-tool picture much. Instrumentation tools were not
   re-measured in the I/O-heavy regime; their per-call hook cost
   is concentrated in the PHP-execution-dense parts of a request
   so the relative picture for instrumentation may shift.

3. **Single host, single PHP version.** PHP 8.4.19 NTS on x86_64
   Linux. Different microarchitectures, ZTS, alpine/musl,
   different JIT modes, or PHP 8.5+ might yield meaningfully
   different numbers on individual cells. The directional
   pattern (sampling tools at ~baseline, full-instrumentation
   inflates with JIT, depth scaling differs by class) is
   structural and should reproduce.

4. **Sequential vs interleaved measurement order matters more
   than expected.** The first real-world rerun ran each
   profiler's full set of iterations as one sequential block,
   and host state drifted significantly across the half-hour
   bench (page cache warming, sandbox load shifts) — producing
   misleading 2× cells in one direction and < 1× cells in the
   other. The interleaved version (`run-realworld-interleaved.sh`)
   put baseline / phpspy / reli in the same round so drift
   cancels, and produced clean numbers. Worth doing for any
   future cell that's expected to land near baseline.

If you have a specific operational scenario, the bench scripts
in this directory are intentionally easy to extend — point them
at a real workload and re-measure. We'd take the data.

## Reproducing

```bash
# Short suite, all in-process tools, JIT off (n=3).
RUNS=3 bash docs/bench/run.sh > docs/bench/results/raw.csv

# Long suite, sampling/light tools only at ~1-3 s baseline, JIT off (n=10).
RUNS=10 bash docs/bench/run-long.sh > docs/bench/results/long.csv

# JIT-on suite (n=10 light / n=3 heavy). SPX excluded.
RUNS_LIGHT=10 RUNS_HEAVY=3 bash docs/bench/run-jit.sh > docs/bench/results/jit.csv

# External samplers (reli, phpspy) on the long benches, JIT off.
RUNS=3 bash docs/bench/run-external.sh > docs/bench/results/external.csv

# Sampler-side CPU (n=5, depth sweep 10/30/100/200).
RUNS=5 bash docs/bench/run-cpu.sh > docs/bench/results/cpu.csv

# Sample-count verification + phpspy -S/-b 65k variants.
bash docs/bench/run-cpu-samples.sh > docs/bench/results/cpu-samples.csv

# Rate sweep 49-1000 Hz × depth 30/100/200.
bash docs/bench/run-cpu-rates.sh > docs/bench/results/cpu-rates.csv

# Aggregate (medians + multipliers vs baseline):
php -n docs/bench/summarize.php docs/bench/results/raw.csv docs/bench/results/external.csv
php -n docs/bench/summarize.php docs/bench/results/long.csv
php -n docs/bench/summarize.php docs/bench/results/jit.csv
php -n docs/bench/summarize-cpu.php docs/bench/results/cpu.csv
```

Setup notes (host install of profilers because the sandbox
couldn't reach public container registries) are in
[`README.md`](README.md). The Dockerfile in this directory
is the unused first attempt and is left for reference.
