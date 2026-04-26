# Similar tools, and when to pick reli

A field guide to PHP profilers and memory-analysis tools. The
ecosystem is mature; reli stands on the shoulders of the tools
listed here, and the goal of this page is to help you pick the
right one for *your* situation — including "don't pick reli, pick
X" where X fits better.

This page is intentionally table-heavy. Prose lives in collapsed
`<details>` blocks. Numerical comparisons live in
[`bench/RESULTS.md`](bench/RESULTS.md), with the methodology
and reproducer scripts in [`bench/`](bench/).

> *Scope*: Closed-source vendor descriptions (Tideways, classic
> Blackfire Profiler) come from public docs; everything else is
> source-verified. Benchmark numbers cited here are summary
> figures from `bench/RESULTS.md`.

## At a glance

| Tool | Category | OSS | ZTS | Line-level | Production-oriented | Pick if you want… |
|---|---|---|---|---|---|---|
| **reli** | external sampler | MIT | ✓ | ✓ (opline) | ✓ | depth analysis, ZTS, native frames, no extension to install, attach to a running unfamiliar process |
| [phpspy](https://github.com/adsr/phpspy) | external sampler | MIT | NTS only | function-entry only | ✓ | the lightest pure-C sampler at typical depths and rates |
| [Excimer](https://www.mediawiki.org/wiki/Excimer) | in-process sampler (timer) | Apache-2.0 | ✓ (beta) | ✓ (`getTrace`) | ✓ | a free production-grade sampler without a SaaS dep |
| [Datadog](https://docs.datadoghq.com/profiler/enabling/php/) | in-process sampler (pthread) | Apache-2.0 + commercial backend | ✓ (`dd-trace-php` 0.99+) | ✓ | ✓ | always-on continuous profiling with a SaaS backend |
| [Blackfire](https://www.blackfire.io/profiler/) (CP) | in-process sampler (collection layer = Datadog's OSS profiler) | (= Datadog's OSS collection) + commercial backend | (= Datadog) | (= Datadog) | ✓ | Datadog's OSS profiler collection layer integrated with Blackfire's agent, backend, and UI |
| [Blackfire](https://www.blackfire.io/profiler/) (classic) | selective + deep auto-instrumentation | closed | ✓ | function-only | per-request only | per-request deep auto-instrumentation (engine-level hooks per Blackfire's docs) |
| [Tideways](https://tideways.com) (Timeline) | selective instrumentation (APM-style) | closed | ✓ | function-only | ✓ | PHP-focused APM with framework-aware tracing |
| [Tideways](https://tideways.com) (Callgraph) | full per-function instrumentation, opt-in | closed | ✓ | function-only | per-request only | full callgraph for one specific transaction |
| [New Relic](https://github.com/newrelic/newrelic-php-agent) | selective instrumentation | Apache-2.0 + commercial backend | NTS only (ZTS support [ended at v9.19.0.309][nr-compat]) | function-only | ✓ | classic APM with extensive framework auto-instrumentation; agent OSS |
| [longxinH/xhprof](https://github.com/longxinH/xhprof) | full per-function instrumentation | Apache-2.0 | ✓ | function-only | development | a free local callgraph profiler |
| [Xdebug](https://xdebug.org/docs/profiler) (profile mode) | full per-function instrumentation | Xdebug | ✓ | function-entry only | development | cachegrind output for KCacheGrind / QCacheGrind / webgrind |
| [SPX](https://github.com/NoiseByNorthwest/php-spx) | full per-function instrumentation | GPL-3.0 | beta | function-only | development (per upstream) | a self-contained dev profiler with a built-in web UI |

[nr-compat]: https://docs.newrelic.com/docs/apm/agents/php-agent/getting-started/php-agent-compatibility-requirements/

[Memory tools](#memory-analysis) are split out separately because
the question "what does this profiler measure about memory?" has
a different shape from the time-axis question.

> *Note*: SPX has a known correctness issue with PHP 8.4 tracing
> JIT — pair it with `opcache.jit=disable` on PHP 8.x. See
> [`bench/RESULTS.md`](bench/RESULTS.md#notes--quirks-observed-during-the-runs).

## Time profiling

### Categories

| Category | How it works | Tools |
|---|---|---|
| **(1) External-process samplers** | Separate process reads target memory via `process_vm_readv`; no code is installed or executed inside the target | reli, phpspy |
| **(2) In-process timer-driven samplers** | PHP extension uses a timer / background thread to set Zend's VM-interrupt flag; the stack is read at the next safe point | Excimer, Datadog Continuous Profiler, Blackfire CP (uses Datadog under the hood) |
| **(3) APM-style selective instrumentation** | PHP extension hooks only known framework integration points (HTTP / SQL / cache / template / event dispatch) — span / trace style | Tideways Timeline (default), New Relic (Observer API on PHP 8+), Blackfire classic Profiler (deterministic auto-instrumentation) |
| **(4) Full per-function instrumentation** | Hook every PHP function call (`zend_execute_ex` or Observer API on every call) | longxinH/xhprof, Xdebug profile mode, SPX, Tideways Callgraph (opt-in) |

<details>
<summary>Subtleties worth flagging</summary>

- **The (3)/(4) split is real *inside* a single product.** Tideways
  defaults to (3) and switches to (4) only on demand via
  Tracepoints (their docs describe callgraph mode as intended for
  on-demand profiling rather than always-on use). New Relic and
  Blackfire have similar on-demand deep-profile escalation
  patterns.
- **(2) is *not* "external sampling".** Excimer uses a
  `SIGEV_THREAD` POSIX timer; Datadog has a long-lived Rust pthread
  that periodically sets the Zend VM interrupt flag. In both cases
  the stack walk runs *inside* the PHP process at the next safe
  point.
- **Datadog's profiler is OSS** ([dd-trace-php](https://github.com/DataDog/dd-trace-php)),
  and Blackfire's Continuous Profiler ships Datadog's
  `datadog-profiling.so` from the `dd-library-php` release with
  `DD_TRACE_AGENT_URL` pointed at a Blackfire agent socket
  (per [Blackfire's own PHP CP setup
  doc](https://docs.blackfire.io/continuous-profiling-cookbooks/php)).
  For technical purposes they're the same collection engine;
  they differ in the SaaS backend.
- **SPX has an `SPX_SAMPLING_PERIOD` option** named "sampling"
  in the docs. Per the upstream README it controls how often the
  recorded stack is *written*, not how often the instrumentation
  hook fires; SPX therefore behaves as a category (4) tool for
  cost purposes, with the option mainly serving to keep report
  size manageable.

</details>

<details>
<summary>Why the sampling/instrumentation choice matters (Tideways' own benchmark + Nikita Popov's note)</summary>

Sampling and instrumentation are both legitimate techniques. The
trade-off has shifted with PHP 7+. Tideways published their own
benchmark on this point ([Profiling Overhead and PHP 7][tw-overhead]):

| Profiler | PHP 5.6 | PHP 7 |
|---|---|---|
| Tideways Timeline (selective, 10% request sampling) | 4.93% | 17.11% |
| Tideways XHProf (full instrumentation, 10% request sampling) | 13.41% | 23.86% |
| Tideways XHProf (full instrumentation, 100%) | 42.22% | 59.42% |

Their observation: PHP itself got faster (about 3× from 5.6 to 7),
so the per-hook fixed cost is now a larger share of total runtime.

A second consideration, raised by Nikita Popov in the README of
[`nikic/sample_prof`][nikic-sample-prof]:

> "A sampling profiler [...] either doesn't affect performance at
> all or slows down everything symmetrically."

Per-call instrumentation adds a roughly fixed cost to every call,
so small frequently-called functions absorb a larger relative
share — i.e. the same workload looks slightly differently shaped
under an instrumenting profiler than under a sampling profiler.

Both points are nuances rather than dealbreakers; they're things
to keep in mind when interpreting numbers from any profiler.

[tw-overhead]: https://tideways.com/profiler/blog/profiling-overhead-and-php-7
[nikic-sample-prof]: https://github.com/nikic/sample_prof

</details>

### Side-by-side

"Output (Laravel × 2000)" is the size of the on-disk artefact we
measured for that tool on a single fixed workload at 200 Hz
sampling (where applicable). Tools that ship straight to a SaaS
backend without a local file aren't measured here. See
[`bench/RESULTS.md` § Output
volume](bench/RESULTS.md#output-volume) for the per-sample
breakdown and methodology notes.

"JIT compatibility" is whether the tool runs correctly when
`opcache.jit` is on (vs. forcing it off or producing wrong
output). "JIT-name resolution" is whether the tool can map a
native frame inside JIT-compiled code back to the original PHP
function — only relevant for tools that capture C-level frames
in the first place; in-process and instrumentation tools that
hook at PHP function boundaries see PHP names directly through
`opline` and don't need the column.

"Production-oriented" is an at-a-glance: ✓ if the tool is
designed for and documented for permanent on-production use
(samplers in our suite at 1 ms target overhead within
measurement noise, APM agents at their default mode); per-request
only if the vendor docs or upstream README position it as
on-demand profiling rather than always-on; development if the
upstream README itself positions it as a development / staging
tool.

| | Category | Production-oriented | ZTS | Native frames | JIT compat | JIT-name resolution | Output (Laravel × 2000) | OSS |
|---|---|---|---|---|---|---|---|---|
| **reli** | (1) | ✓ | yes | yes (DWARF) | yes | yes (perf map) | 33 KB (rbt+gzip, `-S`) / 108 KB (rbt, `-S`) | MIT |
| **phpspy** | (1) | ✓ | NTS only | no | yes | no | 553 KB (text, `-S`) | MIT |
| **Excimer** | (2) | ✓ | yes (beta) | no | yes | n/a (sees PHP names via opline) | 847 KB (collapsed text) | Apache-2.0 |
| **Datadog Profiler** | (2) | ✓ | yes (`dd-trace-php` 0.99+) | no | yes | n/a (PHP names via opline) | (SaaS, not measured locally) | Apache-2.0 + commercial backend |
| **Blackfire CP** | (2) | ✓ | (= Datadog) | no | yes | n/a | (SaaS, not measured locally) | (= Datadog) + commercial backend |
| **Tideways Timeline** | (3) | ✓ | yes | no | yes (per Tideways docs; not source-verified) | n/a | (SaaS, not measured locally) | closed |
| **New Relic** | (3) | ✓ | NTS only ([since v9.19.0.309][nr-compat]) | no | yes (since agent 10.18.0.8) | n/a | (SaaS, not measured locally) | Apache-2.0 + commercial backend |
| **Blackfire (classic)** | (3) | per-request only | yes | no | yes (per Blackfire docs; not source-verified) | n/a | (SaaS, not measured locally) | closed |
| **xhprof (longxinH)** | (4) | development | yes | no | no (`zend_execute_ex` triggers PHP's JIT-disable) | n/a | 800 KB (serialized array) | Apache-2.0 |
| **Xdebug** (profile) | (4) | development | yes | no | no (`zend_execute_ex` triggers PHP's JIT-disable) | n/a | 960 MB (cachegrind) | Xdebug |
| **SPX** | (4) | development | beta | no | no (see [upstream issue](https://github.com/NoiseByNorthwest/php-spx/issues/215)) | n/a | 121 MB (default) / 1.4 MB (`SAMPLING_PERIOD=1ms`); both gzipped | GPL-3.0 |
| **Tideways Callgraph** | (4) | per-request only | yes | no | yes (per Tideways docs; not source-verified) | n/a | (SaaS; per Tideways docs, ~3–4× Timeline) | closed |

### Line-level resolution

Whether a profiler reports the *current opcode line* (`opline->lineno`)
or the *function definition line* (`op_array->line_start`). Matters
for any workload where one function does a lot of work inline.

| Tool | Line-level? | Source |
|---|---|---|
| reli, Excimer, Datadog, nikic/sample_prof | ✓ true (opline) | empirical + source-verified — see [`bench/RESULTS.md`][rb-line] |
| phpspy | ✗ function-entry line only | empirical |
| Xdebug profile | ✗ function-entry only (cachegrind) | empirical |
| xhprof, SPX, Tideways, New Relic, Blackfire (classic) | ✗ function-level / span-level only | output-format inspection / vendor docs |

[rb-line]: bench/RESULTS.md#line-level-resolution

### Performance

In our suite (PHP 8.4 NTS, 1 ms sampling), category-(1) and
category-(2) tools sat within measurement noise of baseline on
every workload with or without JIT. Category-(4) tools showed
much larger overhead, especially against a JIT-on baseline that
the category-(4) tool itself disables — xhprof reached 1640× on
recursive code in our benches. Category-(3) tools sat between
the two extremes; this is also the category most production APMs
ship by default.

Real-world workloads confirm the synthetic numbers: on a Laravel
route (2000 dispatches via `\Illuminate\Foundation\Http\Kernel`)
and a Composer install of Laravel-core deps, all four sampling
tools sit at 1.00–1.08× target wall vs. baseline. See
[`bench/RESULTS.md` § Real-world workloads](bench/RESULTS.md#real-world-workloads).

For full numbers: [`bench/RESULTS.md`](bench/RESULTS.md) has
the JIT-on / JIT-off / per-rate / per-depth / sampler-side CPU /
real-world tables. For the operational summary: phpspy is the
lighter tool at typical PHP stack depths (≤ 50) and rates
(≤ 200 Hz); reli's bulk-stack-copy design pays off at deeper
stacks and higher rates.

<details>
<summary>Tool-by-tool details</summary>

#### reli (this project)
External sampler, MIT, written almost entirely in PHP and attaches
via ptrace + `process_vm_readv`. No PHP extension required.
Supports ZTS targets natively; can resolve native (C-level) frames
via DWARF `.eh_frame` unwinding and JIT-compiled function names via
the perf map / GDB JIT interface. Outputs a compact `.rbt` binary
that converts to speedscope / pprof / flamegraph / callgrind /
folded / phpspy text on demand.

#### phpspy
External sampler in C, MIT. Lower per-sample CPU than reli (C
beats PHP) and the highest practical sample rate at typical PHP
stack depths, but **NTS only** on x86_64 Linux 3.2+ (aarch64
experimental, PHP 8.3+). Outputs a single-line text format and
(experimentally) callgrind, with a `stackcollapse` script for
flamegraphs. Per-frame `process_vm_readv` syscalls give it a
depth-dependent ceiling on sustainable rate (~870 samples/s at
depth 30, ~230 samples/s at depth 200). Reference choice for raw
sampling throughput on a straightforward NTS target.

#### Excimer
PHP extension by Wikimedia, Apache-2.0. Uses a POSIX timer with
`SIGEV_THREAD`: the kernel spawns a thread on each timer expiry
that sets PHP's VM-interrupt flag, and the stack is captured at
PHP's next safe point. PHP 7.1+. Wikimedia runs it in production
[at MediaWiki scale](https://techblog.wikimedia.org/2021/03/03/profiling-php-in-production-at-scale/).
Native output is custom; [excimetry](https://github.com/excimetry/excimetry)
converts to speedscope.

#### Datadog Continuous Profiler
PHP extension shipped via [`dd-trace-php`](https://github.com/DataDog/dd-trace-php),
Apache-2.0 (profiler in Rust, `profiling/src`). A long-lived Rust
pthread sets the Zend VM interrupt flag every ~10 ms; sampling
happens at PHP safe points. PHP 7.1+; ZTS supported since
`dd-trace-php` 0.99+. Profile types: wall, CPU, exceptions,
allocations (statistical, ~4 MB sampling distance), allocated
memory, file/socket I/O. Designed for always-on production use;
data goes to Datadog's SaaS backend.

> Note: load as `extension=`, not `zend_extension=`. PHP silently
> fails to load it the wrong way — see
> [bench/RESULTS.md](bench/RESULTS.md#datadog-must-be-loaded-as-extension-not-zend_extension).

Per Datadog's docs, the allocation profile is *allocation flow*
(includes allocations subsequently freed). Datadog also notes
that a "live heap" / "retained memory" profile type isn't
currently offered for PHP, only for some other languages.

#### Blackfire (classic Profiler + Continuous Profiler)
Two complementary commercial-SaaS products in one suite.
- **Classic Profiler** (deterministic, on-demand): closed-source
  PHP Probe. Per Blackfire's public product documentation, the
  auto-instrumentation hooks into the PHP engine early enough to
  cover things like destructors, GC, OPcache, sessions, and file
  compilation. Triggered per-request rather than always-on.
- **Continuous Profiler** (probabilistic, always-on): per
  [Blackfire's PHP CP setup
  doc](https://docs.blackfire.io/continuous-profiling-cookbooks/php),
  the install ships Datadog's `datadog-profiling.so` from the
  `dd-library-php` release with `DD_TRACE_AGENT_URL` pointed at
  a Blackfire agent socket. Same collection layer as Datadog,
  integrated with a different agent / backend / UI.

#### Tideways
Commercial SaaS, PHP extension + local daemon are **closed-source**
(the OSS [`tideways/php-xhprof-extension`](https://github.com/tideways/php-xhprof-extension)
fork was archived in 2023 in favour of [longxinH/xhprof](https://github.com/longxinH/xhprof)),
which makes specific implementation details unverifiable from
outside. Per [Tideways' own docs](https://support.tideways.com/documentation/setup/configuration/sampling.html)
the extension surfaces two collection modes selected by the
`tideways.collect` INI:

- **Timeline Profiler** (`tideways.collect=tracing`, default):
  category (3) by description — Tideways describes it as
  collecting framework-level timeline data; the exact list of
  instrumented integration points and the per-request overhead
  numbers aren't independently verifiable from outside the
  closed-source extension.
- **Callgraph Profiler** (`tideways.collect=full`, opt-in):
  category (4); Tideways' docs describe it as carrying
  additional overhead per request and recommend it for short-term
  collection rather than always-on production use.

Memory side: the archived xhprof fork exposed allocator-hook
metrics (`mem.na`, `mem.nf`, `mem.aa` via
`TIDEWAYS_XHPROF_FLAGS_MEMORY_ALLOC`); whether the same flags
and semantics are still surfaced in the current closed-source
extension is something we couldn't verify externally. Tideways
themselves published two posts in 2018 candidly discussing the
limits of allocator-counting as a memory metric:
[Testing a new approach](https://tideways.com/profiler/blog/testing-a-new-approach-to-memory-profiling-in-php-with-xhprof)
and the follow-up
[The difficulty of memory profiling in PHP](https://tideways.com/profiler/blog/the-difficulty-of-memory-profiling-in-php).

#### New Relic
Commercial SaaS, but the agent itself is **OSS**
([`newrelic/newrelic-php-agent`](https://github.com/newrelic/newrelic-php-agent),
Apache-2.0). PHP extension in C + local proxy daemon in Go.

Hook approach changed across PHP / agent versions: PHP 7.x uses
a `zend_execute_ex` override with selective dispatch (only
flagged functions actually instrumented); on PHP 8.0+ the agent
moved to the Zend Observer API. PHP's tracing JIT was an
explicit incompatibility for a while — per the [official
compatibility page][nr-compat] the agent only supports JIT
"as of agent release 10.18.0.8" (PHP auto-disables JIT for
older agents). Heavy emphasis on framework auto-instrumentation
(Symfony, Laravel, Magento, Doctrine, AWS SDK, RabbitMQ, etc.).
No always-on stack sampler in the PHP agent.

ZTS PHP builds are **not supported** — same compatibility page:
"PHP builds that are compiled with Zend Thread Safety (ZTS) are
not supported", with support having ended at agent v9.19.0.309.
Fibers are also unsupported. Supported PHP range is 7.2–8.5
with version-specific minimum agent versions.

#### longxinH/xhprof
The currently-maintained xhprof fork (Apache-2.0). PHP 7.2-8.2
documented (8.4 not yet listed). Full per-function instrumentation
via `zend_execute_ex`. Captures wall, CPU, memory (`mu`), peak
memory (`pmu`), and call counts per parent-child function pair.
Output is a hierarchical PHP array. Facebook's original
`facebookarchive/xhprof` is unmaintained.

#### Xdebug (profile mode)
[Xdebug](https://xdebug.org/docs/profiler) profile mode
(currently 3.5.x, supports up to PHP 8.4 via 3.4.0+) outputs
Cachegrind for KCacheGrind / QCacheGrind / webgrind. Full
per-function instrumentation, very high overhead — Xdebug itself
recommends it as a development tool. Cachegrind's memory column
was withdrawn upstream because it returned incorrect data.

#### SPX
PHP extension, GPL-3.0. A nice all-in-one developer experience: a
built-in web UI with timeline / flame graphs / 22 selectable
metrics and very wide PHP support (5.4-8.5). ZTS marked beta
upstream. The README positions SPX as a development / staging
tool. Default is full per-function instrumentation;
`SPX_SAMPLING_PERIOD` thins the recorded data but the per-call
hook still fires.

> Known issue with PHP 8.x tracing JIT, separating what's
> known from what we're guessing:
>
> - **Upstream warning**:
>   [NoiseByNorthwest/php-spx#215](https://github.com/NoiseByNorthwest/php-spx/issues/215)
>   reports that just *loading* SPX (even with `auto_start=0`)
>   is enough to corrupt JIT execution; the issue advises not
>   enabling SPX with JIT.
> - **Our PHP 8.4 tracing-JIT bench observation**: SPX (in
>   either default or `SPX_SAMPLING_PERIOD=1000` mode) produced
>   incorrect results / exited early — see
>   [bench/RESULTS.md](bench/RESULTS.md#spx-produces-wrong-values-under-php-84-tracing-jit).
> - **Workaround**: `opcache.jit=disable` on PHP 8.x.
> - **Hypothesis** (not confirmed): SPX hasn't migrated to the
>   JIT-aware Zend Observer API — the hook PHP 8.0 introduced
>   for profilers — the way modern `dd-trace-php` did and the
>   New Relic agent did as a prerequisite for the JIT support
>   it shipped at agent 10.18.0.8. We haven't traced the root
>   cause in SPX's source.

</details>

## Memory analysis

### Categories of memory question

Different memory tools answer different questions. Knowing which
question you're asking is the first step to picking the right
tool.

| Category | Question it answers | How it's measured | Tools |
|---|---|---|---|
| **A. Per-function memory delta** *(heuristic)* | "Which function's `memory_get_usage` went up the most?" — *not* the same as "which function allocated the most" | `zend_memory_usage()` at function entry/exit | xhprof, Tideways (Timeline & Callgraph), Xdebug (function trace), SPX, classic Blackfire Profiler, New Relic |
| **B1. Allocation flow** | "Which functions are doing the most allocation work, regardless of whether the memory survives?" | Hook the Zend allocator, count bytes per allocation site | Datadog (statistical, ~4 MB sampling distance), php-memprof (full capture) |
| **B2. Leak detection** | "Which functions allocated memory that hasn't been freed yet?" | Hook alloc *and* free, reconcile what's outstanding at dump time | **php-memprof** (its primary use case) |
| **C. Live heap structure** | "What objects exist in memory right now, how big are they, and what holds references to them?" | Snapshot the heap and reconstruct the object graph | **reli**, [php-meminfo](https://github.com/BitOne/php-meminfo) |

<details>
<summary>Why "memory per function" charts can mislead (Tideways' own write-up)</summary>

Many PHP profilers offer a "memory used per function" view. Most
are built on `memory_get_usage()` deltas captured at function
entry/exit. That gives you the *net change in current process
memory* across a function's execution, which is a useful hint
about where memory is moving but is not the same as "how much this
function allocated" or "what this function is holding on to".

Tideways have written candidly about this in
[The difficulty of memory profiling in PHP](https://tideways.com/profiler/blog/the-difficulty-of-memory-profiling-in-php) (2018):

> "[W]e have no way of correlating if a function creates memory
> and its kept around permanently until the script ends, or if
> its (almost) immediately freed."

They illustrated with a Composer benchmark where 1.4 reported
larger `memory_get_usage` values than 1.3.3, even though 1.4's
actual peak memory was lower. The follow-up to
[their experimental allocator-hook metrics](https://tideways.com/profiler/blog/testing-a-new-approach-to-memory-profiling-in-php-with-xhprof),
22 days after introducing them, explained why even allocator-hook
counts don't fully answer "which function is responsible".

This isn't a flaw in any one product — it's a fundamental
property of profiling a managed runtime with reuse and deferred
reclamation. Per-function memory views in time profilers are
*heuristic indicators*; if you need a more precise answer, the
category of tool you reach for depends on the question.

</details>

### Side-by-side

| | Category | Extension required | Production attach | Output | Diff/compare | OSS |
|---|---|---|---|---|---|---|
| **reli** | C (heap structure) | no | yes (dump-and-analyse) | `.rmem` (binary) / sqlite3 / json / report | yes | MIT |
| [**php-meminfo**](https://github.com/BitOne/php-meminfo) | C (heap structure) | yes | requires triggered dump from app code | JSON | no (manual) | MIT |
| [**php-memprof**](https://github.com/arnaud-lb/php-memory-profiler) | B1 + **B2** | yes | high overhead, dev-only per upstream | callgrind / pprof / raw | n/a | MIT |
| **Datadog allocations** | B1 | yes | yes (4 MB statistical sampling) | (Datadog backend) | n/a (gross flow only) | Apache-2.0 + backend |
| **Tideways** memory metrics | A; B1 if the 2018 `mem.*` flags are still surfaced (unverified) | yes | yes (with caveats) | (Tideways backend) | n/a | closed |
| xhprof / Xdebug / SPX / classic Blackfire / New Relic | A | yes | varies | various | n/a | mixed |

### Where reli fits

reli's primary memory use case is **category C**: capture the live
heap as an object graph, with types and reference edges, so you
can see what's actually there at a moment in time.

Picking by question:

- "Which function allocates the most bytes?" — Datadog allocations
  (production) or php-memprof (dev). reli isn't designed for this.
- "Where is a leak coming from, by function?" — php-memprof (the
  alloc/free reconciliation tool). reli's `compare` can show
  *what objects* grew between two snapshots, which often suffices.
- "What's currently held — which objects exist, what's retaining
  them, why isn't GC collecting them?" — reli is an actively
  maintained option here; php-meminfo has long served this role
  but its last release was v1.1.1 in 2021.

## OSS / commercial split

For tools where the data-collection layer is OSS, the descriptions
above are based on inspecting the actual source. For closed-source
collection layers, descriptions are based on vendor documentation
and may not reflect current implementation details.

| Component | License | Source available |
|---|---|---|
| reli, phpspy, php-memprof, php-meminfo | MIT | yes |
| Excimer | Apache-2.0 | yes |
| longxinH/xhprof | Apache-2.0 | yes |
| SPX | GPL-3.0 | yes |
| Xdebug | Xdebug | yes |
| Datadog `dd-trace-php` (collection) | Apache-2.0 | yes (Rust + C) |
| New Relic PHP agent (collection) | Apache-2.0 | yes (C + Go) |
| Tideways extension + daemon | commercial | no (the former OSS [`tideways/php-xhprof-extension`](https://github.com/tideways/php-xhprof-extension) was archived in 2023) |
| Blackfire classic Probe | commercial | no |
| Blackfire Continuous Profiler — collection layer | uses Datadog's `datadog-profiling.so` | yes (collection layer is Datadog's `dd-trace-php`); the surrounding Blackfire agent / backend / UI are commercial |
| All vendor SaaS backends (Datadog, New Relic, Tideways, Blackfire) | commercial | no |

## Decision guide

- *Lowest target overhead, no extension required, OSS* →
  **reli** or **phpspy**. reli for ZTS / native traces / JIT name
  resolution / memory-graph analysis / unfamiliar production
  process; phpspy for the lightest pure-C sampler at typical depths
  and rates.
- *Production-grade SaaS APM with always-on continuous profiling* →
  **Datadog** or **Blackfire** (both use the same underlying
  sampler today; pick by UI / pricing / surrounding ecosystem).
- *Production-grade SaaS APM with PHP-focused framework
  integration* →
  **Tideways** (a PHP-specialised APM) or **New Relic** (broader
  multi-language APM; PHP agent is OSS if that matters to you).
- *Free, production-friendly stack sampler outside a SaaS* →
  **Excimer** (Apache-2.0, runs at MediaWiki scale).
- *Development-time deep dive on a single request* →
  **xhprof** (longxinH), **Xdebug profile**, **SPX**, or
  **Tideways Callgraph**.
- *"Which function is allocating the most memory?"* →
  **Datadog allocations** (production) or **php-memprof** (dev).
- *"Where is a leak coming from, by function?"* → **php-memprof**.
- *"What objects exist in memory right now?"* → **reli**
  (actively maintained); **php-meminfo** is an older alternative
  (last release v1.1.1, 2021).

<details>
<summary>A note on reli being implemented in PHP</summary>

Most of the other sampling-space tools have their hot paths in
native code (phpspy in C, Excimer's core in C, Datadog's profiler
in Rust). reli is written almost entirely in PHP itself. This is
a deliberate choice and it shapes where reli's strengths and
weaknesses fall.

Where the PHP-implementation choice helps:

- *Target-side overhead is very low in our benchmark suite.*
  Sampling happens in a separate process via `process_vm_readv`;
  the target only pays a brief ptrace stop per sample. The
  slowness of reli's own analysis code does not slow down the
  target.
- *No measurement distortion from per-call hooks.* Shared with
  the other samplers in category (1) and (2).
- *No PHP extension to install in the target.* Convenient for
  production attach, ephemeral containers, or environments where
  installing a `.so` is awkward.

Where it costs:

- *Profiler-side CPU is higher* than C samplers at shallow stacks.
  At deep stacks the trade-off reverses thanks to bulk-stack-copy
  + cached binary analysis — see
  [bench/RESULTS.md](bench/RESULTS.md#three-classes-of-stack-walking-cost).
- *Higher RSS* (53 MB vs phpspy's 8 MB).
- *Lower max sample rate* than phpspy on shallow stacks.

The flip side is that PHP implementation made it tractable to
grow features that would have been a much larger undertaking in
C: deep memory-graph analysis, ZTS support, native-stack
unwinding integration, JIT-name resolution, custom output
templates, an MCP server, an HTTP focus bus.

reli also defers some of its profiler-side cost via the `.rbt`
binary trace format: live sampling writes a compact stream and the
expensive analysis (name resolution, callee/caller aggregation)
happens later at `rbt:analyze` / `rbt:explore` time, off the hot
path.

</details>

<details>
<summary>phpspy in detail (the closest sibling)</summary>

reli started out heavily inspired by [phpspy](https://github.com/adsr/phpspy);
the two have since diverged in scope. The detailed prose comparison
here is kept because phpspy is the closest sibling and the most
common point of confusion.

#### Structural difference

The main structural difference is that reli is written in almost
pure PHP while phpspy is written in C. If you want to customise
*what* and *how* information is captured, doing it in PHP is
easier — at some performance cost. (Though we aim to keep that
cost modest.)

#### ZTS PHP

reli can find VM state from ZTS interpreters: daemon-mode traces
of threads started via [ext-parallel](https://github.com/krakjoe/parallel)
are captured automatically, which phpspy alone cannot do.
`inspector:eg` exposes just the EG address so you can feed it to
phpspy manually for ZTS targets, and the
[hybrid phpspy mode](tracing/phpspy-hybrid.md) (`phpspy:trace` /
`phpspy:daemon`) combines reli's ZTS-aware EG resolution with
phpspy's fast C-based tracing.

#### Capabilities reli has that phpspy doesn't (currently)

- More accurate line numbers (opline-level — phpspy reports
  function-entry only, see [Line-level resolution](#line-level-resolution)).
- Output format customisation via PHP templates.
- Running-opcode output for each sample.
- Automatic PHP-version detection from stripped binaries.
- Compact binary trace format (`.rbt`) plus speedscope / pprof /
  folded / callgrind / flamegraph converters
  (see [tracing/binary-trace-format.md](tracing/binary-trace-format.md)).
- Deep memory-graph analysis of the target process.
- Merged native (C-level) stack traces via DWARF `.eh_frame`
  unwinding.
- JIT-compiled function-name resolution via perf map / GDB JIT
  interface.

Nothing above is technically unreachable from phpspy — these may
land there one day.

#### Capabilities phpspy has that reli doesn't (currently)

- Lower memory footprint (8 MB RSS vs reli's 53 MB).
- Higher sustainable sampling rate at shallow stacks.
- Mature, battle-tested in production at scale for years.

#### When to pick which

- **phpspy** — for a straightforward NTS PHP target at typical
  stack depths and rates, where the priority is the lightest
  pure-C sampler. Variable peek at sample time exists in both via
  `-e/--peek-var` (phpspy) and `--trace-var` (reli); reli's
  coverage may be a bit wider but they cover the same general
  capability.
- **reli** — when you need any of those deeper capabilities,
  want to customise capture/output in PHP, need ZTS support
  without extra plumbing, or are sampling deep stacks at high
  rates.
- **hybrid (`phpspy:trace` / `phpspy:daemon`)** — phpspy's
  sampling speed on a ZTS target, or where you prefer
  phpspy-rendered output but need reli's EG resolution. See
  [tracing/phpspy-hybrid.md](tracing/phpspy-hybrid.md).

</details>
