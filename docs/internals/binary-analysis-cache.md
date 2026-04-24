# Binary analysis cache

reli caches expensive binary-analysis results to disk so that
repeated profiling of the same PHP binary is fast. This note covers
*what* gets cached, *where*, *how cache keys are computed*, and the
Docker-overlayfs edge case that shaped the current keying scheme.
For the user-facing one-liner (clear / bypass), see
[the "Clear / bypass the cache" row in the docs index](../README.md#advanced--tooling)
— the short version is `./reli cache:clear` to clear and `--no-cache`
on any inspector command to bypass.

## What is cached

The first time reli attaches to a PHP interpreter binary, it
performs several one-shot analyses that are deterministic functions
of that binary:

- **ELF symbol resolution** — locating symbols in `.dynsym` /
  `.symtab` / `.debug_*` sections.
- **TLS brute-force offsets** — for ZTS targets, finding
  `_tsrm_ls_cache` / engine globals in the TLS block when the
  symbol has been stripped.
- **PHP version detection** — deriving the target's PHP version
  (e.g. `v84`) from binary contents.
- Other per-binary resolution results used by the process readers.

All of these depend only on the binary's bytes, so caching them and
keying by a stable binary fingerprint is safe.

## Performance impact

The win is largest for ZTS targets, where the TLS brute-force scan
dominates initialisation:

| Target | Cold (first run) | Warm (cached) |
|---|---|---|
| Typical ZTS binary | ~8 s | ~5 ms |
| Typical NTS binary | fast anyway | slightly faster |

For short-lived processes (CI, one-shot scripts) the warm path
matters; for long-running daemons the cost is amortised either way.

## Where the cache lives

Cache files sit under `~/.cache/reli/binary-analysis/`, following
the [XDG Base Directory specification](https://specifications.freedesktop.org/basedir-spec/latest/)
(i.e. `$XDG_CACHE_HOME/reli/binary-analysis/` when `XDG_CACHE_HOME`
is set).

The directory is not shared between users or between containers
unless you explicitly bind-mount it.

## Cache key: `(device_id, inode, ELF-header-bytes)`

A naive cache key using only the filesystem's `(device, inode)` pair
is correct for most bare-metal setups but **breaks on Docker's
overlayfs**: multiple images can have different binaries with the
same `(device, inode)` pair (e.g. `php:8.3` vs `php:8.3-zts`), so a
cache entry keyed only by `(device, inode)` could be served against
the wrong binary.

To make the key uniquely identify the binary even across overlayfs
images, reli includes the **leading bytes of the ELF header** in
the key. Since two different PHP builds differ in their ELF header
content (at least in e_type/e_entry/section count or later fields),
any meaningful binary change produces a different cache entry.

This means:

- Same binary, different mount → shared cache entry ✓
- Different binary, same `(device, inode)` → separate cache entries ✓
- Binary modified in place → new key → cold path, not a stale hit ✓

## User controls

- **Clear the cache**: `./reli cache:clear`. Safe at any time —
  worst case is one cold run per newly-seen binary.
- **Bypass for a single run**: pass `--no-cache` to any inspector
  command, e.g. `./reli inspector:trace --no-cache -p <pid>`.
- **Disable entirely**: there is no global kill switch. If you
  always want cold runs (CI isolation, debugging cache logic, etc.)
  add `--no-cache` in the wrapper, or run `cache:clear` in your
  pre-run hook.
