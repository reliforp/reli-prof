# Reproduction of `RESULTS.md` on a different host

`RESULTS.md` was produced on the sandbox that originally authored
this work. This file holds a side-by-side reproduction on a
different machine, so readers can see which numbers are robust
across hardware and which aren't. Raw CSVs from this run are in
[`repro-modern-x86/`](repro-modern-x86/).

Reproducer host: 24-core x86_64 (modern desktop class), 60 GiB
RAM, NVMe, Ubuntu 24.04. Same scripts as `docs/bench/`, with
hardcoded paths patched to local installs (phpspy built from
upstream HEAD, php-spx built from upstream HEAD,
datadog-profiling 1.18.0 from the official tarball, xdebug
3.5.0 / xhprof 2.3.10 / excimer 1.2.5 from Sury). Target PHP:
PHP 8.4.20 NTS. reli's PHP: PHP 8.5.5 NTS.

This is **not a controlled hardware-only reproduction**: some
tool versions also differ from the original `RESULTS.md` run
(Sury / PECL ship newer point releases since then, and
phpspy / php-spx are tracked from upstream HEAD here). Treat
divergences below as "environment-dependent" — a mix of host
hardware, kernel configuration, libc, virtualisation level, and
tool version. A follow-up that isolates each axis separately
would tighten the attribution.

## What reproduces vs what shifts

| Class | Result |
|---|---|
| Sampling tools (excimer, datadog, phpspy, reli, spx-sample) at ~1.0× target wall | ✅ reproduces in every cell |
| Real-world Laravel / composer at ~1.0–1.07× target wall | ✅ reproduces |
| phpspy depth-scaling linear, reli flat (sample-capture-efficiency table) | ✅ shape reproduces |
| Heavy-instrumentation absolute multipliers (xhprof 1640× / Xdebug 180×) | ❌ much smaller here (xhprof 86× / Xdebug 94×) |
| Sample efficiency at 1 kHz × deep stack (absolute capture %) | ✅ shape, but absolute capture much higher here |
| Profiler-side CPU at 1 kHz (absolute seconds and relative ordering of phpspy vs reli) | ❌ relative ordering can flip |

Operationally the qualitative story in `comparison.md` and the
headline of `RESULTS.md` holds. The figures most worth reading
with host-dependence in mind are the heavy-instrumentation
multipliers and the per-sample CPU table.

## JIT-off short suite (n=3)

vs `RESULTS.md` L98-108. Multipliers vs baseline; baseline shown in seconds.

```
                fib-32           sort-100k-x10     mandel-200       depth-30         depth-100
                RES   here       RES   here        RES   here       RES   here       RES   here
baseline (s)    0.110 0.078      0.230 0.114       0.086 0.060      0.193 0.161      0.211 0.123
reli            1.0x  ~1.0x*     1.0x  ~1.0x*      1.0x  1.02x      1.3x  1.04x      1.1x  1.05x
phpspy          1.0x  ~1.0x*     1.0x  ~1.0x*      1.0x  0.97x      1.3x  1.06x      1.1x  1.04x
Excimer         1.0x  0.88x      1.0x  1.02x       0.8x  1.20x      1.0x  0.98x      1.0x  1.04x
Datadog Prof    1.0x  0.96x      1.1x  0.95x       1.0x  0.93x      1.3x  1.00x      1.0x  0.98x
SPX-sample-1ms  2.6x  2.08x      1.0x  1.04x       1.0x  0.93x      1.6x  1.91x      2.2x  3.15x
Xdebug-profile  62x   31.9x      1.0x  1.01x       4.8x  4.60x      43x   20.0x      52x   31.7x
SPX-default     41x   32.7x      1.0x  0.99x       1.0x  0.93x      19x   14.3x      30x   30.2x
xhprof          635x  32.4x      0.9x  0.97x       1.0x  0.98x      285x  13.3x      401x  27.5x
```

`*` reli/phpspy on fib-32 / sort here are noisy because the
targets are too short (78 / 114 ms baseline) for sampler attach
overhead to amortise — the long suite below is the cleaner read.

