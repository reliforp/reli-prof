# Bug: `inspector:memory:dump` → `:analyze` loses `bg_address`, skipping the shutdown-function walk

## Summary

`EmitModulesJob` walks `BG(user_shutdown_function_names)` to attribute
objects pinned by `register_shutdown_function`. The walk works on the
**live** `inspector:memory` path but is silently skipped on the
**dump → analyze** path, because the dump file format does not persist
`bg_address`. The analyzer receives `bg_address = null` and
`EmitModulesJob::execute()` short-circuits before iterating the
shutdown-function hashtable.

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

## Suggested fix shape (not implemented here)

1. Bump the dump format to v3.
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

## Non-goals

- This bug is independent of the recent `bg_address` work for FFI/uri
  module attribution; ext/uri's lexbor branch attaches to
  `modules_context` via `maybeAttachLexborState` regardless of
  `bg_address`. Only the shutdown-function subtree is gated on it.
- Older PHP versions (< 8.1) are unaffected by the new closure-via-
  `fci_cache` path on 8.5+ but are still gated on `bg_address`, so the
  fix benefits every supported version.
