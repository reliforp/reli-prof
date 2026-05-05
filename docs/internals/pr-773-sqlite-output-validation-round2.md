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
3. **One new regression found**: writing a second `inspector:memory`
   capture into an already-populated reli SQLite database fails with
   `RuntimeException: sqlite_master root is not a leaf (type 0x05);
   not yet supported` from `SqliteRaw\Reader::loadSqliteMaster`.
   Affects every flag combination. Reproduces with a 100-element-array
   target.
   **0.12.0 supports this workflow** — repeated
   `inspector:memory -f sqlite3 -o existing.sqlite3` against a
   long-running target produces a 2-run DB
   (`runs` table has 2 rows, `context_edges` has both `run_id=1` and
   `run_id=2` populated, `integrity_check = ok`). So this is a real
   feature regression introduced by #773's rmem-canonical migration,
   not just an implementation-detail leak. See the verification at
   the bottom of this document.
   **[#776](https://github.com/reliforp/reli-prof/pull/776) stops the
   crash** by mirroring `MemoryExportSqliteCommand`'s
   refuse-to-overwrite guard, but only as a *safe interim* — it
   doesn't restore the released-and-removed feature. The follow-up
   options are listed in the resolution section below.
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

### Resolution: #776 stops the crash, but doesn't replace the lost feature

`inspector:memory -f sqlite3 -o existing.sqlite3` is a
**released-and-working capability in 0.12.0**: repeating it against
a long-running target produces a 2-run DB that
`inspector:memory:compare snapshot.db --run-id-baseline 1 --run-id-target 2`
(also released in 0.12.0) consumes correctly. The combined feature
is documented:

- `MemoryCompareCommand.php:55` — `target` arg help text:
  `path to the target snapshot (.rmem or SQLite .db/.sqlite); omit to compare run IDs within the same file`
- `docs/memory/memory-report.md:381-383` — worked example showing
  exactly this flow.
- `docs/memory/memory-report.md:449` — usage block's
  `(omit to compare run IDs within the same SQLite file)` parenthetical.

The `runs.run_id` autoincrement, the `run_id` column on every per-run
table, the `CREATE TABLE IF NOT EXISTS`, and the
`MemoryCompareCommand`'s `--run-id-baseline` / `--run-id-target`
options are all 0.12.0-shipped infrastructure that already work as a
unit on a real multi-run DB. The only thing missing in 0.13.x is
that the new rmem-canonical `SqliteRaw\Reader::loadSqliteMaster`
can't *parse* a populated reli DB during the second ingest — its
docblock at `Reader.php:166-172` explicitly flags this as a known
TODO ("if the schema ever grows past one page, this will need to
walk like collectTableLeaves does").

#776's refuse-to-overwrite guard fixes the *crash* (which is what was
critical), but the user-visible behaviour after #776 is "0.12.0
feature is gone, error message instead". For 0.13.x to ship without
a feature regression vs 0.12.0, one of A/B/C below has to follow.

#### A. (Preferred) Restore the feature

Implement the missing piece: teach
`SqliteRaw\Reader::loadSqliteMaster` to walk multi-leaf
`sqlite_master` (the docblock TODO at `Reader.php:166-172`), and
add an integration test that captures twice into the same SQLite
file and asserts:

- `PRAGMA integrity_check = ok` on the final DB
- `runs` table has 2 rows
- per-run row counts in `context_edges` etc. equal the single-run
  baselines
- `inspector:memory:compare path.sqlite3 --run-id-baseline=1 --run-id-target=2`
  works end-to-end

The shard ingest already stamps the freshly-allocated `run_id` on
every row (`PdoMemoryOutput.php:330-338`'s comment block describes
this design intent explicitly), so the only code change needed is
in the reader. Once that lands, #776's guard can be dropped (or kept
behind a `--no-append` opt-out for users who want export-sqlite-style
overwrite refusal).