## JIT-off long suite (n=10, ~1–3 s baselines)

```
                fib-37    sort-100k-x100   mandel-500   depth-30-2M   depth-100-1M
                RES here  RES here         RES here     RES here      RES here
baseline (s)    -   0.734 -   1.137        -   0.526   -   1.632     -   1.244
excimer-1ms     -   0.99x -   1.02x        -   1.00x   -   1.08x     -   1.06x
datadog-prof    -   1.01x -   1.02x        -   1.01x   -   1.00x     -   1.00x
spx-sample-1ms  -   2.51x -   1.01x        -   1.00x   -   1.88x     -   3.15x
phpspy-1khz     -   1.01x -   0.99x        -   1.00x   -   1.00x     -   0.99x
reli-1khz       -   0.99x -   0.99x        -   0.99x   -   1.00x     -   1.00x
```

`RESULTS.md` doesn't have a long-suite-only table (it folds long
into the JIT-on headline). reli + phpspy sit at baseline ✓.

## JIT-on suite

vs `RESULTS.md` L61-72.

### Light tools, long benches (n=10)

```
                fib-37    sort-100k-x100   mandel-500   depth-30-2M   depth-100-1M
                RES here  RES here         RES here     RES here      RES here
baseline (s)    -   0.248 -   1.120        -   0.287   -   0.267     -   0.332
reli            1.01 1.12 1.01 1.17        1.01 1.04   1.00 1.02     1.01 1.03
reli-relijit    1.01 1.06 1.00 1.14        0.98 1.04   0.98 1.04     1.00 1.03
phpspy          1.01 1.06 1.00 1.15        1.01 1.05   0.99 1.04     1.01 1.04
excimer         1.01 0.98 1.01 1.01        1.01 1.01   1.00 1.01     0.98 1.01
datadog         0.95 0.99 1.03 1.01        1.00 1.06   1.00 1.00     1.01 0.99
```

External samplers (reli, phpspy) sit slightly elevated here vs
`RESULTS.md` (1.04–1.17× vs 0.99–1.01×). Inspecting raw rows shows
a warm-up effect: first 5–6 of 10 runs come in at ~1.4 s on
sort-100k-x100, last 4 settle at ~1.12 s. Likely host-cache warming
between back-to-back runs at sub-second baselines. In-process
tools don't show the same elevation.

### Heavy tools, short benches (n=3)

```
                fib-32           sort-100k-x10    mandel-200       depth-30         depth-100
                RES    here      RES   here       RES    here      RES    here      RES    here
baseline (s)    -      0.026     -     0.110      -      0.034     -      0.027     -      0.035
xhprof          1640x  85.9x     1.02x 1.01x      0.98x  1.06x     1071x  72.2x     1271x  90.1x
xdebug-profile  182x   94.0x     1.00x 1.05x      10.4x  8.41x     165x   114.6x    160x   109.5x
```

This is the largest divergence between the two hosts.

The mechanism: both rows agree that JIT auto-disables under
xhprof / Xdebug, so the absolute instrumented run-time is similar
across JIT-on/off (xhprof on `fib-32` here takes ~2.26 s either
way). The multiplier inflates under JIT-on because the *baseline*
gets faster. `RESULTS.md`'s xhprof `fib-32` cites ~62 s absolute,
giving 1640× over a JIT-on baseline of ~0.038 s. On this machine
the same xhprof workload finishes in ~2.5 s, dropping the
multiplier into the 70–90× range.

Note this isn't simply "the sandbox CPU was slower so everything
was slower". The JIT-on baseline only got ~1.5× faster across
hosts (0.038 s → 0.026 s); xhprof on the same workload got ~27×
faster (62 s → 2.26 s). If both scaled with raw CPU clock the
multiplier would be roughly constant. So the per-call hook is
sensitive to *something* in the host that isn't proportional to
PHP interpreter speed.

