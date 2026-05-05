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
   **Fixed in [#776](https://github.com/reliforp/reli-prof/pull/776)**
   by mirroring the refuse-to-overwrite guard
   `MemoryExportSqliteCommand` already has. The follow-up is purely
   documentation: three places in the codebase still advertise a
   "compare run IDs within the same file" workflow that was never
   actually reachable — see the resolution section below.
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

### Resolution: refuse-on-exists, then prune the now-stale docs

The pragmatic stance is to commit to refuse-on-exists as the *final*
behaviour rather than an interim stopgap, because:

- Two-file `inspector:memory:compare` (rmem/rmem, sqlite/sqlite, or
  even rmem/sqlite mixed) **already works today** and is the path
  the PR description's rmem-as-canonical-intermediate direction
  implicitly favours.
- rmem/rmem compare skips SQLite ingest entirely, so it's strictly
  cheaper than building one shared multi-run DB.
- "Bundle everything in one file for sharing" is solved by `tar.gz`
  on the rmem files; doesn't need schema-level support.
- `runs.run_id` autoincrement and the per-row `run_id` column stay
  useful for future workflows where multi-run-per-DB is built up
  by something other than re-running `inspector:memory` against the
  same path (the obvious one is `inspector:sidecar` collecting many
  dumps into one DB), without any work being needed on the write
  side right now.

In other words [#776](https://github.com/reliforp/reli-prof/pull/776)
is a complete fix — option 1 from the previous draft of this report,
upgraded from "stopgap" to "final".

#### Documentation cleanup that should follow #776

Three places currently advertise a "compare run IDs within the same
file" workflow that has never actually been reachable for the user
(the only way to put two `run_id`s into one file is to ingest twice
into the same path, which #776 now refuses, and which previously
crashed mid-merge). They should be updated so users don't reach for
a feature that doesn't exist:

1. **`src/Command/Inspector/MemoryCompareCommand.php:55`** — the
   `target` argument's help text:

   ```php
   'path to the target snapshot (.rmem or SQLite .db/.sqlite); '
       . 'omit to compare run IDs within the same file'
   ```

   Suggested replacement:

   ```php
   'path to the target snapshot (.rmem or SQLite .db/.sqlite)'
   ```

   i.e. drop the `; omit to ...` clause. The `target` argument
   stays optional (because the `--run-id-baseline` /
   `--run-id-target` options remain available for the rare case
   where two run_ids really did end up in one DB — e.g. via a
   future sidecar workflow), but we stop telling users to omit it
   for a workflow they can't construct.

2. **`docs/memory/memory-report.md:381-383`** — the example block:

   ```markdown
   # Compare run IDs within the same file (SQLite only — multi-run support
   # requires the relational schema)
   ./reli inspector:memory:compare snapshot.db --run-id-baseline 1 --run-id-target 2
   ```

   Suggested action: **delete this example entirely.** The
   surrounding text already shows the canonical two-rmem
   workflow, which is the recommended form.

3. **`docs/memory/memory-report.md:449`** — the usage block's
   parenthetical:

   ```
   target  ... (omit to compare run IDs within the same SQLite file)
   ```

   Suggested replacement: drop the parenthetical, matching the
   change to the source-of-truth help text in (1).

Optionally, the same `docs/memory/memory-report.md` rewrite is a
good place to **promote rmem/rmem compare as the recommended
form** — it sidesteps the SQLite ingest cost entirely and is what
the PR-description-level "rmem is the canonical intermediate"
direction implies. The two-file SQLite compare should still be
documented (some users will be coming from existing `.sqlite3`
captures), but rmem-first reads more naturally given the rest of
the PR.

#### What's *not* recommended as follow-up

Implementing real multi-run-per-DB writes (the round-1 draft of
this report's "option 2": loosen
`SqliteRaw\Reader::loadSqliteMaster` to walk multi-leaf
`sqlite_master`, make parallel-shard ingest stamp the
freshly-allocated `run_id` on every row, add an integration test
exercising the compare-within-one-file flow). That code change is
real work; the user-visible benefit is duplicated by the
already-working two-file compare path, which is also faster
because it skips the second SQLite ingest.

Leaving the `run_id` columns and autoincrement in place keeps the
schema forward-compatible if a real multi-run producer (e.g.
`inspector:sidecar`) wants to use it later, without paying the
implementation cost speculatively now.

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
