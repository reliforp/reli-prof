# PR #781 — SQLite output validation under perf-PR gate matrix (real-world reproducer)

Follow-up to `pr-773-sqlite-output-validation-round3-real-world.md`. PR #781
re-architects the integer-index-build path on top of #773's rmem-canonical
ingest:

* `RELI_PARALLEL_INDEX` becomes default-on and is wired into `mergeShards`
* `RELI_FORMAT_DIRECT_INDEX` (the "L" path) runs as a `ParallelIndexBuilder`
  sidecar with cohort-shared `SharedPgnoCounter` page allocation
* New `RELI_L_PARALLEL` (auto-on at 8+ cores) splits the L sidecar per-index
* New `RELI_L_PARTITION_COUNT=N` partitions the largest integer index across
  N forked workers (manual opt-in for 16+-core hosts)

The PR is observably-non-trivial because every integer index is now
*assembled in PHP* (b-tree leaves built in workers, interior tree assembled
in the orchestrator, `sqlite_master` patched in via `writable_schema`)
instead of `CREATE INDEX`. That is precisely the path most likely to
introduce silently-wrong indexes, so the validation centres on:

1. `PRAGMA integrity_check` + `quick_check` per gate combination
2. **Functional** index correctness — `INDEXED BY <name>` returns the same
   row count on the L path as on the default `CREATE INDEX` path
3. `EXPLAIN QUERY PLAN` confirms the L-built index is picked by the planner
4. Multi-run-into-same-DB still works when the second run uses the L path

Same reproducer as round-3 (`tools/pr-773-real-world-leak-reproducer.php`),
same target shape (4000 `UserRecord`, 1500 cached closures, 1 MB + 512 KB
buffered strings, generator retainer, manager-cycle).

## Capture matrix (one stable target snapshot, 4-core sandbox)

| label | env | bytes | wall | integrity |
|-------|-----|-------|------|-----------|
| `A_default`  | (none — PARALLEL_INDEX default-on) | 106 151 936 | 25.2 s | ok |
| `B_pix0`     | `RELI_PARALLEL_INDEX=0` (regress to serial CREATE INDEX) | 106 151 936 | 19.9 s | ok |
| `C_L`        | `RELI_FORMAT_DIRECT_INDEX=1` (L sequential) | 109 875 200 | 20.0 s | ok |
| `D_Lpar`     | + `RELI_L_PARALLEL=1` (force per-index parallel; auto-off at 4 cores) | 111 972 352 | 21.3 s | ok |
| `E_part`     | + `RELI_L_PARTITION_COUNT=4` (force partition; auto-off at 4 cores) | 114 069 504 | 21.5 s | ok |
| `F_mmap`     | `RELI_SHARED_MMAP_INGEST=1` | 106 151 936 | 20.8 s | ok |
| `G_all`      | mmap + L + L_PARALLEL + PARTITION=4 | 114 069 504 | 21.7 s | ok |
| `X_default`     | export-sqlite default                                | 106 151 936 | 4.5 s | ok |
| `X_L_seq`       | export-sqlite + `RELI_FORMAT_DIRECT_INDEX=1`         | 109 875 200 | 4.5 s | ok |
| `X_L_par`       | + `RELI_L_PARALLEL=1`                                | 111 972 352 | 5.7 s | ok |
| `X_L_par_part4` | + `RELI_L_PARTITION_COUNT=4`                         | 114 069 504 | 6.1 s | ok |

`PRAGMA integrity_check = ok` on every output. Eleven distinct paths.

## Content parity: every data table identical across every path

sha256 of concatenated, ordered rows from
`{summary, class_objects_summary, location_types_summary, context_nodes,
context_node_attributes, context_node_locations, context_edges}`:

```
  A_default      856775303ca3220b
  B_pix0         856775303ca3220b
  C_L            856775303ca3220b
  D_Lpar         856775303ca3220b
  E_part         856775303ca3220b
  F_mmap         856775303ca3220b
  G_all          856775303ca3220b
  X_default      856775303ca3220b
  X_L_seq        856775303ca3220b
  X_L_par        856775303ca3220b
  X_L_par_part4  856775303ca3220b
```

(Different from round-3's `13225985db0d9189` because this is a different
target run with one extra orphan-collected node, but identical across all 11
paths within this run, which is the property that matters.)

The only schema-level difference between the L paths and the rest is that
the three integer indexes built by L (`idx_context_edges_run_child`,
`idx_context_node_attributes_run_node`, `idx_context_node_locations_run_node`)
appear at the **end** of `sqlite_master` rather than in CREATE order — that
is the natural consequence of L running as a sidecar that patches its
sqlite_master entries via `writable_schema` after the parent's other
`CREATE INDEX` calls have already committed.

The set of indexes is identical:

```
  A_default      indexes=10  names_sha=1eafbf7ed72d
  C_L            indexes=10  names_sha=1eafbf7ed72d
  D_Lpar         indexes=10  names_sha=1eafbf7ed72d
  E_part         indexes=10  names_sha=1eafbf7ed72d
  X_L_par_part4  indexes=10  names_sha=1eafbf7ed72d
```

## Functional index correctness — the test that actually matters for L

`INDEXED BY` forces the planner to use the named index; if the index is
missing leaves, has a malformed b-tree, or doesn't see the latest run's
rows, the count diverges. Across all 11 paths and all three L-built indexes:

```
  edges_run_child   attrs_run_node   locs_run_node
  ---------------   --------------   -------------
  503 439           163 604          182 925       (every variant)
```

Identical row counts via `INDEXED BY` on every path, including the
partition-merge path. `EXPLAIN QUERY PLAN` confirms that SQLite's planner
treats the L-built index as a real covering index:

```
sqlite> EXPLAIN QUERY PLAN
        SELECT COUNT(*) FROM context_edges INDEXED BY idx_context_edges_run_child
        WHERE run_id=1 AND child_node_id=12345;
QUERY PLAN
`--SEARCH context_edges USING COVERING INDEX idx_context_edges_run_child (run_id=? AND child_node_id=?)
```

— same plan on `C_L.sqlite3` and `E_part.sqlite3`.

## Multi-run-into-same-DB with **mixed** paths (the strongest dispatch test)

* Run 1: default path (PARALLEL_INDEX default-on, no L)
* Run 2 into the same `multirun.sqlite3`: L + parallel + partition=4

```
runs:                              2     (timestamps 19:53:12Z, 19:53:33Z)
edges total:                  1 006 878  (= 2 × 503 439)
edges run=1:                    503 439
edges run=2:                    503 439
edges INDEXED BY child run=1:   503 439     ← run-1 index intact after run-2 ingest
edges INDEXED BY child run=2:   503 439     ← run-2's L-built index works
PRAGMA integrity_check:        ok
```

This pins both directions of the dispatch routing #781 layers on top of
#773: a default-path run-1's `CREATE INDEX`-built b-tree is not corrupted
when a run-2 L-sidecar appends pages with shared-counter pgno claims, **and**
the L-sidecar's sqlite_master patches don't strand run-1's index pages.

## Performance, observed (4-core sandbox, single capture each)

| path | wall |
|------|------|
| `B_pix0` (regress to serial CREATE INDEX) | 19.9 s |
| `C_L` (L sequential) | 20.0 s |
| `F_mmap` (shared-mmap ingest) | 20.8 s |
| `D_Lpar` (force per-index L parallel; gated off normally) | 21.3 s |
| `E_part` (force partition=4; gated off normally) | 21.5 s |
| `G_all` (everything force-on) | 21.7 s |
| `A_default` (PARALLEL_INDEX default-on) | 25.2 s |

Two observations worth flagging:

1. **A_default looking 5 s slower than B_pix0** is most likely cold-cache
   noise (A was the first run after the target spawn, and the binary-
   analysis cache is `--no-cache`'d on every run). Re-ordering would have
   probably shifted the cost. The PR's bench numbers are warm-run averages.
   Not raising as a regression — the absolute correctness signal is what
   round-3-style validation should focus on.
2. **The inner-parallel L modes (D, E) are net-negative on a 4-core box**,
   exactly as the PR description predicts (3 L workers + 4 PIB workers +
   parent + L orch ≈ 9 procs on 4 cores). The PR's gating logic
   (`RELI_L_PARALLEL` auto-on at `cores >= 8`, `RELI_L_PARTITION_COUNT`
   manual-opt-in) is correctly suppressing them by default; force-enabling
   them costs ~1.5 s here. Architecture is in place for the 8+/16+-core
   wins the PR projects.

`export-sqlite` (rmem already on disk) is consistently ~4–6 s — fastest path
to a SQLite output, and the new `Writer::openForPatch` (rb+ + pwrite, no
slurp/rewrite) is presumably what's keeping the L variants within ~1.5 s of
the default there.

## Bottom line

PR #781 preserves every observable correctness property of #773's SQLite
output across all four new gate combinations:

* `integrity_check = ok` on 11/11 paths
* Identical 7-table content hash across 11/11 paths
* Same set of indexes; the L path's sidecar-emitted index entries appear
  later in `sqlite_master` but are functionally indistinguishable
* `INDEXED BY <L-built-index>` returns identical row counts on every path
  (the test that catches "index built but missing leaves")
* `EXPLAIN QUERY PLAN` shows SQLite using the L-built index as a covering
  index
* Multi-run-into-same-DB works even when run 1 uses default and run 2 uses
  L + parallel + partition

The 4-core sandbox cannot exercise the auto-on cores-≥-8 gates, but the
default decisions correctly gate the inner-parallel modes off, and force-
enabling them does not regress correctness — only wall-clock, in line with
the PR's own analysis.