One plausible mechanism is the cost of xhprof's timing calls:
`strace -c` on this host shows xhprof in CPU+MEMORY mode
issuing exactly two `clock_gettime` calls per PHP function
entry/exit (43798 `clock_gettime` calls for fib(20)'s 21891 PHP
calls). Timing-call cost can vary substantially with libc /
vDSO / kernel-syscall-path configuration, virtualisation
overhead, and kernel mitigation settings, and two of those per
PHP call multiplied through fib's call density lines up with the
order-of-magnitude shift we see. We haven't measured the
timing-call cost on the original sandbox host, so this stays a
plausible mechanism rather than a confirmed cause. Cache
behaviour against xhprof's per-function hashmap is another
candidate we haven't ruled out.

The qualitative ranking — sampling tools at baseline,
full-instrumentation tools two-to-three orders of magnitude
heavier — is robust; the absolute multiplier headline is
host-specific. Xdebug profile mode shows the same multiplier-
shrinkage shape (180× → ~95×). The exact mechanism behind the
shrinkage warrants a follow-up if the precise number ever
matters.

A consequence worth being explicit about, in fairness to the
instrumentation tools: a "70–90× slowdown" classifies xhprof as
"keep it out of production hot paths, run it for one-off
diagnostics" rather than "literally unusable". The 1640×
sandbox figure is real but reads more dramatic than what most
readers will experience on their own hardware.

## Sample-rate × depth sweep

vs `RESULTS.md` L148-161 (sample capture as % of requested):

```
              49 Hz       99 Hz       200 Hz      500 Hz      1000 Hz
              RES here    RES here    RES here    RES here    RES here
depth=30
  phpspy      100 106     95  106     93  104     92  102     87  100
  reli         98  99     95   99     92   99     89   97     78   95
depth=100
  phpspy       66  67     66   76     64   67     62   69     41   69
  reli         97  99     93   99     91   99     89   97     71   95
depth=200
  phpspy       44  38     40   45     37   42     29   44     23   42
  reli         98 100     93   99     91   99     84   97     58   93
```

Shape preserved (phpspy linear-in-depth, reli flat). On this
machine both samplers capture more efficiently — most striking,
reli at 1 kHz × depth=200 was 58 % in `RESULTS.md`, here it's
93 %. Same shape, the gap to phpspy widens in the hard regime.

## Profiler-side CPU at 1 kHz — relative ordering shifts

`RESULTS.md` L196-209 cites this depth sweep:

```
                  depth=10   depth=30   depth=100  depth=200
phpspy              3.85       3.89       4.91       5.03
reli (Δ)           +3.79      +3.81      +3.57      +3.92
```

Live `ps -o pcpu` observation on this host (depth=100 / 1 kHz,
target running at ~100 % of one core in either case):

```
phpspy   ~12 % CPU on its own process
reli     ~29 % CPU on its own process
```

This is a live observation rather than a row in
[`repro-modern-x86/cpu.csv`](repro-modern-x86/cpu.csv) — the
phpspy CPU column in `cpu.csv` came back zero on this host
(see "Issues hit while reproducing" below for why). The CSVs in
this reproduction therefore don't speak directly to per-sample
CPU; the live observation is the best we have. Steadier
measurements would come from `pidstat -p $PHPSPY 1` over the
target's lifetime.

With that caveat, phpspy looks like **the cheaper sampler per
sample at depths ≤ 100 on this host**, opposite of the sandbox
ranking. Plausible reading: phpspy's per-sample cost is
dominated by `N × process_vm_readv` syscall latency (linear in
stack depth N), and modern x86 dropped per-syscall latency
proportionally more than overall host throughput. reli's
per-sample cost is dominated by PHP-side stack-walk execution,
which scales with PHP interpreter / JIT speed — improved less
dramatically across hosts. On the sandbox phpspy was
syscall-saturated (~1 core); here phpspy at depth=100 spends
~0.17 ms/sample, reli spends ~0.31 ms/sample.

The crossover where reli's design wins on per-sample cost has
moved deeper. The shape is unchanged (phpspy syscall-fan-out
costs scale linearly with depth, reli stays flat), so reli is
still cheaper at depth ≥ ~150 here, and dramatically cheaper
when phpspy hits its sustainable-rate ceiling at depth ≥ 200.

