# reli-prof 0.12.0

The biggest expansion of Reli so far.

What started life as a weird low-level technical stunt now looks much more like
a real tool: a broader set of tracing, memory analysis, monitoring, and runtime
inspection capabilities.

This release also substantially improves cold attach performance, expands
target/runtime coverage (PHP 8.4 / 8.5, AArch64, FrankenPHP, stronger ZTS
support), and adds new ways to integrate Reli into real profiling and debugging
workflows.

## Highlights

- **Memory analysis is no longer experimental** — `inspector:memory` grows
  into a full dump → analyze → report pipeline with `.rdump` and `.rmem`
  intermediates, streaming analysis, interactive exploration, web UI, and
  automatic findings. (#521, #556, #570, #607, #621, #627, #629, #634)

- **Binary traces arrive as a first-class workflow** — new `.rbt` format plus
  `rbt:analyze` and `rbt:explore` provide compact, streamable traces with a
  dedicated CLI workflow. One hour of profiling that would often produce
  hundreds of MB in phpspy text typically fits in a few hundred KB as `.rbt`.
  (#595, #600)

- **Monitoring and on-demand capture** — `inspector:watch` can trigger
  actions when conditions match (memory, RSS, CPU, stack/function signals),
  while `inspector:sidecar` lets your PHP code request a snapshot exactly
  when it matters. A canonical use is capturing a heap snapshot on
  `memory_limit` failure. (#528, #573, #593, #598, #672)

- **Live variable inspection** — `inspector:peek-var` reads PHP variable
  values from a running process, and `inspector:trace --trace-var` can attach
  variable annotations to samples. (#543, #599)

- **Hybrid phpspy mode** — Reli resolves EG addresses (including ZTS /
  FrankenPHP cases phpspy alone cannot handle), then delegates fast sampling
  to phpspy via `phpspy:install`, `phpspy:trace`, and `phpspy:daemon`.
  (#520)

- **Broader runtime and target coverage** — PHP 8.4 / 8.5, AArch64,
  FrankenPHP across all modes, and a major ZTS support rebuild for stripped,
  PIC, distro, and idle-worker cases. (#451, #495, #515, #516, #519, #526,
  #664, #666, #680, #682)

## ⚠️ Breaking change

**Minimum PHP version is now 8.4** (was 8.1 in 0.11.4). The Reli analyzer
process must run on PHP 8.4 or 8.5; the *target* process you profile is
independent and still spans PHP 7.0 – 8.5. (#708)

If you can't upgrade your Reli host to PHP 8.4+, the official Docker image
(`reliforp/reli-prof:0.12.0`) ships with PHP 8.5, and the new
**`docker:print-wrapper`** command (#635) generates a shell wrapper so
`reli` feels native even when running through Docker:

```bash
eval "$(docker run --rm reliforp/reli-prof docker:print-wrapper)"
reli inspector:trace -p <pid>   # no local PHP required
```

## Memory analysis is no longer experimental

The `inspector:memory` command, which carried an `[experimental]` tag in
0.11.x, has graduated and the memory toolchain has been substantially
overhauled (#621). What was a single command now ships as a full pipeline —
`inspector:memory:dump` / `:analyze` / `:report` / `:compare` plus the new
`rmem:*` family — and the recommended workflow has changed.

**Before (0.11.x):** profile a live process and read JSON output by hand.  
**Now (0.12.0):** dump once, analyze repeatedly, explore interactively.

```text
# 1. Capture a raw memory dump from a running process
sudo reli inspector:memory:dump -p <pid> -o snapshot.rdump

# 2. Analyze and convert to the .rmem graph format
reli inspector:memory:analyze snapshot.rdump -f binary -o snapshot.rmem

# 3. Report / explore the snapshot
reli inspector:memory:report  snapshot.rmem      # automatic findings (15+ passes)
reli rmem:explore             snapshot.rmem      # interactive TUI
reli rmem:live                snapshot.rmem      # HTTP server with web UI
reli rmem:mcp                 snapshot.rmem      # MCP bridge for AI clients
```

The new `.rmem` binary format (#607) is the compact, mmap-friendly
intermediate, and the natural default. SQLite is also supported as an
alternate path when you want ad-hoc SQL queryability over the snapshot.
Both pipelines also have a new streaming mode that keeps peak memory bounded
while analyzing very large heaps (#570).

The `inspector:memory:report` engine itself (#556) now ships with 15+ analysis
passes covering high-level overview, type/class ranking, arrays and strings,
allocation blame, cycle detection (SCC), choke points, structural dedup,
ownership patterns, dynamic properties, per-property memory, GC-pending
objects, retained-size confidence, and more. Findings come back with
severity, confidence, hypothesis, and actionable next-checks; output is
human-readable text or machine-parseable JSON.

## Binary traces become first-class

Reli 0.12 introduces the `.rbt` binary trace format and a dedicated CLI
workflow around it (#595, #600). The format is compact, streamable, and
designed for long-running sampling sessions that would otherwise produce very
large phpspy-compatible text output.

The new commands:

- `rbt:analyze` — one-shot analysis and summary
- `rbt:explore` — interactive terminal exploration

Together, these make binary traces more than just an export format: they
become a practical default workflow for collecting and inspecting large trace
datasets.

## Monitoring and on-demand capture

Reli can now watch running targets and react when conditions match.

`inspector:watch` polls a target and triggers actions when conditions such as
memory, RSS, CPU, stack/function signals, or variable values match. This
includes continuous tracing for as long as the condition holds. (#528, #598)

`inspector:sidecar` flips the model: instead of polling from outside, your PHP
code can request a snapshot at the exact moment it cares about via a bundled
client that works on PHP 7.0+. (#593, #672)

A canonical use case is registering the bundled `MemoryLimitHandler` so that a
`memory_limit` OOM still leaves you with a heap snapshot at the moment of
failure.

## Live variable inspection

Reli can now read PHP variable values from a running process without
instrumentation.

- `inspector:peek-var` performs one-shot reads from a live process (#543)
- `inspector:trace --trace-var` attaches variable annotations to each sample
  in a trace (#599)

This makes it possible to correlate traces with runtime state, not just call
stacks.

## New command families

- **Memory pipeline:** `inspector:memory:dump`, `:analyze`, `:report`,
  `:compare` (#592)
- **Memory exploration:** `rmem:explore`, `rmem:viz`, `rmem:live`,
  `rmem:mcp`, `rmem:query` (#627, #634)
- **Binary traces:** `rbt:analyze`, `rbt:explore` (#600)
- **phpspy integration:** `phpspy:install`, `phpspy:trace`, `phpspy:daemon`
  (#520)
- **Other new entry points:** `inspector:watch` (#528),
  `inspector:sidecar` (#593), `inspector:peek-var` (#543),
  `docker:print-wrapper` (#635)

## New profiling modes

- **Native (C) stack unwinding** via DWARF `.eh_frame` — frames below the
  PHP / C boundary now appear in traces. JIT-compiled PHP frames are
  resolved to symbolic names via opcache's perf map when the target has
  `opcache.jit_debug=0x10` set. (#514)

- **Core dump** post-mortem analysis — analyze a core file instead of
  attaching to a live process. (#620, building on #432 / #456 / #458 / #459 /
  #460)

## Runtime / target support

- **PHP 8.4 and 8.5** target support (#495, #519); also a sampling profiler
  perf round (#512)

- **AArch64** architecture support (#526)

- **FrankenPHP** — worker, regular, and CLI modes are all supported. Worker
  mode is the most ergonomic for `memory:dump` and `inspector:trace`. See
  [docs/tracing/frankenphp.md](docs/tracing/frankenphp.md) for per-mode
  notes. (#515, #426, #520, #680, #684, #682)

- **ZTS support rebuilt** — in 0.11 only ZTS builds with
  `ZEND_ENABLE_STATIC_TSRMLS_CACHE` defined (and unstripped) would attach.
  0.12 adds:
  - brute-force TLS scanning so **stripped ZTS binaries** also work (#451)
  - an `_id`-based TSRM resolver fallback for PIC `libphp.so` — distro
    PHP-ZTS packages (#515)
  - symbol-preemption handling for statically-linked libphp — FrankenPHP
    (#680)
  - TSRM cache candidate validation to catch idle-worker false positives
    (#682, #666 negative-caching fix)
  - cold-attach correctness fixes (#664)

  Combined, real-world ZTS distributions — distro builds, FrankenPHP,
  stripped / PIC, idle workers — now profile out of the box. Expanded ZTS
  test matrix (#516).

## Memory analysis additions (selected)

Memory analysis in 0.12 expands in three directions:

- **Broader heap coverage:** Generator / Fiber, WeakReference / WeakMap,
  PHP stream resources, SimpleXML, PDO / mysqlnd internal allocations
  (columns, bound parameters), and global callback / shutdown handler
  retention are now tracked explicitly.
- **Richer graph analysis:** new additions include per-property memory ranking,
  cycle detection (SCC), GC-pending detection, ownership patterns, and
  ZendMM chunk cache / fragmentation analysis.
- **Better scalability:** streaming-mode analysis keeps memory usage bounded
  and makes OOM-resistant work on graphs with 6 M+ edges practical.

(#544, #545, #547, #550, #552, #561, #562, #564, #565, #567, #570, #581,
#582, #590, #629, #637)

## Performance

Cold attach is substantially faster and more robust in 0.12.0, especially for
complex ZTS / libphp / FrankenPHP cases.

Key changes include ELF parser hot-path work, `readSlice` infrastructure in
place of large `readAll` calls, `libphp.so` byte sharing across symbol readers,
lazy dereferencing for remote memory reads, scatter-gather VM stack prefetch,
and a persistent on-disk binary analysis cache keyed by `libphp.so` fingerprint.
(#506, #517, #522, #524, #628, #664, #667, #669, #673, #674, #676, #666,
#682)

## Notable bug fixes

- 100 % CPU usage in daemon mode (#530)
- ZendMM chunk iteration could loop infinitely (#535)
- incomplete chunk walk for cached chunks and huge allocations (#686)
- autoloader detection breaks correctly after first successful load (#721)

Many smaller fixes are also tucked into feature PRs. See the
[0.12.0 milestone](https://github.com/reliforp/reli-prof/milestone/26)
for the full list.

## Other

- XDG Base Directory Specification for config / state paths (#505)
- automated Docker Hub image publishing for tag pushes, release-branch dev
  builds, and manual one-off builds (#729)

## Acknowledgements

Most of 0.12.0 took shape over a six-week intensive push on top of Reli's
long-standing foundations: FFI-based memory reading, ELF parsing, ZendMM
walking, and TSRM / TLS resolution.

The bulk of new code in this round — including substantial new subsystems such
as DWARF parsing from scratch — was Claude Code's work, directed and reviewed
by the maintainer. In that sense, "AI-assisted" undersells the inversion: for
this release, the human was often the assistant.

A separate write-up on that development experience is in progress.
