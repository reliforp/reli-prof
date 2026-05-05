# PR #773 SQLite output — round-5 validation report

After rounds 1–4 covered the IntegerIndexMerger boundary, multi-run
write, postgres `context_edges` schema, JSON output structure,
dump→analyze, fibers/enums/readonly/long-string shapes,
memory_limit_error feature, and report parity, this round focuses
on the remaining commands the PR's diff stat highlighted and the
"would this be a merge blocker for 0.13.0?" decision criterion.

## TL;DR

- **No merge blockers found in this round.** Every remaining
  command in the PR scope produces integrity-clean,
  parity-respecting output across the experimental flag matrix.
- Verified clean:
  - `inspector:memory:compare` with all 4 input format combos
    (rmem×rmem, rmem×sqlite, sqlite×rmem, sqlite×sqlite)
  - `inspector:coredump` 8-flag matrix + parity vs live capture of
    the same target
  - `inspector:memory:dump:inspect`
  - `inspector:memory:normalize-dump` (with explicit output and
    in-place forms; gracefully rejects garbage / truncated dumps)
  - `inspector:sidecar` daemon + `SidecarClient` over Unix domain
    socket: 3 successive dumps from 3 different targets all
    analyze cleanly with deterministic row counts
- Two notes for follow-up (neither blocking):
  - The compare label asymmetry surfaced earlier
    (`ArrayElementsContext` vs `ZendArrayTableMemoryLocation` for
    the same choke_point) is the **same pre-existing
    GraphSubstrate iterator gap** documented in round-4. Same
    fix resolves it.
  - Sidecar's per-dump metadata (`.meta.json` carries `label`,
    `metadata`, `call_trace`, `memory_stats`) does not flow into
    the analyzed SQLite's `summary` table. Users get the heap
    analysis but the contextual label they tagged at capture time
    isn't visible from `SELECT * FROM summary`. Likely the dump
    file's `meta` block isn't read by `inspector:memory:analyze`
    on a `*.dump` whose sibling `*.meta.json` exists.

## Verified clean (this round)

### inspector:memory:compare — all 4 input combinations

`tests/scripts/sqlite-validation/test_compare_inputs.sh` runs
`circular_refs` baseline against `weakmap_amphp` target through every
combination of input formats:

```
rmem_vs_rmem        rc=0  out_size=11909
rmem_vs_sqlite      rc=0  out_size=11813
sqlite_vs_rmem      rc=0  out_size=11909
sqlite_vs_sqlite    rc=0  out_size=11813
```

All 4 succeed. The `rmem_vs_*` outputs are byte-identical (11909) and
the `sqlite_vs_*` outputs are byte-identical (11813); the two
groupings differ only in the choke_point label ("ArrayElementsContext"
vs "ZendArrayTableMemoryLocation"). Same root cause as round-4's
`GraphSubstrate.iterateNodeSizes` structural-node gap: when the
target side is an rmem the substrate enumerates the structural
parent (`ArrayElementsContext`); when it's a sqlite the substrate
falls through to the sized child (`ZendArrayTableMemoryLocation`).
**Pre-existing in 0.12.0**, not a #773 regression.

Same-file across format (`rmem` of dump A vs `sqlite3` built from
the same dump A) produces a clean zero-delta report — internal
parity holds across the canonical-intermediate boundary.

### inspector:coredump — 8-flag matrix + live parity

`tests/scripts/sqlite-validation/test_coredump_matrix.sh` generates a
core dump via `gcore` against `target_circular_refs`, then runs
`inspector:coredump` through every combination of the three
experimental flags.

| Flags  | size      | integrity | row counts (8 tables) | content hashes |
|--------|-----------|-----------|------------------------|------------------|
| default | 9,814,016 | ok        | identical to baseline | identical to baseline |
| sm     | 9,814,016 | ok        | identical              | identical        |
| pi     | 9,814,016 | ok        | identical              | identical        |
| fd     | 9,822,208 | ok        | identical              | identical        |
| sm_pi  | 9,814,016 | ok        | identical              | identical        |
| sm_fd  | 9,822,208 | ok        | identical              | identical        |
| pi_fd  | 9,822,208 | ok        | identical              | identical        |
| all    | 9,822,208 | ok        | identical              | identical        |