`RESULTS.md`'s caveat block in this section now points at this
file for the reproduction.

## Real-world

vs `RESULTS.md` L297-330 (interleaved order, target JIT on, n=5):

```
                  baseline   phpspy 200 Hz      reli 200 Hz
                  RES here   RES   here         RES   here
laravel-route     4.67  3.46 1.00x 1.014x       1.01x 0.998x  (8000 iters here)
composer-install  8.24  1.22 1.01x 1.030x       1.06x 1.072x  (primed cache)
```

Both samplers reproduce ~1.0–1.07× target overhead on real PHP
workloads. Baselines on this host are shorter than on the
sandbox; composer install is ~7× faster because the package cache
sits on local NVMe instead of a slow filesystem.

The first attempt used the script's default 2000 iters for
laravel-route. With a 1.13 s baseline run-to-run noise was ~30 %;
bumping to 8000 (3.46 s baseline) tightened it to ~3 %. The
script could grow an `$ITERS` env-var override.

## Issues hit while reproducing

These are reproducer-side gotchas — not problems with the
original `RESULTS.md` numbers. Worth flagging here so the next
reproducer doesn't lose hours.

1. **phpspy attach silently fails under `ptrace_scope=1`.**
   Ubuntu / Debian default. phpspy attaches by PID to a sibling
   process; `process_vm_readv` returns `EPERM` on every sample.
   The bench scripts redirect phpspy stderr to `/dev/null`, so
   you don't see the failure — you get a 0-byte
   `/tmp/bench-phpspy.out` and a "phpspy at 1.0× baseline" cell
   that's actually a baseline run with a no-op sampler. Fix:
   `sudo sh -c 'echo 0 > /proc/sys/kernel/yama/ptrace_scope'`.
   reli's `--cmd args` form forks the target so reli is
   unaffected. Now documented in `docs/bench/README.md`.

2. **Disk fill from xdebug + SPX outputs.** `run-jit.sh` writes
   400–600 MB `/tmp/cachegrind.out.<pid>` per xdebug-profile
   cell with no cleanup; SPX writes another ~570 MB to
   `/tmp/spx`. Filled the host's `/` partition mid-run on the
   first attempt. Worked around by interleaving
   `rm -rf /tmp/cachegrind.out.* /tmp/spx /tmp/spx-*` between
   suites. Worth fixing in the scripts.

3. **`run-cpu.sh`'s phpspy CPU column was zero on this host.**
   `/usr/bin/time -v` wraps phpspy backgrounded with `&`, then
   the bench `kill`s it on target exit. `time` apparently
   doesn't get a chance to write its summary to its `-o` file
   in this configuration. `RESULTS.md` L196-209 has phpspy CPU
   numbers from the original run, so the bug is environment-
   specific. The sample-efficiency table from `cpu-rates.csv`
   already carries the depth-scaling story.

4. **Hardcoded paths in `run-external.sh` / `run-jit.sh` /
   sweep scripts.** `/home/user/reli-prof/reli`,
   `/usr/local/bin/phpspy`, `/usr/bin/php8.5`,
   `${EXTDIR}/{spx,datadog-profiling}.so`. All needed patching
   to reproduce. Could grow env-var overrides
   (`${RELI:-/usr/local/bin/reli}` pattern) so the next
   reproducer doesn't have to fork or sed.

## CSVs from this run

[`repro-modern-x86/`](repro-modern-x86/):

```
raw.csv                      JIT-off short suite (n=3)
external.csv                 external samplers, JIT-off short (n=3)
long.csv                     JIT-off long suite (n=10)
jit.csv                      JIT-on suite (light n=10, heavy n=3)
cpu.csv                      depth sweep with /usr/bin/time -v
cpu-samples.csv              -S / -b variants depth sweep
cpu-rates.csv                rate × depth sample-efficiency sweep
realworld-laravel-8k.csv     Laravel route × 8000 (n=5, interleaved)
realworld-composer.csv       composer install (n=5, interleaved, primed cache)
```
