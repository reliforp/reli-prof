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
mechanism to thread a per-module globals address through to the
offline analyzer at all.** EG and CG are the only addresses the dump
format carries; everything else is implicitly assumed to be either
(a) reachable via EG/CG, (b) re-discoverable from raw memory contents
without symbol resolution, or (c) gone. BG falls into (c) today even
though resolving it at dump time costs nothing — the value is
computed and then dropped on the floor before the header is written.

ext/uri's lexbor state is **not** an example of an alternative
"resolve at analyze time" design — it's a forced workaround. The
lexbor symbol is stripped from typical production php builds, so
`findXxxGlobals`-style resolution is structurally impossible and the
scanner falls back to brute-force range identification from the
dump's memory map. That technique is module-specific and expensive;
it is not a general substitute for symbol resolution.

Whoever adds the next module-globals walker (curl, mysqlnd, openssl,
…) will hit the same gap. The architectural question is just: where
in the pipeline does the address get pinned down? The only realistic
answer for symbols that the linker actually exposes is "at dump time,
into the dump header." Brute-force scanning is the lexbor-shaped
escape hatch for the cases where that fails.

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

## Design space

### Recommended: persist per-module addresses in the dump header

- Bump dump format to v3. After `cg_address` (+ `rss_bytes` if v2),
  add a small extensible block — e.g. `uint32 count` followed by
  `count` × (string key, int64 value) — for module-globals
  addresses. Pre-seed it with `basic_globals`; new modules add a
  key without further format churn.
- `MemoryDumpCommand` resolves the symbols at dump time via
  `PhpGlobalsFinder` (it already calls `findExecutorGlobals` /
  `findCompilerGlobals`; adding `findBasicGlobals` next to them is
  one line, plus the dumper-side serialization).
- `MemoryDumpReaderFactory::parse()` reads the map; missing keys
  fall back to `null` so existing module-globals walkers (which
  already `if (=== null) return;`) keep working uniformly.
- This is the only choice that holds up across the realistic
  build matrix: distros, alpine, custom builds, FrankenPHP-style
  static-link executables, etc. Symbol availability at dump time
  is checked by the same `PhpGlobalsFinder` the live path already
  trusts; whatever it can resolve, the dumper persists.

### Why not "re-resolve at analyze time"

- Plausible in theory (`CoreDumpReader.php:58` does it for core
  dumps), but it requires the binary to be present at analyze time
  via `--include-binary` or `--dependency-root`, and to retain its
  dynamic symbol table. Stripped production binaries break this.
  The lexbor scanner exists precisely because that constraint
  doesn't hold for the lexbor symbol in upstream php builds.
- For `basic_globals` the symbol is part of php itself and is
  almost always resolvable wherever reli can attach in the first
  place, so this path *would* work most of the time — but the
  dump-time resolution path is cheaper, simpler, and has none of
  these caveats. The cases where dump-time resolution fails
  (e.g. heavily stripped static linker) are exactly the cases
  where analyze-time resolution would fail too.

### When brute-force scanning is the right answer

- Only when the symbol is structurally absent from a representative
  share of target binaries. ext/uri / lexbor is the canonical
  example. A new module-globals walker should default to
  header-persisted addresses; brute-force is an opt-in per-module
  fallback, not a substrate.

## Concrete changes

1. Bump dump format to v3.
   - Header layout: after `cg_address` (+ `rss_bytes` if v2), append
     an extensible map of module-globals addresses. Suggested wire
     format: `uint32 count`, then `count` repetitions of
     `(uint32 key_len, key_bytes, int64 address)`. `address = -1`
     reserved for "symbol present but unresolved" if the dumper ever
     needs to distinguish that from "key absent."
   - Pre-seed with `"basic_globals"`. Future module-globals walkers
     add their key in the dumper without another format bump.
   - `MemoryDumpReaderFactory::parse()` reads the map for v3+; v1/v2
     dumps yield an empty map, which means every lookup falls back
     to `null` and existing walkers keep their current short-circuit
     behavior.

2. `MemoryDumpCommand`: call
   `$this->php_globals_finder->findBasicGlobals(...)` next to the
   existing `findExecutorGlobals` / `findCompilerGlobals` calls and
   pass the result through `$this->memory_dumper->dump(...)`.
   Swallow resolution failures (log + continue with the key absent
   from the map) rather than aborting the dump — losing the
   shutdown-function walk is strictly better than losing the dump.

3. `MemoryDumper::dump()` (signature change) and downstream writer
   accept a `array<string, int>` of module-globals addresses and
   serialize it into the v3 header.

4. `MemoryDumpReaderFactory::createFromPath()` looks up
   `'basic_globals'` in the parsed map and forwards it as the
   `bg_address` argument to `new MemoryDumpReader(...)`. A thin
   helper like `?int $bg_address = $parsed['module_globals']['basic_globals'] ?? null`
   keeps the call site obvious.

5. Tests:
   - Unit test for `MemoryDumpReaderFactory::parse()` round-tripping
     v3 headers (including empty map, single-entry map, unknown
     extra keys), and confirming v1/v2 dumps still parse with the
     map absent.
   - Integration test (target-version group) that constructs a PHP
     target which registers a shutdown function holding a class
     instance, dumps + analyzes it, and asserts the instance is
     reachable from the `modules->...->shutdown_function[N]` path —
     mirroring the live `inspector:memory` behavior verified
     manually.

Doing the extensible-map shape now rather than a one-off
`int64 bg_address` field costs the same line count and avoids
re-bumping the format the next time a module-globals walker lands.

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
  `maybeAttachLexborState` regardless of `bg_address`. That path is a
  brute-force scanner used because the lexbor symbol is stripped from
  typical production php builds — i.e. an unavoidable workaround, not
  a design pattern to extend. The right precedent to follow for new
  module-globals walkers is EG/CG (resolve at dump time, persist in
  header), not lexbor.
- Older PHP versions (< 8.1) are unaffected by the new closure-via-
  `fci_cache` path on 8.5+ but are still gated on `bg_address`, so the
  fix benefits every supported version.
- The same gap will reappear for any future module-globals walker
  (e.g. ext/curl resource registry, ext/mysqlnd connection pool,
  ext/openssl key store). Doing the dump-header map shape now means
  that gap closes by adding one key to the dumper-side resolution
  list, without another format bump.
