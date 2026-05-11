# Bug: dump→analyze pipeline can't resolve per-module globals (concrete case: `bg_address` for the shutdown-function walk)

## Summary

`EmitModulesJob` walks `BG(user_shutdown_function_names)` to attribute
objects pinned by `register_shutdown_function`. The walk works on the
**live** `inspector:memory` path but is silently skipped on the
**dump → analyze** path, because the dump file format does not persist
`bg_address`. The analyzer receives `bg_address = null` and
`EmitModulesJob::execute()` short-circuits before iterating the
shutdown-function hashtable.

The deeper issue this is the first symptom of: **reli currently has no
consistent story for per-module global addresses in the offline
pipeline.** EG/CG are pre-resolved at dump time and persisted in the
header. BG is pre-resolved at dump time but **not** persisted — so it
silently disappears on offline. ext/uri's lexbor state goes the
opposite way and re-discovers itself entirely at analyze time via
`maybeAttachLexborState` (using the dump's reconstructed memory map +
binary). Whoever adds the next module-globals walker will hit this
same fork in the road. Pick one direction and stick to it.

## Impact

Any object held only by `register_shutdown_function([$this, …])` (or
the closure variant since PHP 8.5) is invisible to offline analysis
even though its bytes are physically present in the dump:

- Appears in ZendMM bin/large-run histograms.
- Counted in `memory_get_usage()`.
- **Not** attached to any reachable root.
- Surfaces as `Only X% of heap analyzed — Y MB unaccounted` in
  `inspector:memory:report`.

Real-world case where this matters: `barryvdh/laravel-snappy` issue
#536. Live `inspector:memory` shows 111 pinned `IlluminateSnappyPdf`
instances under `modules->standard->shutdown_function[N]`; the same
target dumped with `inspector:memory:dump` and analyzed with
`inspector:memory:analyze` shows an empty `modules` root and 1.77 MB
unaccounted. See `docs/memory/case-laravel-snappy-leak.md`.

## Root cause (call sites)

1. `src/Command/Inspector/MemoryDumpCommand.php:102-109` — only
   `findExecutorGlobals` and `findCompilerGlobals` are called.
   `findBasicGlobals` is **not** called. (`MemoryCommand.php:102` does
   call it, which is why the live path works.)

2. `src/Inspector/MemoryDump/MemoryDumpReaderFactory.php:215-223` —
   the dump header writes `eg_address` + `cg_address` (+ `rss_bytes`
   in v2). No slot for `bg_address`.

3. `src/Inspector/MemoryDump/MemoryDumpReaderFactory.php:114-123` —
   `new MemoryDumpReader(...)` is constructed without a `bg_address`
   argument, so the `?int $bg_address = null` default takes effect
   (`MemoryDumpReader.php:38`).

4. `src/Lib/PhpProcessReader/PhpMemoryReader/Collector/Job/EmitModulesJob.php:47-50`
   — when `bg_address === null` the job emits an empty `modules` root
   and returns before reaching the shutdown-function walk.

## Design space (pick one direction before writing code)

Three viable shapes — they trade off format churn vs. analyze-time
cost vs. binary-availability assumptions. The fix for `bg_address` is
small in every direction; the **architectural choice is which one
becomes the convention for future module-globals walkers** (curl,
mysqlnd, ext/openssl, whatever EmitModulesJob grows next).

### Option A: persist per-module addresses in the dump header

- Bump dump format to v3. Reserve a small extensible block (e.g. a
  `uint32 count` followed by `count` × (string key, int64 value))
  for module-globals addresses; populate `eg_address`, `cg_address`,
  `bg_address`, and future entries via the same mechanism.
- `MemoryDumpCommand` resolves all known module-globals symbols at
  dump time via `PhpGlobalsFinder` and serializes the map.
- `MemoryDumpReaderFactory::parse()` reads the map; missing keys
  fall back to `null`.
- **Pros**: no binary needed at analyze time; symbol resolution
  cost is paid once at dump; works with the current minimal-dump
  default (no `--include-binary`).
- **Cons**: dumper has to know upfront which symbols any future job
  might need (or speculatively resolve all of them). Format churn
  whenever the set changes, unless the map is fully generic from
  day one.

### Option B: re-resolve symbols at analyze time

- Make `MemoryDumpReaderFactory::createFromPath()` invoke
  `PhpGlobalsFinder::findBasicGlobals` (and friends) against the
  binary view reconstructed from the dump's memory map + included
  segments, exactly like `CoreDumpReader.php:58` already does for
  core dumps.
