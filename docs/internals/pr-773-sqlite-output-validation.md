# PR #773 SQLite output — real-world validation report

Validation of the `claude/unify-memory-output-3Wh8M` branch's SQLite output
against PHP processes shaped after open GitHub issues from the
`memory_get_usage` and `"allowed memory size of"` searches.

## TL;DR

- **Default ingest path (no flags) and `RELI_SHARED_MMAP_INGEST=1` and
  `RELI_PARALLEL_INDEX=1`, individually or combined, produce
  byte-identical, integrity-clean SQLite output.** No defects found.
- **`RELI_FORMAT_DIRECT_INDEX=1` (the off-by-default
  `IntegerIndexMerger` path) produces silently wrong query results at
  realistic memory-dump sizes** — `PRAGMA integrity_check` fails with
  duplicate page references in three indexes
  (`idx_context_edges_run_child`,
  `idx_context_node_locations_run_node`,
  `idx_context_node_attributes_run_node`), and `COUNT(*)` over those
  tables returns over-counts when SQLite picks the corrupt index as a
  covering index. The underlying tables themselves are correct
  (`SELECT COUNT(*) FROM table NOT INDEXED` matches the default path).
- The likely bug site is `IntegerIndexMerger.php:186-189`: for
  non-final chunks the last cell's `pgno` is used as the chunk's
  `rightmost` *while still being kept in `$chunk` as a cell*, so that
  `pgno` is referenced twice on the resulting interior page (as cell
  index `max_children - 1`, **i.e. cell 122 in the integrity_check
  output**, and as the rightmost pointer).
- Recommended action: keep `RELI_FORMAT_DIRECT_INDEX` strictly
  off-by-default until that boundary case is fixed, and update the
  `IntegerIndexMerger` docblock claim "L is still correct (5K
  integrity_check passes, COUNT(*) matches full-scan)" — the test
  evidently bottomed out below the threshold (~7K leaf pages) and the
  comment is now wrong at memory-dump scale.

## Methodology