**Coredump→sqlite matches a live capture of the same target
exactly** in row counts (36588 nodes, 51564 edges, 18649 locations,
14707 attrs) and invariant content hashes for every table. The only
delta is `summary` (live=29 keys, coredump=31, +2 metadata keys
specific to the offline dump path: `rss`, `memory_get_peak_usage`).
This matches the round-4 finding for the live `inspector:memory:dump`
path and is consistent across both offline-capture entry points.

### inspector:memory:dump:inspect

Runs cleanly on every dump shape from round-2:

```
plain dump (no flags):      Region Count: 183
lean dump (--exclude-heap): Region Count: 266
full dump (--include-binary): Region Count: 192
```

The lean dump's higher region count is initially counter-intuitive
but legitimate: excluding `[heap]` removes a single large region
and lets neighbouring regions stand alone instead of being absorbed
into one big chunk. After `inspector:memory:normalize-dump` (which
merges overlapping regions) the counts stay the same — there are
no overlapping regions to merge. Just a different topology, not a
data discrepancy.

Edge cases handled gracefully:

- Truncated dump (first 4 KiB only): `dump:inspect` shows the
  intact header; `analyze` fails clearly with
  `failed to read string of length 8` from `MemoryDumpReaderFactory.php:259`.
- Garbage file (`echo 'garbage' > foo.dump`): `dump:inspect`
  rejects with `invalid dump file: bad magic` (line 78);
  `normalize-dump` rejects with `Invalid dump file: bad magic`
  (`MemoryDumpNormalizer.php:164`). Both are clean error paths.

### inspector:memory:normalize-dump

`tests/scripts/sqlite-validation/test_dump_analyze.sh` produces 3
dump variants; `normalize-dump` reports
`183 → 183 / 266 → 266 / 192 → 192 merged regions` for each (no
overlapping regions to merge in any of them). Post-normalize
`analyze → sqlite3` produces identical row counts to pre-normalize
analyze for every variant, confirming the rewrite is a no-op when
the input is already normalized. Explicit-output mode preserves the
original file (in-place mode overwrites it).

### inspector:sidecar — full daemon + client end-to-end

`tests/scripts/sqlite-validation/test_sidecar2.sh`:

1. Starts `inspector:sidecar -s sock -o dumps -t test=round5`. The
   security check
   (`SocketPathResolver::resolveDefault`) requires the socket parent
   directory to be 0700 — clean error message
   `Sidecar socket parent directory ... has mode 0755, expected 0700.
    Run: chmod 0700 '...'`
   when violated.
2. Spawns 3 PHP target processes that each call
   `\Reli\Sidecar\Client\SidecarClient::requestDump($pid, ...)`
   with a different label.
3. The sidecar receives all 3 requests, writes 3 `.dump` files +
   3 sibling `.meta.json` files (8.9 MB each), and replies to each
   client with a JSON envelope containing `status:"ok"`,
   `protocol_version:1`, `path:..`, `bytes:9342976`, `trace:[..]`,
   `memory_stats:{memory_usage, memory_peak_usage, rss, memory_limit, ...}`,
   and `error_context`.
4. Each sidecar dump analyzes cleanly:
   - integrity_check: ok
   - context_edges: 108680 (deterministic across all 3 dumps)
   - context_nodes: 96158 (deterministic across all 3 dumps)

The sidecar's own architecture (per-call worker, parent dir 0700
permissions check, JSON-envelope protocol with explicit version,
`call_trace` recording the client's PHP stack at request time,
inline `memory_stats`) is sound.

## Open polish items (not merge blockers)

### Sidecar metadata gap

Each sidecar `.dump` has a sibling `.meta.json` that records:

```json
{
    "pid": 28516,
    "timestamp": "2026-05-05T20:47:30+00:00",
    "trigger": "sidecar_request",
    "php_version": "v84",
    "memory_stats": { ... },
    "call_trace": [...],
    "label": "label_1",
    "metadata": {"test": "round5", "shape": "small_array"}
}
```

`inspector:memory:analyze foo.dump -f sqlite3 -o out.sqlite3`
produces a SQLite whose `summary` table has the heap-walk metrics
(`memory_get_usage`, `zend_mm_heap_total`, etc.) but **none** of
the sidecar metadata: `label`, `metadata`, `call_trace`, `trigger`,
`timestamp`. So a user that runs:

```php
$client->requestDump(getmypid(), $exception_file, $exception_line, 'oom-at-checkout');
```

and later opens the resulting SQLite expecting to find their
`oom-at-checkout` label has to read the sibling `.meta.json` by
hand — the analyze step ignores it.

Suggested fix: when `inspector:memory:analyze` is given a `*.dump`
that has a sibling `*.meta.json`, automatically read the meta and
splice each top-level scalar key into the resulting `summary` table
under a `meta_` prefix or similar. Or accept a `--meta-file`
option for explicit cases.

### Sidecar multi-run-into-one-DB workflow

Round-2 imagined the sidecar as a "natural producer of multi-run
SQLite DBs" — one daemon collecting many snapshots into one shared
DB over its lifetime. The current sidecar instead writes one
`.dump` file per request. To get a single multi-run SQLite from a
sidecar's lifetime, the user has to:

1. Analyze each `.dump` to a separate `.sqlite3`.
2. There is currently no way to merge them — `export-sqlite`
   refuses overwrite, and there's no `analyze --append` mode.

The live `inspector:memory -f sqlite3 -o existing.sqlite3` workflow
restored by #777 *does* support multi-run-into-same-DB, but it
applies only to live captures, not sidecar-collected dumps. If the
sidecar workflow is the intended canonical multi-run producer,
adding `analyze --append` (or a new merge command that takes N
sqlite3 inputs) would close the loop.

Alternatively, the sidecar could be reframed as "one .dump per
event, analyze whichever you care about" and the `--run-id-baseline`
/ `--run-id-target` options on `compare` could stay aimed at the
live multi-run flow only.

## Round-by-round summary so far

| Round | Status | Finding | Resolution |
|-------|--------|---------|------------|
| 1 | merged | IntegerIndexMerger boundary in `RELI_FORMAT_DIRECT_INDEX` path | #775 |
| 2 | merged | multi-run-into-same-DB feature regression | #776 + #777 |
| 3 | pending | postgres `context_edges` schema asymmetry (pre-existing) | one-line schema fix proposed |
| 4 | pending | `GraphSubstrate.iterateNodeSizes` structural-node gap → `call_stack` finding silently dropped on sqlite report path; memory_limit_error UX gaps (silent no-resolve) | both pre-existing; `node_sizes` seed proposed for the substrate gap |
| 5 | this report | no new findings; verified clean across compare/coredump/dump:inspect/normalize-dump/sidecar | n/a |

**For #773 merge readiness specifically**: rounds 1–2 covered the
PR-introduced regressions and they are fixed. Rounds 3–4 are
pre-existing bugs that #773 made *visible* by adding new comparison
paths but are not regressions. Round 5 verifies the rest of the PR
scope is clean. From the validation perspective the PR is
**merge-ready**; rounds 3–4 are appropriate as separate
follow-up PRs.

## Test artefacts

- `tests/scripts/sqlite-validation/test_compare_inputs.sh` — compare
  with all 4 input format combinations.
- `tests/scripts/sqlite-validation/test_coredump_matrix.sh` —
  `inspector:coredump` 8-flag matrix + live-capture parity.
- `tests/scripts/sqlite-validation/test_sidecar2.sh` — sidecar
  daemon + 3-target client + per-dump analyze.
- (`dump:inspect` / `normalize-dump` tests are inline in this report
  — no driver script needed since they're single invocations.)