- The lexbor module already takes this path
  (`MemoryLocationsCollector::maybeAttachLexborState` →
  `LexborScanRangeFinder`), so the substrate exists.
- **Pros**: zero format change; uniform with the lexbor path;
  extending to a new module is just "wire up another finder at
  analyze time."
- **Cons**: requires the binary to be reachable — either via
  `--include-binary` at dump time or `--dependency-root` at analyze
  time. Silent skip if neither is available is the failure mode
  we're already in.

### Option C: hybrid — header carries the always-needed addresses, analyze-time fallback for the rest

- Persist EG/CG/BG in the header (these three are universal and
  cheap to resolve), and route everything else through the
  analyze-time finder mechanism.
- **Pros**: covers the common case without `--include-binary`;
  leaves the door open for extension-specific globals that
  shouldn't bloat the header.
- **Cons**: two code paths to keep in sync; the bar for "promote
  this symbol to the header" is fuzzy.

### Decision criteria

- Is the project willing to commit to "offline analysis works only
  with `--include-binary` or `--dependency-root` for module-globals
  features"? → Option B.
- Is the project trying to keep the minimal-dump default
  feature-complete? → Option A (or C).
- Recommendation: **C as the convention, A as the concrete first
  step.** Shipping `bg_address` in the header is the minimum to
  unblock the shutdown-function walk regardless of binary access.
  Future modules can opt into either side as the cost/benefit
  calls for, with the lexbor pattern documented as the
  analyze-time blueprint.

## Concrete changes for the chosen `bg_address` step (Option A scoped down)

1. Bump dump format to v3.
   - Header layout: after `cg_address` (+ `rss_bytes` if v2), append
     `int64 bg_address` (use `-1` / `0` as the "unavailable" sentinel
     since BG can legitimately have address 0 on ZTS-with-offset-zero
     edge cases; pick whichever sentinel is consistent with the other
     "absent" markers).
   - `MemoryDumpReaderFactory::parse()` reads `bg_address` for v3+;
     v1/v2 dumps keep yielding `null` (no behavior change).

2. `MemoryDumpCommand`: call
   `$this->php_globals_finder->findBasicGlobals(...)` next to the
   existing `findExecutorGlobals` / `findCompilerGlobals` calls and
   pass the result through `$this->memory_dumper->dump(...)`.

3. `MemoryDumper::dump()` (signature change) and downstream writer
   accept the new `?int $bg_address` and serialize it into the v3
   header.

4. `MemoryDumpReaderFactory::createFromPath()` forwards
   `$parsed['bg_address'] ?? null` into `new MemoryDumpReader(...)`.

5. Tests:
   - Unit test for `MemoryDumpReaderFactory::parse()` round-tripping
     v3 headers, and confirming v1/v2 dumps still parse with
     `bg_address = null`.
   - Integration test (target-version group) that constructs a PHP
     target which registers a shutdown function holding a class
     instance, dumps + analyzes it, and asserts the instance is
     reachable from the `modules->...->shutdown_function[N]` path —
     mirroring the live `inspector:memory` behavior verified manually.

If Option A is generalized to an arbitrary `string => int64` map
instead of a single `bg_address` field, the format bump is the same
size of change, and every subsequent module-globals symbol becomes
a one-line addition. Worth doing now rather than re-bumping later.

## Reproduction

A minimal repro lives at the laravel-snappy case study
(`docs/memory/case-laravel-snappy-leak.md`). The decisive comparison
is: against the same PID,

- `php ./reli inspector:memory -p $PID` → 111 instances of
  `Barryvdh\Snappy\IlluminateSnappyPdf` reachable under
  `modules->standard->shutdown_function[N]`.
- `php ./reli inspector:memory:dump -p $PID -o snap.rmem` then
  `php ./reli inspector:memory:analyze snap.rmem -o snap.analyzed.rmem -f rmem`
  followed by `inspector:memory:report snap.analyzed.rmem` → empty
  `modules` root, 1.77 MB unaccounted, no path reaches the leaked
  instances.

## Notes

- ext/uri's lexbor branch attaches to `modules_context` via
  `maybeAttachLexborState` regardless of `bg_address` — it's an
  example of Option B already in production for one module. Only the
  shutdown-function subtree is currently gated on `bg_address`.
- Older PHP versions (< 8.1) are unaffected by the new closure-via-
  `fci_cache` path on 8.5+ but are still gated on `bg_address`, so the
  fix benefits every supported version.
- The same gap will reappear for any future module-globals walker
  (e.g. ext/curl resource registry, ext/mysqlnd connection pool,
  ext/openssl key store) that relies on a `findXxxGlobals`-style
  pre-resolved address. Land the architectural decision once.
