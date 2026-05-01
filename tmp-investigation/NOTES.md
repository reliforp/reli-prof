# phpMyAdmin issue 18455 — Reli reproducibility / capability investigation

Issue: <https://github.com/phpmyadmin/phpmyadmin/issues/18455>

> "PHP Fatal error: Allowed memory size of 134217728 bytes exhausted (tried to
>  allocate 26212192 bytes)" at `Session.php:204` (`session_start()`),
>  triggered after importing a large CSV (~50k rows × 83 cols, ~20 MB) and
>  browsing the table.

The 26 MB allocation inside `session_start()` strongly implies that the
session file has grown large and the unserialize-time zval expansion exceeds
the 128 MB limit. This investigation answers: **can Reli pinpoint which
`$_SESSION` key holds the bloat?**

## Reproduction

`seed_session.php` writes a 19 MB session file containing a phpMyAdmin-shaped
payload (`pma_columns`, `pma_recent_rows`, `pma_navi_tree`, …). The two
victim scripts then exercise different capture strategies.

```sh
mkdir -p tmp-investigation/dumps tmp-investigation/sessions
chmod 0700 tmp-investigation/sock
php tmp-investigation/seed_session.php

# sidecar in another shell
RELI_SIDECAR_SOCKET=$PWD/tmp-investigation/sock/sidecar.sock \
  php ./reli inspector:sidecar \
    --socket=$PWD/tmp-investigation/sock/sidecar.sock \
    --output-dir=$PWD/tmp-investigation/dumps \
    --memory-limit=512M \
    --tag scenario=phpmyadmin-issue-18455
```

### Strategy A — `MemoryLimitHandler` at the OOM (`oom_victim.php`)

`memory_limit=64M`, `MemoryLimitHandler::register()` registered before
`session_start()`. When the fatal hits, the shutdown handler fires a sidecar
request.

Result: the dump is captured, but the analysis report shows only **4.9 % of
the heap analyzed** — `memory_get_usage()` is 62 MB but only 3 MB is
reachable from EG. The 59 MB of partial-unserialize zvals were detached from
their HashTable parent during ext-session's RSHUTDOWN before our handler ran,
so they are no longer reachable as a tree even though their bytes are still
sitting in the ZendMM chunks (verifiable: `grep -c 'padding_padding'` over
the .dump returns 535 599 hits).

### Strategy B — manual `snapshot('after-session-start')` with bumped
`memory_limit` (`snapshot_after_start.php`)

`memory_limit=256M`, `SidecarClient::snapshot()` called immediately after
`session_start()` returns. This is the workflow you would deploy for live
diagnosis of issue 18455: leave the limit at production value, but install a
diagnostic mode that bumps it temporarily on a tagged request.

Result: the report points straight at the offender —

```
$_SESSION                 52.77 MB
└ referenced              52.77 MB
  └ ['pma_recent_rows']   47.92 MB

$_SESSION->referenced['pma_recent_rows']  → ArrayElementsContext, 4000 children
$_SESSION->referenced['pma_navi_tree']    →                 4.79 MB / 1000 children
```

`inspector:memory:compare before.rmem after.rmem` confirms the same delta:
ZendString +717 717, ZendArrayTable +6 093, heap +52.78 MB.

That is the answer to the issue: the import path is parking either the full
imported row set or its inline-edit cache in `$_SESSION`, and PHP's session
serializer is reasonably compact on disk (19 MB) but the runtime zvals are
~3× that.

## Two Reli bugs surfaced by this scenario

1. **`MemoryLimitHandler` is shadowed by ext-session's "Failed to decode
   session object" warning.** When `session_start()` OOMs during unserialize,
   ext-session emits an `E_WARNING` during RSHUTDOWN that runs before user
   shutdown functions, so `error_get_last()` no longer reports the original
   `Allowed memory size … exhausted` and `MemoryLimitHandler::isMemoryLimitError`
   bails. Workaround used here:

   ```php
   set_error_handler(static function (int $errno, string $msg): bool {
       return str_contains($msg, 'Failed to decode session object');
   });
   ```

   Returning true suppresses the engine's update of `error_get_last` so the
   OOM stays visible to the shutdown handler. A fix in
   `MemoryLimitHandler::register` itself could record the OOM the first time
   it's seen (e.g. via a tick-driven sentinel or a custom error handler that
   stashes the most recent fatal-class message), independent of whichever
   warning fires last.

2. **OOM-time dumps under-attribute the heap when ext-session has cleaned
   up before the handler runs.** The bytes are physically present in the
   dump (see `padding_padding` grep above), but `inspector:memory:report`
   only sees what is reachable from EG roots. For session-related OOMs the
   "snapshot after session_start with a bumped limit" pattern (Strategy B)
   is the reliable diagnostic; Strategy A is fine for code paths whose
   allocations are still rooted at fatal time.

## Files

- `seed_session.php`          — write a phpMyAdmin-shaped session blob
- `oom_victim.php`            — Strategy A reproduction + workaround
- `snapshot_after_start.php`  — Strategy B reproduction
- `dumps/`, `sessions/`, `sock/` — runtime artefacts (gitignored)
