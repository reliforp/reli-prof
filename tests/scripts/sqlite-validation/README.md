# SQLite output validation harness

Drivers + reproduction targets for cross-checking the `inspector:memory`
SQLite output against real-world PHP memory-issue shapes.

This is the harness behind `docs/internals/pr-773-sqlite-output-validation.md`.

## Files

| Path                          | Purpose                                                                   |
|-------------------------------|---------------------------------------------------------------------------|
| `run.sh`                      | Capture rmem from a live target, then produce sqlite3 (default + experimental), json, and the export-sqlite round-trip. Runs `PRAGMA integrity_check` on every produced sqlite3. |
| `run_quick.sh`                | Capture rmem once, then export-sqlite twice (default vs all experimental flags) and diff per-table row counts. Faster for sweeping multiple targets / sizes. |
| `compare.sh <label>`          | Compare integrity, per-table row counts, and top-N hashes across the three sqlite3 files for one label. |
| `targets/target_*.php`        | Long-running PHP scripts that mimic the heap shape of specific GitHub issues. |

The targets all write their PID to `/tmp/sqlite-validation/target.pid` once
they reach a stable state, then park in `sleep`. The drivers attach with
`reli inspector:memory -p $pid`.

## Usage

```bash
mkdir -p /tmp/sqlite-validation
chmod +x run.sh run_quick.sh compare.sh

./run_quick.sh phalcon_orm_cache    targets/target_phalcon_orm_cache.php    5000
./run_quick.sh valinor_closure_cache targets/target_valinor_closure_cache.php 4000
./run_quick.sh large_buffer         targets/target_large_buffer.php         32
./run_quick.sh querybuilder         targets/target_querybuilder_5gb.php     30000
```

Outputs land in `/tmp/sqlite-validation/out/<label>/`.

## Targets and their GitHub sources

- `target_phalcon_orm_cache.php` — array cache shape from
  [phalcon/cphalcon#16954](https://github.com/phalcon/cphalcon/issues/16954)
  (`Model::find()` with `'cache'` parameter exhausts memory in loops).

- `target_valinor_closure_cache.php` — static reflection cache shape
  from [CuyZ/Valinor#800](https://github.com/CuyZ/Valinor/issues/800)
  (`Reflection::function()` cache grows unboundedly).

- `target_large_buffer.php` — large-string heap shape from issues like
  [glpi-project/glpi document download](https://github.com/glpi-project/glpi)
  buffering entire files into memory, and the
  [symfony StreamedJsonResponse not streaming](https://github.com/symfony/symfony) thread.

- `target_querybuilder_5gb.php` — many-nested-arrays shape from
  [nextcloud/server doctrine/dbal QueryBuilder reaching 5GB](https://github.com/nextcloud/server)
  and [thephpleague/flysystem#1856](https://github.com/thephpleague/flysystem/issues/1856)
  (DirectoryListing iterator memory leak).

The reproductions deliberately use plain PHP (no Phalcon/Valinor/etc.
libraries) — only the **shape** of the heap matters for SQLite-output
validation, and a stand-alone `<?php` file is much easier to spin up
inside a sandbox than a full framework install.