This is the only option that **keeps 0.12.0's docs accurate as-is,
keeps `inspector:memory:compare`'s `--run-id-*` options meaningful,
and avoids a 0.13.x release-note line saying "we broke this 0.12.0
workflow on purpose"**.

#### B. Accept the regression, document it

Ship 0.13.x with #776's guard as the final behaviour and add a
**Backwards-incompatible change** entry to the release notes
explicitly:

> `inspector:memory -f sqlite3 -o foo.sqlite3` no longer appends a
> second run to an existing file. To compare two snapshots, capture
> them as separate files (`.rmem` is recommended for speed) and
> pass both paths to `inspector:memory:compare`. The
> `--run-id-baseline` / `--run-id-target` options to
> `inspector:memory:compare` remain available for DBs assembled by
> other producers (e.g. `inspector:sidecar`) but no first-party
> producer creates them in 0.13.x.

Plus the doc updates that follow from this:

1. `MemoryCompareCommand.php:55`: drop `; omit to compare run IDs within the same file`.
2. `docs/memory/memory-report.md:381-383`: delete the
   `--run-id-baseline 1 --run-id-target 2` example.
3. `docs/memory/memory-report.md:449`: drop the
   `(omit to compare run IDs within the same SQLite file)` parenthetical.

This is honest about the change but loses a working feature for the
sake of avoiding the Reader change.

#### C. Add an `--append` opt-in flag

Keep #776's guard as the default (safe, mirrors `export-sqlite`), but
add `inspector:memory --append -f sqlite3 -o existing.sqlite3` that
takes the multi-leaf-aware path. Default is the new safe behaviour;
0.12.0 users get an explicit one-flag migration. Effectively A's
implementation work, with the safer default of B; the cost is one
extra CLI option to document.

#### Recommendation

A. The schema, the CLI, and the docs are all *already* aligned on
multi-run-per-DB; only the reader is in the way. Implementing
multi-leaf `sqlite_master` parse is a contained, well-understood
change (the SQLite file format spec is small and the existing
`collectTableLeaves` is the template). Doing it in this PR cycle —
or in an immediate follow-up before 0.13.0 ships — preserves
feature parity with 0.12.0 and saves the 0.13.x release notes from
having to advertise a feature deletion.

If the implementation cost is judged too high right now, **C is the
acceptable backup** — it preserves the option for 0.12.0 users at
trivial doc cost, and the underlying work to lift the
single-leaf assumption can be shipped any time later without
touching anything else.

**B should only be picked if multi-run-per-DB is genuinely deemed
the wrong design** — e.g. the team decides rmem two-file is the
strategic direction and the 0.12.0 multi-run-write path was a
historical accident. That's a defensible position, but it should
be stated explicitly rather than landed by accident through #776
silently swallowing a feature.

### 0.12.0 baseline verification (proof that this is a regression)

Run against the released 0.12.0 tag in this repo with a trivial
target (`['hello' => 'world', 'arr' => range(1, 100)]`) parked in a
sleep loop:

```text
=== 0.12.0 first capture ===
size=7659520 integrity=ok
runs row(s):  1|2026-05-05T15:45:07Z
context_edges run_id=1: 35447

=== 0.12.0 SECOND capture into same file ===
size=16265216 integrity=ok
runs rows:    1|2026-05-05T15:45:07Z
              2|2026-05-05T15:45:08Z
context_edges by run_id:
              1|35447
              2|35447
```

Both runs land cleanly, `integrity_check = ok`, both
`run_id` values are populated with the per-run row counts of a
single capture, file roughly doubles in size — exactly the
"compare run IDs within the same file" workflow the docs describe.

`inspector:memory:compare snapshot.db --run-id-baseline 1 --run-id-target 2`
against this DB also works on 0.12.0 (compares the two runs and
reports zero deltas, since both captures were of the same parked
target). So the entire pipeline — produce → store → compare — was
shipped and functional in 0.12.0; the regression is purely on the
write side and purely in 0.13.x's new rmem-canonical ingest.

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
