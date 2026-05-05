# PR #773 — SQLite output validation, round 3 (real-world leak shapes)

Cross-checks the SQLite-output paths added/refactored by PR #773 (rmem as
canonical intermediate, parallel-shard ingest, optional shared-mmap and
parallel-CREATE-INDEX) against a PHP target that simultaneously exhibits the
four memory anti-patterns most commonly reported in open GitHub issues.

The previous round documents (`pr-773-sqlite-output-validation-round2.md`,
referenced by commits `e642356` and `f7b6b44`) covered fresh-DB writes and
re-running into an existing reli DB. This round checks the four
correctness-critical observable properties (integrity, schema parity, content
parity, multi-run capability) under a load shape inspired by real, currently-
open production issues — not synthetic fixtures.

## Real-world issue shapes mirrored

Sourced from the two GitHub issue searches the PR review explicitly asks for:

* `https://github.com/search?q=memory_get_usage+&type=issues&s=updated&o=desc&state=open`
* `https://github.com/search?q=%22allowed+memory+size+of%22&type=issues&s=updated&o=desc&state=open`

The reproducer (`tools/pr-773-real-world-leak-reproducer.php`) combines all
four shapes in one PHP process so that one snapshot exercises every code path
the SQLite output has to cover (heavy strings, deep hashes, circular edges,
closure-to-scope edges, hydrated array-of-objects):

| Issue | Pattern |
|-------|---------|
| `CuyZ/Valinor#800`, `phalcon/cphalcon#16954` | Static cache growing unboundedly with closure values that capture scope |
| `thephpleague/flysystem#1856` | Iterator/generator retainers (gen frame holds source array) |
| `glpi-project/glpi#24118` | File payload buffered into memory instead of streamed (1 MB + 512 KB + ~250 KB JSON) |
| `andrefelipeufcg/matrizpermissoes#3`, `librenms/librenms#19593` | Hydrate all rows without pagination (4000 `UserRecord` objects, each carrying a 32-entry `perms` hash and a `manager` back-pointer with a 2-cycle at the root) |

## Captured snapshots

Single live target (one stable usleep loop), six output paths captured
back-to-back so the underlying memory state is identical:

| Output | Command | Resolved bytes |
|--------|---------|----------------|
| `cap.rmem` | `inspector:memory -f rmem` | 46 514 935 |
| `direct.sqlite3` | `inspector:memory -f sqlite3` (default rmem→shard ingest) | 106 094 592 |
| `exported.sqlite3` | `inspector:memory:export-sqlite cap.rmem ...` (default) | 106 094 592 |
| `mmap.sqlite3` | `inspector:memory -f sqlite3` with `RELI_SHARED_MMAP_INGEST=1` | 106 094 592 |
| `pidx.sqlite3` | `inspector:memory -f sqlite3` with `RELI_PARALLEL_INDEX=1` | 106 094 592 |
| `both.sqlite3` | `inspector:memory -f sqlite3` with both above set | 106 094 592 |
| `fdi.sqlite3` | `inspector:memory:export-sqlite ...` with `RELI_FORMAT_DIRECT_INDEX=1` | 106 172 416 |

Reproducer reported `memory_get_usage(true) = 16 777 216`; reli's
`zend_mm_heap_usage` came back at `16 454 072`, with
`heap_memory_analyzed_percentage = 106.25%` (the small overshoot is
double-counting in the analyzer's coverage metric, not data corruption — every
table downstream is internally consistent).

The SQLite databases are 106 MB primarily because of the index pages built
from the analyzed graph (~474 K context_nodes, ~503 K context_edges, ~164 K
node_attributes, ~183 K node_locations).

## Properties verified

### 1. `PRAGMA integrity_check = ok` for every path

```
direct.sqlite3   integrity=ok
exported.sqlite3 integrity=ok
mmap.sqlite3     integrity=ok       (RELI_SHARED_MMAP_INGEST=1)
pidx.sqlite3     integrity=ok       (RELI_PARALLEL_INDEX=1)
both.sqlite3     integrity=ok       (both set)
fdi.sqlite3      integrity=ok       (RELI_FORMAT_DIRECT_INDEX=1)
```

`PRAGMA quick_check` also returns `ok` for both default-path DBs. Importantly
this exercises the regression that the PR description itself calls out and
fixes (the `cell_count = 0` interior-page case in `TableMerger::merge` at the
`leaves % max_children == 1` boundary): with 503 436 edges and 473 857 nodes
plus the indexes, the b-tree assembly is well past the 273-leaf threshold the
unit test pins, and integrity passes.

### 2. Schema parity: identical across all paths

`sqlite3 ... .schema` is byte-identical between `direct.sqlite3` and
`exported.sqlite3`. Eight tables, three secondary indexes on
`context_node_attributes` / `context_node_locations` / `context_edges` (one of
which is the partial index `idx_context_edges_strong_nontree_links ... WHERE
is_tree = 0 AND strength = 'strong'`).

### 3. Content parity: every data table identical across every path

Concatenated rows (ordered by primary key) hashed with sha256:

```
                summary    class_objects_summary    location_types_summary
                context_nodes  context_node_attributes
                context_node_locations  context_edges
  exported          13225985db0d9189
  fdi               13225985db0d9189
  direct            13225985db0d9189
  mmap   (live)     13225985db0d9189
  pidx   (live)     13225985db0d9189
  both   (live)     13225985db0d9189
```

All six paths produce the **same content hash** for the user-facing data
tables. The only intentional per-row difference is `runs.created_at`, which
is set at the moment of write and so naturally varies between separate
captures of the same target (verified: `direct.sqlite3` has
`2026-05-05T19:41:30Z`, `exported.sqlite3` has `2026-05-05T19:41:53Z`; both
are otherwise identical).

