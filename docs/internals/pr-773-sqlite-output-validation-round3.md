# PR #773 SQLite output — round-3 validation report

Round 3 follows the merge of `IntegerIndexMerger` (#775),
`MemoryCommand` overwrite-guard (#776), and the multi-run-into-same-DB
restoration (#777). All three SQLite-output regressions surfaced by
rounds 1–2 are now fixed and verified end-to-end (see the round-2
report's verification of #777 — multirun_live across all 8 flag
combinations + `inspector:memory:compare` consuming the resulting
multi-run DB).

This round picks up the next item flagged in the original PR
description as deliberately under-covered:

> **MySQL / PostgreSQL serial-ingest parity**: rmem path now fans
> out to all driver types but only sqlite3 has a deep parity test.
> Manual smoke check passed; a parity harness would be a good
> follow-up.

## TL;DR

- **PostgreSQL output is broken**: every `inspector:memory -f
  postgresql` invocation, on every kind of target, fails with a
  `SQLSTATE[23502] Not null violation: 7 ERROR: null value in column
  "id" of relation "context_edges"`.
- The cause is a one-line schema bug: in
  `PdoMemoryOutput::createTables` (`PdoMemoryOutput.php:2104-2114`),
  `context_edges` is declared with the literal
  `id INTEGER PRIMARY KEY`, while its sister tables
  `context_node_locations` (line 2118) and `context_node_attributes`
  (line 2135) correctly use the driver-aware `id {$autoId}` form.
  The literal is the SQLite rowid alias; on PostgreSQL it's just an
  INTEGER NOT NULL with no default, so the subsequent INSERT (which
  omits `id`) fails NOT NULL.
- **MySQL is affected too** by the same schema asymmetry — its
  driver returns `'INTEGER PRIMARY KEY AUTO_INCREMENT'` from
  `autoIncrementPrimaryKey()`, so the literal `INTEGER PRIMARY KEY`
  drops the AUTO_INCREMENT, leaving the same "no default" failure
  shape as postgres. Not empirically verified here (no MySQL in this
  sandbox) but the source code analysis is unambiguous.
- **This is not a regression introduced by #773.** 0.12.0's
  `createTables` has the same `id INTEGER PRIMARY KEY` literal for
  `context_edges` (and the same `{$autoId}` for the other two
  tables), so the postgres path has been broken since at least
  0.12.0. The PR's "manual smoke check passed" evidently exercised
  a target whose heap somehow didn't trigger the failing INSERT —
  but every realistic PHP heap does.
- **Recommended fix**: change line 2106 of `PdoMemoryOutput.php`
  from `id INTEGER PRIMARY KEY,` to `id {$autoId},`. That brings
  `context_edges` in line with the other two tables, with no
  user-visible change for the sqlite path (`SqliteDriver::
  autoIncrementPrimaryKey()` returns the literal `'INTEGER PRIMARY
  KEY'`, so the substituted SQL is identical).

## Reproduction

Default postgres on 127.0.0.1:5432 with a fresh `reli` database, a
trivial PHP target parked in a sleep loop, and the merged tip of the
PR (`39d20f81`):

```bash
php /tmp/sqlite-validation/target_circular_refs.php 4 20 15 &
PID=...

php ./reli inspector:memory -p $PID -f postgresql \
    --db-host=127.0.0.1 --db-user=reli --db-password=reli --db-name=reli
```

Output:

```text
In PdoMemoryOutput.php line 1894:

  SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column "id" of
   relation "context_edges" violates not-null constraint
  DETAIL:  Failing row contains (null, 1, null, 0, interned_strings, 1, strong).
```

The `(null, 1, null, 0, interned_strings, 1, strong)` tuple is
PostgreSQL's "what would have ended up on disk" view of the row.
First column (`id`) is null because the INSERT correctly omits it;
PostgreSQL has no default to substitute, so the implicit NOT NULL
of `INTEGER PRIMARY KEY` fires. The other null
(`parent_node_id` for an interned-strings root edge) is allowed —
column 3 is plain `INTEGER` without NOT NULL.

Captured rmem ingests cleanly into sqlite from the same target
(`integrity_check = ok`, 51,564 context_edges rows).

## Schema asymmetry — pinpointed

`PdoMemoryOutput::createTables`
(`src/Inspector/Output/MemoryOutput/PdoMemoryOutput.php:2046`):

```php
//   ...
//   runs.run_id      uses {$autoId}    (line 2057)
//   summary.*        no id column

$db->exec("
    CREATE TABLE IF NOT EXISTS context_nodes (
        ...                                       /* no id column */
    )
");

// `id INTEGER PRIMARY KEY` is the rowid alias on a regular ROWID
// table — no extra storage cost, but it gives report-time chunked
// loaders a stable, indexable column they can paginate by ...
$db->exec("
    CREATE TABLE IF NOT EXISTS context_edges (
        id INTEGER PRIMARY KEY,                   /* <-- literal, SQLite-only */
        run_id INTEGER NOT NULL,
        ...
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS context_node_locations (
        id {$autoId},                             /* <-- driver-aware (correct) */
        run_id INTEGER NOT NULL,
        ...
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS context_node_attributes (
        id {$autoId},                             /* <-- driver-aware (correct) */
        run_id INTEGER NOT NULL,
        ...
    )
");
```

The comment block above the `context_edges` definition explains the
intent ("rowid alias on a regular ROWID table"), which is fine for
SQLite but doesn't translate to PostgreSQL/MySQL. The
`autoIncrementPrimaryKey()` substitution exists exactly to bridge
this — and for two of the three tables, the substitution was
remembered.

| Driver | `autoIncrementPrimaryKey()` returns | Effect of the literal `INTEGER PRIMARY KEY` |
|--------|-------------------------------------|-----------------------------------------------|
| SQLite | `'INTEGER PRIMARY KEY'`             | identical to literal — no change              |
| MySQL  | `'INTEGER PRIMARY KEY AUTO_INCREMENT'` | drops AUTO_INCREMENT; INSERTs without `id` fail with "Field 'id' doesn't have a default value" |
| PostgreSQL | `'SERIAL PRIMARY KEY'`          | drops the SERIAL sequence default; INSERTs without `id` fail with NOT NULL violation (this report) |

## Confirmation that this isn't a #773 regression

`git show 0.12.0:src/Inspector/Output/MemoryOutput/PdoMemoryOutput.php`
shows the same asymmetry — `context_edges` has the literal,
`context_node_locations` and `context_node_attributes` use
`{$autoId}`. So the postgres path was already broken in 0.12.0;
nobody noticed because the postgres CLI options weren't exercised
in any deployed workflow. The PR description's flag was prescient.

## Suggested fix

```diff
--- a/src/Inspector/Output/MemoryOutput/PdoMemoryOutput.php
+++ b/src/Inspector/Output/MemoryOutput/PdoMemoryOutput.php
@@ -2103,7 +2103,7 @@ private function createTables(\PDO $db): void
         // and post-filtering rowid per chunk.
         $db->exec("
             CREATE TABLE IF NOT EXISTS context_edges (
-                id INTEGER PRIMARY KEY,
+                id {$autoId},
                 run_id INTEGER NOT NULL,
                 parent_node_id INTEGER,
                 child_node_id INTEGER NOT NULL,
```

That's it. The sqlite path's behaviour is unchanged because
`SqliteDriver::autoIncrementPrimaryKey()` returns exactly the
literal `'INTEGER PRIMARY KEY'` — which is the rowid alias the
existing comment describes. The pagination optimisation the comment
calls out keeps working on sqlite. Postgres and mysql get a working
auto-increment.

The shard-side schema (`PdoMemoryOutput.php:1482-...`, used only by
the SQLite parallel-shard ingest) can keep its literal — shards are
always SQLite, so the literal is correct there.

## Test plan suggestion (parity harness)

The PR description explicitly invited a parity harness. A minimal
one would be:

1. Capture an rmem from a representative target.
2. Drive `inspector:memory -f sqlite3 -o a.sqlite3` and
   `inspector:memory -f postgresql --db-* ...` (and `-f mysql ...`)
   against the same target/process.
3. For each shared user-facing table (`runs`, `summary`,
   `context_nodes`, `context_edges`, `context_node_locations`,
   `context_node_attributes`, `class_objects_summary`,
   `location_types_summary`), assert:
   - row counts match across drivers
   - `sha256(SELECT * FROM table ORDER BY 1,2,3,4,5)` matches across
     drivers (modulo type coercion — postgres may render BIGINT and
     INTEGER differently than sqlite; normalising the dump format
     before hashing is enough)

`tests/scripts/sqlite-validation/test_postgres.sh` (added in this
report) is a starting point for this harness; rounding it out into a
PHPUnit-driven `integration` group test on top of the dockerised
mysql/postgres images already used by the project is the natural
follow-up.

## Test artefacts

- `tests/scripts/sqlite-validation/test_postgres.sh` — drives
  postgres ingest, cross-checks against sqlite ingest of the same
  rmem, and tests multi-run-into-same-DB on postgres. Currently
  exits with the empirical failure documented above; will pass
  after the one-line schema fix lands.
