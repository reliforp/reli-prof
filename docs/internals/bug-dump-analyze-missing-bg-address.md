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

## Design: layered resolution

The pipeline ends up with three tiers, ordered by cost and by how
much state the dump has to carry. Each tier gracefully degrades to
the next; a walker that gets `null` back keeps its current
short-circuit behaviour.

### Tier 1 (primary): dump-time resolve → persist in the header

- Bump dump format to v3. After `cg_address` (+ `rss_bytes` if v2),
  add an extensible block of module-globals addresses — e.g.
  `uint32 count` followed by `count` × `(uint32 key_len, key_bytes,
  int64 address)`. Pre-seed it with `basic_globals`; future module
  walkers add a key without another format bump.
- `MemoryDumpCommand` resolves the symbols at dump time via
  `PhpGlobalsFinder` (it already calls `findExecutorGlobals` /
  `findCompilerGlobals`; adding `findBasicGlobals` next to them is
  one line, plus the dumper-side serialization).
- `MemoryDumpReaderFactory::parse()` reads the map; missing keys
  fall through to tier 2.
- This tier is the only one that works against minimal dumps (no
  `--include-binary`, no `--dependency-root`). It covers the common
  case across the realistic build matrix (distros, alpine, custom
  builds, FrankenPHP-style static-link executables, etc.) — symbol
  availability at dump time is judged by the same `PhpGlobalsFinder`
  the live path already trusts.

### Tier 2 (fallback): analyze-time resolve against the dump's binary view

- Triggered when a symbol the analyzer wants is absent from the
  tier-1 map and the binary is reachable through the dump (via
  `--include-binary` at capture time or `--dependency-root` at
  analyze time).
- `MemoryDumpReaderFactory::createFromPath()` reuses the same
  `PhpGlobalsFinder` machinery that `CoreDumpReader.php:58` already
  invokes against core dumps. The dump's reconstructed memory map
  + binary segments are exactly the substrate that finder expects;
  no new resolver is needed.
- Use cases this rescues:
  - Old dump + newer reli that supports more module walkers
    (header was written before the symbol was on the list).
  - Bespoke / out-of-tree extensions whose symbols the dumper
    didn't know to resolve speculatively.
- Failure mode: stripped binary → finder returns null → tier 3 (or
  the walker short-circuits). Identical to today's behaviour for
  those targets.

### Tier 3 (per-module opt-in): brute-force scanning

- Only when the symbol is structurally absent from a representative
  share of target binaries — ext/uri / lexbor is the canonical
  example, because the lexbor symbol is stripped from typical
  upstream php builds. Tier 1 and tier 2 can't help there.
- This is a per-module scanner, not a substrate. A new
  module-globals walker should default to tiers 1+2 and only opt
  in to tier 3 when there's a concrete reason the symbol is
  unavailable on production targets.

### Resolution algorithm at the createFromPath chokepoint

```
for each module_globals symbol the analyzer wants:
  if dump_header.module_globals[symbol] is set:
    use it                                # tier 1
  else if binary segments are accessible:
    try PhpGlobalsFinder::find<Sym>(...)
    if resolved: use it                   # tier 2
    else: null
  else:
    null                                  # walker short-circuits
                                          # or falls through to its
                                          # per-module tier-3 scan
```

Tier 2 deliberately does not also retry symbols that the tier-1
map records as "resolved" — the persisted address came from the
live process and is authoritative. The map only needs the
`address = -1` "present-but-unresolved" sentinel if the dumper
wants to suppress tier 2 retries for known-stripped symbols; for
now, leaving the key absent and letting tier 2 try cheaply is
simpler.

## Concrete changes

### Tier 1: format bump + dump-time resolve

1. Bump dump format to v3.
   - Header layout: after `cg_address` (+ `rss_bytes` if v2), append
     an extensible map of module-globals addresses. Suggested wire
     format: `uint32 count`, then `count` repetitions of
     `(uint32 key_len, key_bytes, int64 address)`.
   - Pre-seed with `"basic_globals"`. Future module-globals walkers
     add their key in the dumper without another format bump.
   - `MemoryDumpReaderFactory::parse()` reads the map for v3+; v1/v2
     dumps yield an empty map.

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

### Tier 2: analyze-time resolve when the binary is available

4. Introduce a small helper at `MemoryDumpReaderFactory::createFromPath()`
   that, for each module-globals symbol the readers need, does:
   - look up the parsed v3 map first;
   - if absent and the dump exposes binary segments (a) directly
     via `--include-binary` or (b) via the existing
     `dependency-root` path resolver, call the corresponding
     `PhpGlobalsFinder::find<Sym>Globals(...)` against the
     reconstructed `ProcessMemoryMap` + binary view. The substrate
     is identical to `CoreDumpReader.php:50-58`.
   - swallow finder failures into `null` (walker short-circuits as
     today).
5. Wire the helper output as the constructor arguments to
   `new MemoryDumpReader(...)` so the rest of the pipeline sees a
   single resolved `?int` per symbol regardless of which tier
   produced it.

### Tests

6. Unit tests for `MemoryDumpReaderFactory::parse()` round-tripping
   v3 headers (empty map, single-entry, unknown extra keys),
   plus confirmation that v1/v2 dumps still parse with the map
   absent.
7. Unit test for the createFromPath resolution helper: tier-1 hit
   wins; tier-1 miss + binary available → tier-2 hit; both miss →
   null. Use a fixture dump rather than a live PHP process so the
   test stays in the unit suite.
8. Integration test (target-version group) that constructs a PHP
   target which registers a shutdown function holding a class
   instance, dumps + analyzes it, and asserts the instance is
   reachable from the `modules->...->shutdown_function[N]` path —
   mirroring the live `inspector:memory` behavior verified
   manually.
9. Optional second integration test: take a v3 dump *without*
   `basic_globals` in the header (simulate an older reli) but with
   `--include-binary`, then analyze with a newer reli that asks for
   `basic_globals`. Tier 2 should rescue it. This pins down that
   old dumps stay future-analyzable.

Doing the extensible-map shape now rather than a one-off
`int64 bg_address` field costs the same line count and avoids
re-bumping the format the next time a module-globals walker lands.
Wiring tier 2 alongside tier 1 in the same change is cheap because
the `PhpGlobalsFinder` substrate is already shared with
`CoreDumpReader`, and it future-proofs old dumps against newer
walkers — "data is in the dump but invisible" is exactly the
state we're already in for one symbol; landing both tiers at once
closes that whole class of regression.

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
- **`--include-binary` remains a user-facing trade-off.** Tier 1 covers
  the symbols reli knows about at dump time; tier 2 only rescues
  symbols added later (or for out-of-tree extensions) and only when
  the binary travels with the dump. Users who want their dumps to
  stay analyzable across reli upgrades should pass `--include-binary`
  (or keep the originating binary discoverable via
  `--dependency-root`); minimal-dump users accept that newly-added
  module-globals walkers may require re-capture against a live
  process. Worth documenting on the `inspector:memory:dump` help text
  and in `docs/memory/memory-dump.md` so the choice is informed.