`fdi.sqlite3` has a slightly different file size (106 172 416 vs.
106 094 592) — consistent with the format-direct-index path emitting a
different (still-valid) page allocation pattern. Content hash matches,
`integrity_check = ok`, no orphan or missing rows.

### 4. Reproducer artifacts visible in the SQLite output

Cross-checked against what the reproducer constructed:

* `class_objects_summary` reports `UserRecord = 4000` (matches the loop bound
  exactly), `Closure = 1501` (1500 cached closures + 1 generator outer
  closure), `Generator = 1`.
* `location_types_summary` top entry is `ZendStringMemoryLocation count=139167
  memory_usage=7189911` — dominated by the 1 MB / 512 KB raw strings plus
  the JSON-encoded payload and the per-record `name`/`email`/`perm_*` keys.
* `context_nodes` has `StringContext=147169`, `ScalarValueContext=138241`,
  `ArrayElementContext=136698`, `ClosureContext=1501` — all consistent with
  the reproducer's 1500 closures, 4000×32 perm scalars, and 1500 payload
  strings.
* `summary.php_version = v84` matches the host PHP. `analyzer = "reli 0.13.x-dev"`.

### 5. Multi-run-into-same-DB (regression for the workflow `f7b6b44` restored)

Two captures into the same `multirun.sqlite3`:

```
-- after run 1 --
runs:        1
nodes:       473 857
edges:       503 436

-- after run 2 (same -o file) --
runs:        2
distinct run_id in nodes: 2
edges total: 1 006 872     (= 2 × 503 436)
edges run=1:   503 436     (run 1 rows survived)
edges run=2:   503 436
PRAGMA integrity_check = ok
```

`runs` carries two distinct timestamps (`19:46:59Z`, `19:47:21Z`), and
`run_id=1` rows are intact — confirming that the dispatch routing change in
`PdoMemoryOutput::ingestFromRmem` (re-arms the serial INSERT path on second-
or-later capture) does not strand the first run's rows in orphan pages.

### 6. `MemoryExportSqliteCommand`'s refuse-to-overwrite guard

```
$ inspector:memory:export-sqlite /tmp/cap.rmem /tmp/exported.sqlite3
Output already exists: /tmp/exported.sqlite3 (refusing to overwrite)
```

That guard is the surviving overwrite protection (the `MemoryCommand`-side
guard from `e642356` was deliberately dropped by `f7b6b44` to restore
multi-run-into-same-DB). The export-sqlite path is meant to be a one-shot
converter, so refuse-to-overwrite is the correct UX there.

## Wart noted (not a regression)

Pointing `inspector:memory -f sqlite3 -o ...` at an *existing non-reli* file
surfaces a raw PDO exception with a stack trace, rather than a clean error:

```
$ echo hello > /tmp/notadb.sqlite3
$ php ./reli inspector:memory -p $PID -f sqlite3 -o /tmp/notadb.sqlite3
In PdoMemoryOutput.php line 1279:
  SQLSTATE[HY000]: General error: 26 file is not a database
```

Pre-existing reli-shaped DBs are correctly handled (multi-run append).
Pre-existing arbitrary SQLite DBs (`CREATE TABLE foo(x);`) are silently
extended with reli's tables alongside the user's — that may also be worth
tightening, but it is the same behaviour 0.12.0 had so it is out of scope for
this PR. Both observations belong in a follow-up; they do not block PR #773.

## Performance, observed

Wall-clock per output path against this target (~474K nodes / ~503K edges,
4-core sandbox):

| Output | Time |
|--------|------|
| `-f rmem` | 15.6 s |
| `-f sqlite3` (default) | 22.0 s |
| `-f sqlite3` w/ `RELI_SHARED_MMAP_INGEST=1` | 22.1 s |
| `-f sqlite3` w/ `RELI_PARALLEL_INDEX=1` | 21.0 s |
| `-f sqlite3` w/ both | 20.6 s |
| `-f json` | 42.6 s |
| `-f report` | 20.5 s |
| `inspector:memory:export-sqlite cap.rmem ...` | 5.1 s (rmem already on disk) |
| `inspector:memory:export-sqlite ...` w/ `RELI_FORMAT_DIRECT_INDEX=1` | 7.5 s |

The ratio against the PR's own bench numbers (50K-object target, 4-core box)
is consistent: roughly linear in node+edge count for ingest, a bit better
than linear for the rmem→sqlite export. The shared-mmap+parallel-index
combination saves ~6% of wall-clock on this larger dataset; consistent with
the PR description's claim that more cores would close the gap further.

`inspector:memory:compare` on a 1M-edge multi-run DB is heavy enough that it
was killed at the 3-minute mark on this 4-core sandbox. The PR does not
change the compare implementation, so this is observation rather than
regression — but it would be worth a follow-up perf pass given that the
multi-run-into-same-DB capability is the workflow that drives compare.

## Bottom line

PR #773's SQLite output is correct under a real-world load shape:

* `integrity_check = ok` on every path (default, shared-mmap, parallel-index,
  both, format-direct-index, export-from-rmem).
* Schema parity exact.
* Data parity exact (single content hash across every path).
* Multi-run-into-same-DB works end-to-end with no orphan rows.
* The four real-world memory shapes (static-cache leak, generator retainer,
  buffered file payload, unpaginated hydration) all show up correctly in the
  resulting tables — class counts, location-type sizes, and string counts
  match what the reproducer constructed.