Targets were small PHP scripts that hold structures matching the shapes
in the GitHub issues (full sources in
[`tests/scripts/sqlite-validation/targets/`](#scripts) below). Each
target runs to a stable state, parks, and writes its PID to a
well-known file so the driver can attach.

For each target the validation driver does:

1. `inspector:memory -p <pid> -f rmem -o capture.rmem` — canonical
   intermediate.
2. `inspector:memory:export-sqlite capture.rmem default.sqlite3` —
   default ingest (parallel-shard + serial CREATE INDEX).
3. Same with `RELI_SHARED_MMAP_INGEST=1 RELI_PARALLEL_INDEX=1
   RELI_FORMAT_DIRECT_INDEX=1` → `shared_mmap.sqlite3`.
4. `PRAGMA integrity_check;` on every produced file.
5. Per-table `COUNT(*)` cross-comparison.
6. `sha256sum` of sorted projections of large tables to catch
   ordering-independent content drift.

The `inspector:memory:export-sqlite` step lets the same `.rmem`
capture be replayed against multiple flag combinations, so each
"run" below comes from byte-identical input data.

## Real-world repros used

| Label                  | GitHub source                                                                                | Shape                                                                            |
|------------------------|----------------------------------------------------------------------------------------------|----------------------------------------------------------------------------------|
| `phalcon_orm_cache`    | [phalcon/cphalcon#16954](https://github.com/phalcon/cphalcon/issues/16954)                   | array cache keyed by query, value = list of model objects with nested arrays    |
| `valinor_closure_cache`| [CuyZ/Valinor#800](https://github.com/CuyZ/Valinor/issues/800)                               | static cache of reflection objects keyed by `spl_object_hash($closure)`         |
| `large_buffer`         | [glpi-project/glpi (document download regression)](https://github.com/glpi-project/glpi/issues?q=document+memory) | one or two huge strings + an array of medium strings                            |
| `querybuilder`         | [nextcloud/server (DBAL QueryBuilder reached 5GB)](https://github.com/nextcloud/server/issues?q=querybuilder+memory) | many nested associative arrays representing query rows held in memory          |

## Results — flag matrix on the phalcon_orm_cache rmem (5000 rows)

Run via `inspector:memory:export-sqlite` so the input is byte-identical
across all rows.

| Flags                                                         | err_lines¹ | file size       |
|---------------------------------------------------------------|------------|-----------------|
| (default, no flags)                                           |          1 (`ok`) | 154,726,400 |
| `RELI_SHARED_MMAP_INGEST=1`                                   |          1 (`ok`) | 154,726,400 |
| `RELI_PARALLEL_INDEX=1`                                       |          1 (`ok`) | 154,726,400 |
| `RELI_SHARED_MMAP_INGEST=1` + `RELI_PARALLEL_INDEX=1`         |          1 (`ok`) | 154,726,400 |
| **`RELI_FORMAT_DIRECT_INDEX=1`**                              |       **33** ❌ | 154,832,896 |
| **all three flags (the documented experimental combination)** |       **33** ❌ | 154,832,896 |

¹ `sqlite3 file.sqlite3 'PRAGMA integrity_check;' | wc -l` — 1 means
single `ok` line; >1 means errors.

The sizes are deterministic — three separate runs of the all-flags
combination produced the same `err_lines=33` and the same row counts
in every table. The `+106 KiB` size delta between corrupt and clean
output is the pages occupied by the duplicate index entries.

The corrupt path is **strictly worse**: sm-only and pi-only and
sm+pi all produce byte-identical files to default, so the size-time
trade-off (PR description) doesn't justify enabling FORMAT_DIRECT
without a fix. Even if it were a wall-clock win, queries against
the resulting DB would be wrong.

## Defect detail

`PRAGMA integrity_check` on the `RELI_FORMAT_DIRECT_INDEX=1` output
reports 33 issues — 30 "2nd reference to page X" entries and 3
"wrong # of entries in index" entries:

```
Tree 16 page 22865 cell 122: 2nd reference to page 22813
Tree 16 page 22864 cell 122: 2nd reference to page 22690
... (28 more "cell 122: 2nd reference" lines, all on Tree 14 / 15 / 16)
wrong # of entries in index idx_context_edges_run_child
wrong # of entries in index idx_context_node_locations_run_node
wrong # of entries in index idx_context_node_attributes_run_node
```

Tree 14 / 15 / 16 are exactly the rootpages for the three indexes
listed in `PdoMemoryOutput::integerIndexSpecs()`:

```
sqlite> SELECT name, rootpage FROM sqlite_master
        WHERE name IN ('idx_context_edges_run_child',
                       'idx_context_node_locations_run_node',
                       'idx_context_node_attributes_run_node');
idx_context_node_locations_run_node|14
idx_context_node_attributes_run_node|15
idx_context_edges_run_child|16
```

These are the three indexes that `IntegerIndexMerger` builds; the
other six user indexes (compound-key, text-keyed, partial-WHERE) go
through the regular CREATE INDEX path and remain correct in every
flag combination.

### User-visible impact

`COUNT(*)` and any covering-index query against the affected tables
returns wrong (over-counted) results:

```
== COUNT(*) via different access paths ==
  default.sqlite3       plain=720347  not_indexed=720347  subquery=720347
  iso_fd.sqlite3        plain=726107  not_indexed=720347  subquery=726107
  shared_mmap.sqlite3   plain=726107  not_indexed=720347  subquery=726107

== EXPLAIN QUERY PLAN of plain COUNT(*) on iso_fd ==
QUERY PLAN
`--SCAN context_edges USING COVERING INDEX idx_context_edges_run_child
```

The data tables themselves are correct — `SELECT ... NOT INDEXED`
returns the right count. So the underlying ingest is fine; only the
index-merge step is broken. But typical analysis queries
(`WHERE child_node_id = ?`, `WHERE node_id = ?`) hit those indexes,
so users on the experimental flag get silently inflated answers
without any visible error.

### Cross-target reproduction

Same defect, same shape, scaled with the dump:

| Target                | rmem size | context_edges (default → fd) | extra entries |
|-----------------------|-----------|-------------------------------|---------------|
| `phalcon_orm_cache`   |   62 MiB  |   720,347 → 726,107          |   +5,760      |
| `valinor_closure_cache` | 14 MiB  |   171,321 → 172,632          |   +1,311      |
| `querybuilder`        |  112 MiB  | 1,445,283 → 1,456,852         |  +11,569      |
| `large_buffer`        |    4 MiB  |    39,282 →  39,282           |       0       |

`large_buffer` is too small to trigger — it stays under the threshold
where the merger's interior-page assembly takes the buggy branch
(`count($level) >= $max_children` at
`IntegerIndexMerger.php:172`). The other three are well within the
size of a real-world memory dump, so any user that turns on
`RELI_FORMAT_DIRECT_INDEX` against a real workload will hit it.

### Likely root cause

`IntegerIndexMerger.php:171-201`, lines 186-189:

```php
$max_children = $this->maxInteriorCellCount($page_size);
while (count($level) >= $max_children) {
    $next = [];
    $remaining_rightmost = $rightmost;
    for ($i = 0, $n = count($level); $i < $n; $i += $max_children) {
        $chunk = array_slice($level, $i, $max_children);
        $is_last_chunk = ($i + $max_children >= $n);
        if ($is_last_chunk) {
            $chunk_rightmost = $remaining_rightmost;
        } else {
            $last = $chunk[count($chunk) - 1];
            $chunk_rightmost = $last['pgno'];          // <-- uses last cell's pgno
        }                                               //
        $page = $this->buildInteriorPage(               // <-- but $chunk still contains $last
            $chunk, $chunk_rightmost, $page_size);      //     so $last['pgno'] is on the page twice
```

For non-final chunks the last entry's `pgno` is taken as the chunk's
`rightmost` *and the entire chunk (including that last entry) is
passed to `buildInteriorPage`*. The resulting interior page therefore
references `$last['pgno']` from two slots: cell index
`max_children - 1` (i.e. **122** on the system that produced these
traces, where `max_children = 123` for 4-KiB index pages with the
schema's key shape) and the rightmost-pgno header field. That's
exactly the "page X cell 122: 2nd reference to page Y" pattern,
and the matching `wrong # of entries` count comes from those
duplicates.

This is the same flavor of boundary bug the PR description calls out
having fixed in `TableMerger::merge` / `TableInteriorPageBuilder::
greedyGroup` (the `count % max_children == 1` cell_count = 0 case);
the analogous fix here is probably to `array_pop($chunk)` after
recording the promoted divider+pgno when `!$is_last_chunk`, mirroring
how `is_last_chunk` already takes its rightmost from outside the
chunk.

The `IntegerIndexMerger` docblock at `PdoMemoryOutput.php:1224` says:

> L is still correct (5K integrity_check passes, COUNT(*) matches
> full-scan); it just isn't a wall-clock win at this scale with the
> current PHP-only implementation.

That's true at 5K rows but wrong at the sizes we measured. The
threshold tracks `max_children = 123`: the level of leaves has to
reach ≥ 123 for the buggy branch to enter, which is roughly
`max_children × leaf_capacity ≈ 6.7K rows` for a `(run_id, int)`
schema. `large_buffer` at 39K rows didn't trigger because the
context_node_attributes table had 13K rows, not enough leaves of the
attributes-table size to reach the threshold for that specific index;
the moment any of the three indexed tables crosses ~7K rows the bug
appears.

## What the default path looks like at the same scale

For reference, the sorted-content hash of `context_edges` over
`(child_node_id, parent_node_id, run_id)` is identical across:
- `default.sqlite3` (no flags)
- `iso_sm.sqlite3`
- `iso_pi.sqlite3`
- `iso_sm_pi.sqlite3`
- `from_rmem.sqlite3` (round-trip via `inspector:memory:export-sqlite`)
- `iso_fd.sqlite3` and `iso_sm_fd.sqlite3` (corrupt — but the *table*
  content matches; only the index has duplicate entries)

So the data-side parity that the PR's `PdoMemoryOutputRmemIngestTest`
checks holds in practice on every real-world target we tried. The
PR's invariants

> - Direct-write parity for every user-facing table
> - `PRAGMA integrity_check = ok` after the merge

both hold for the default path and for every supported combination of
the two non-FORMAT_DIRECT experimental flags. They do not hold for
`RELI_FORMAT_DIRECT_INDEX=1` once any of the three integer-keyed
indexes crosses ~7K rows.

## Scripts

The driver scripts and target reproductions used for this validation
are checked in under `tests/scripts/sqlite-validation/`. Re-running
`tests/scripts/sqlite-validation/run_quick.sh <label> <target.php>`
recreates the full matrix for a single target.
