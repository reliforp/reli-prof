# PR #773 SQLite output — round-2 validation report

Follow-up to `pr-773-sqlite-output-validation.md` after the
`IntegerIndexMerger` fix from
[claude/fix-sqlite-output-bug-VC2ij](https://github.com/reliforp/reli-prof/pull/775)
landed on the PR branch (`54506f3`). Re-runs all original repros against
the fixed branch, plus five new heap shapes targeting code paths that
weren't exercised by the round-1 set, plus a multi-run-into-same-DB
test.

## TL;DR

1. **The original IntegerIndexMerger fix holds.** Every flag
   combination on every original repro produces integrity-clean,
   content-identical output (sweep run via
   `tests/scripts/sqlite-validation/run_full_matrix.sh`).
2. **Five new heap shapes — circular refs, WeakMap, SplObjectStorage,
   closures with captured `$this` / static / first-class-callable, and
   suspended generators — also produce integrity-clean,
   content-identical output across all 8 flag combinations.**
3. **One new defect found**: writing a second `inspector:memory`
   capture into an already-populated reli SQLite database fails with
   `RuntimeException: sqlite_master root is not a leaf (type 0x05);
   not yet supported` from `SqliteRaw\Reader::loadSqliteMaster`.
   Affects every flag combination. Reproduces with a 100-element-array
   target.
4. As a related-but-not-defect observation, `inspector:memory -f json`
   silently truncates an existing JSON file at the same path, while
   `inspector:memory:export-sqlite` explicitly refuses to overwrite.
   The behaviour for `inspector:memory -f sqlite3` falls between the
   two — it tries to ingest and crashes — which is the worst of the
   three from a UX standpoint.

## Round-2 heap shapes

Every shape was replayed through every combination of the three
experimental flags
(`RELI_SHARED_MMAP_INGEST`, `RELI_PARALLEL_INDEX`,
`RELI_FORMAT_DIRECT_INDEX`) — eight variants per shape. For each
variant we collected file size, `PRAGMA integrity_check`, per-table
row counts, and per-table content hashes (sorted full-row dump,
sha256). Every cell of every matrix matched the default-flags variant
exactly.

Source GitHub issues that motivated each target:

| Target                       | GitHub source                                                                                  |
|------------------------------|------------------------------------------------------------------------------------------------|
| `target_circular_refs.php`   | [PHPOffice/PHPExcel#583](https://github.com/PHPOffice/PHPExcel/issues/583), [BitOne/php-meminfo#83](https://github.com/BitOne/php-meminfo/issues/83) |
| `target_weakmap_amphp.php`   | [amphp/file#88](https://github.com/amphp/file/issues/88)                                       |
| `target_splobjectstorage.php`| Search hits across [zumba/json-serializer#76](https://github.com/zumba/json-serializer/issues/76), Doctrine UnitOfWork pattern, etc. |
| `target_closure_capture.php` | Generic `use ($heavy)` leak shape from Symfony EventDispatcher / Laravel queue / Reactor handler bug families |
| `target_generator_state.php` | React/AmpHP/Revolt long-lived generator pattern — suspended frames retain captured locals       |

Per-shape size profile (rmem → default sqlite3):

| Shape                  | rmem      | sqlite3   | context_edges | context_nodes |
|------------------------|-----------|-----------|---------------|---------------|
| `circular_refs`        |  5.5 MiB  |  13 MiB   |   83,230      |   49,343      |
| `weakmap_amphp`        | 21 MiB    |  54 MiB   |  250,328      |  184,791      |
| `splobjectstorage`     |  5.8 MiB  |  14 MiB   |   73,722      |   54,584      |
| `closure_capture`      | 26 MiB    |  72 MiB   |  353,405      |  233,846      |
| `generator_state`      |  7.7 MiB  |  18 MiB   |   90,809      |   74,772      |

All ≥ the ~7K-row threshold that triggered the round-1
`IntegerIndexMerger` boundary bug, so each shape is a meaningful
post-fix regression test for that specific path. Every shape is
clean.

## New defect — second `inspector:memory` capture into an existing DB fails

### Minimal repro

```bash
cat > /tmp/min_target.php <<'EOF'
<?php
$x = ['hello' => 'world', 'arr' => range(1, 100)];
file_put_contents("/tmp/min_pid", getmypid());
fwrite(STDERR, "READY\n");
while (true) sleep(60);
EOF

php /tmp/min_target.php >/dev/null 2>&1 &
sleep 0.5
PID=$(cat /tmp/min_pid)
rm -f /tmp/min.sqlite3

# First capture: succeeds.
php ./reli inspector:memory -p "$PID" -f sqlite3 -o /tmp/min.sqlite3
# → /tmp/min.sqlite3 created, 7.6 MiB, integrity_check ok,
#   sqlite_master has 24 entries (8 tables, 14 indexes, 2 views).

# Second capture into the same file: fails.
php ./reli inspector:memory -p "$PID" -f sqlite3 -o /tmp/min.sqlite3

# In Reader.php line 179:
#   sqlite_master root is not a leaf (type 0x05); not yet supported
```

The error originates in `SqliteRaw\Reader::loadSqliteMaster`
(`Reader.php:179`). The function's own docblock explicitly calls out
the limitation:

```
/**
 * Parse sqlite_master (rooted at page 1, b-tree starts at offset
 * 100). Returns a name => row map. Only single-leaf sqlite_master
 * is supported here; if the schema ever grows past one page,
 * this will need to walk like collectTableLeaves does. Our
 * shards have ≤ 4 tables so a single leaf is always enough.
 */
```

i.e. the assumption is that the SqliteRaw reader is only ever used
against shard files the worker built from scratch, not against the
target main DB after a capture has already populated it.

### Bug surface

Affects every flag combination (`default`, `sm`, `pi`, `fd`, `sm_pi`,
`sm_fd`, `pi_fd`, `all`). All five fail identically on the second
capture in
`tests/scripts/sqlite-validation/test_multirun_live.sh`. Also
reproduces with `--no-cache`, so it isn't a binary-analysis-cache
side-effect.

The schema design supports multi-run (`runs.run_id` is auto-increment;
every per-run table carries a `run_id` column; `createTables` uses
`CREATE TABLE IF NOT EXISTS`), so the failure is unexpected from a
user perspective. Specifically not a design intent of "single run per
file" — that's enforced at the CLI level only for `export-sqlite`
(via the `Output already exists: … (refusing to overwrite)` guard at
`MemoryExportSqliteCommand.php:89`), not for `inspector:memory`.

### What does work

Two adjacent scenarios behave correctly:

- **Pre-existing unrelated SQLite file**: writing
  `inspector:memory -f sqlite3 -o existing.sqlite3` into a SQLite
  file that contains some unrelated tiny schema (e.g. one user
  table `foo` with one row) **succeeds**. The new reli tables get
  created alongside the original; the original `foo` table and its
  row are preserved; `integrity_check = ok`. So the bug is
  specifically about ingesting into a DB whose `sqlite_master`
  already contains *reli's* full schema.

- **Empty (zero-byte) file**: `touch out.sqlite3 ; reli ... -o out.sqlite3`
  works — reli treats it as a fresh DB.

So the failure is really "main already has all 24 reli sqlite_master
entries". Even though 24 rows nominally fit in one leaf page, the
shard ingest path apparently grows past that during merge (probably
adding subset/temp/REINDEX-side rows), at which point the SqliteRaw
reader trips on the now-multi-page sqlite_master.

### UX inconsistency across formats

| Command                                                | Behaviour with existing output file                         |
|--------------------------------------------------------|-------------------------------------------------------------|
| `inspector:memory:export-sqlite a.rmem b.sqlite3`      | Refuses to overwrite (`Output already exists: …`)           |
| `inspector:memory -f json -o a.json`                   | **Silently overwrites** the file in place                   |
| `inspector:memory -f sqlite3 -o a.sqlite3`             | **Tries to ingest, crashes with RuntimeException** (this PR) |

The middle row (silent JSON overwrite) is a long-standing UX
asymmetry rather than a regression introduced by this PR; flagging it
mostly because the third row's failure mode looks like a low-level
"reli is broken" bug to a user who was just expecting the same
silent-overwrite behaviour as JSON.

### Suggested resolution

Two reasonable options, in increasing order of effort:

1. **Make `inspector:memory -f sqlite3` consistent with
   `export-sqlite`**: refuse to overwrite an existing output file
   with a clear message, instead of failing partway through with a
   `SqliteRaw\Reader` internal error. This matches the
   already-deployed guard in `MemoryExportSqliteCommand.php:89`.
2. **Properly support multi-run-into-same-DB**: the schema already
   does (`runs.run_id` autoincrement; every per-run table has a
   `run_id` column). Loosening
   `SqliteRaw\Reader::loadSqliteMaster` to walk multi-leaf
   `sqlite_master` (the docblock at `Reader.php:166-172` already
   contemplates this) plus making sure the parallel-shard ingest
   stamps every row with the freshly-allocated `run_id` would
   unlock dumping multiple snapshots of the same long-running
   target into one DB and then comparing them via
   `inspector:memory:compare` — which is a real workflow the new
   `compare` command suggests is intended.

Either option is non-blocking for the PR as it stands, since the
single-capture-per-file workflow (which is what the PR description's
benchmarks measure and what every existing test exercises) keeps
working correctly in every flag combination. But the current failure
mode for the multi-run case is user-hostile and worth tightening
before release.

## Test artefacts

- `tests/scripts/sqlite-validation/run_full_matrix.sh` — drives one
  target through all 8 flag combinations, emits the integrity / row
  count / content-hash matrix.
- `tests/scripts/sqlite-validation/test_multirun_live.sh` — the
  multi-run-into-same-DB test that surfaced the new bug.
- `tests/scripts/sqlite-validation/targets/target_*.php` — the round-2
  reproductions.

Re-running everything for a future regression check is just:

```bash
for t in circular_refs weakmap_amphp splobjectstorage \
         closure_capture generator_state \
         phalcon_orm_cache valinor_closure_cache \
         large_buffer querybuilder; do
    tests/scripts/sqlite-validation/run_full_matrix.sh "$t" \
        tests/scripts/sqlite-validation/targets/target_${t}.php
done
tests/scripts/sqlite-validation/test_multirun_live.sh
```
