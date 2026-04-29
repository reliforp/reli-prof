# reli-prof 0.12.0 (DRAFT)

A major leap forward in memory analysis, two new file formats, a new CLI
surface for binary traces, latest-PHP support, and substantial cold-attach
performance work.

## ⚠️ Breaking change

**Minimum PHP version is now 8.4** (was 8.1 in 0.11.4). The reli analyzer
process must run on PHP 8.4 or 8.5; the *target* process you profile is
independent and still spans PHP 7.0 – 8.5. (#708)

If you can't upgrade your reli host to PHP 8.4+, the official Docker image
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
Both pipelines also have a new streaming mode that keeps peak memory
bounded while analyzing very large heaps (#570).

The `inspector:memory:report` engine itself (#556) ships with 15+ specialized
analysis passes — overview, type breakdown, class ranking, companion
detection, top arrays / strings, blame allocation, cycle clusters (SCC),
choke points, structural dedup, dynamic properties, per-property memory,
drill-down, retained-size confidence, GC-pending objects, and ownership
patterns. Findings come back with severity, confidence, hypothesis, and
actionable next-checks; output is human-readable text or machine-parseable
JSON.

## Highlights

- **dump → analyze → report pipeline** out of experimental, with `.rdump`
  and `.rmem` intermediates (#521, #556, #570, #607, #621)
- **`rmem:explore` / `rmem:live` / `rmem:mcp` / `rmem:viz`** — interactive
  TUI, web visualization, MCP bridge for memory snapshots (#627, #634, #629,
  #635)
- **`.rbt` binary trace format** with `rbt:analyze` and `rbt:explore` —
  compact, streamable traces; 1 hour of profiling that produces hundreds
  of MB in phpspy text format typically fits in **a few hundred KB** as
  `.rbt` (#595, #600)
- **Monitor running PHP VMs** — `inspector:watch` polls a target and
  triggers actions when conditions match (memory thresholds, CPU, RSS) —
  including continuous tracing for as long as the condition holds.
  `inspector:sidecar` flips the model: your PHP code requests a snapshot
  at the exact moment it cares about, via a bundled client (PHP 7.0+).
  **A canonical use: register the bundled `MemoryLimitHandler` so a
  `memory_limit` OOM still leaves you with a heap snapshot at the moment
  of death.** (#528, #573, #598, #593, #672)
- **Live variable inspection** — read PHP variable values from a running
  process without instrumentation. `inspector:peek-var` for one-shot
  reads, `inspector:trace --trace-var` for variable annotations on every
  sample. (#543, #599)
- **phpspy hybrid mode** — reli + phpspy: reli resolves EG addresses
  (including ZTS / FrankenPHP, which phpspy alone can't), phpspy
  performs fast C-native sampling. Three new commands —
  `phpspy:install`, `phpspy:trace`, `phpspy:daemon` — cover one-shot
  install, single-process tracing, and concurrent daemon mode. (#520)
- **PHP 8.4 / 8.5 target support, AArch64, FrankenPHP across all modes**
  (#495, #519, #526, #515)

## New commands

`inspector:memory:dump`, `inspector:memory:analyze`, `inspector:memory:report`,
`inspector:memory:compare` (#592), `inspector:watch` (#528),
`inspector:sidecar` (#593), `inspector:peek-var` (#543),
`phpspy:install` / `phpspy:trace` / `phpspy:daemon` (#520),
`rbt:analyze` / `rbt:explore` (#600),
`rmem:explore` / `rmem:viz` / `rmem:live` / `rmem:mcp` / `rmem:query` (#627, #634),
`docker:print-wrapper` (#635).

## New profiling modes

- **Native (C) stack unwinding** via DWARF `.eh_frame` — frames below the
  PHP / C boundary now appear in traces. JIT-compiled PHP frames are
  resolved to symbolic names via opcache's perf map when the target has
  `opcache.jit_debug=0x10` set. (#514)
- **Core dump** post-mortem analysis — analyze a core file instead of
  attaching to a live process (#620, building on #432 / #456 / #458 / #459 /
  #460)

## Runtime / target support

- **PHP 8.4 and 8.5** target support (#495, #519); also a sampling profiler
  perf round (#512)
- **AArch64** architecture (#526)
- **FrankenPHP** — worker, regular, and CLI modes are all supported. Worker
  mode is the most ergonomic for `memory:dump` and `inspector:trace`. See
  [docs/tracing/frankenphp.md](docs/tracing/frankenphp.md) for per-mode
  notes. (#515, #426, #520, #680, #684, #682)
- **ZTS support** rebuilt — in 0.11 only ZTS builds with
  `ZEND_ENABLE_STATIC_TSRMLS_CACHE` defined (and unstripped) would attach.
  0.12 adds:
  - brute-force TLS scanning so **stripped ZTS binaries** also work (#451)
  - an `_id`-based TSRM resolver fallback for PIC libphp.so — distro
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

Generator / Fiber / WeakReference / WeakMap / PHP stream resource / SimpleXML
tracking; per-property memory ranking; cycle detection (SCC); GC-pending
detection; ownership patterns; ZendMM chunk cache + fragmentation passes;
streaming-mode analysis for OOM-resistant work on 6 M+ edge graphs.
(#544, #545, #550, #552, #565, #637, #561, #564, #567, #562, #629, #570)

## Performance

Cold-attach overhaul — ELF parser hot paths, `readSlice` infrastructure
replacing 19 MB `readAll`, libphp.so byte sharing across symbol readers, and
ZTS cache poisoning fixes (#664, #667, #669, #673, #674, #676, #666, #682).
**Persistent binary analysis cache** — parsed ELF symbols and DWARF frame
data persist on disk keyed by libphp.so fingerprint, so the second cold
attach to the same target binary skips the parse entirely (#517, #522).
Lazy dereferencing for remote memory reads (#506). Scatter-gather VM stack
prefetch with multi-slot lazy segment loading (#524, #628).

## Notable bug fixes

- 100 % CPU usage in daemon mode (#530)
- ZendMM chunk iteration could loop infinitely (#535); incomplete chunk
  walk for cached chunks and huge allocations (#686)
- Autoloader detection breaks correctly after first successful load (#721)

Many smaller fixes are also tucked into feature PRs. See the
[0.12.0 milestone](https://github.com/reliforp/reli-prof/milestone/26)
for the full list.

## Other

- XDG Base Directory Specification for config / state paths (#505)
- Automated Docker Hub image publishing for tag pushes, release-branch dev
  builds, and manual one-off builds (#729)

## Acknowledgements

Most of 0.12.0 took shape over a six-week intensive push, layered on
top of reli's pre-existing foundations — the FFI-based memory reading,
ELF parsing, ZendMM walking, and TSRM / TLS resolution that have been
the project's craft work since long before AI coding assistants were
a thing. The bulk of new code on top of those foundations — DWARF
parsing from scratch among many other pieces — was Claude Code's
work, with the maintainer directing and reviewing. "AI-assisted"
understates the inversion: in this round, the human was the
assistant. A separate write-up on the experience is in progress.
