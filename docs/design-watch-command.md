# Design Notes: `inspector:watch` Command

> **This is a historical design document.** For current usage, see [docs/watch-command.md](watch-command.md). For implementation details, see [docs/internals/](internals/).

## Original Design Goals

- Condition-based passive monitoring (only act when triggers fire)
- Low overhead in production (adaptive polling per trigger tier)
- Daemon mode for multi-process monitoring
- Configurable rate limiting to prevent disk/performance issues

## Key Design Decisions Made During Implementation

### Removed: `--on-exception` trigger
Sampling-based polling cannot reliably catch exceptions in flight (`EG->exception` is set for microseconds between throw and catch). Removed to avoid giving users false confidence. Alternative: use `--watch-var` to monitor caught exceptions in catch blocks.

### Removed: `--watch-global-array-size`
Unified into `--watch-var` with `count_gt`/`count_lt`/`count_eq` operators (e.g., `global::$cache:count_gt:10000`).

### `--watch-var` syntax evolved
- All scopes require `$` prefix on variable names
- `local::` and `func_static::` require function specification (`func()$var`)
- Top-level script scope uses `<main>` (not `main`) to avoid collision with user-defined functions
- Nested path access: `[key]` for arrays, `->prop` for objects

### `--oneshot` vs long-running daemon
`--oneshot=<N>` exits after N triggers. For daemons restarted by supervisors, use restart-resilient mechanisms (`--max-dump-size`, `--max-triggers-per-hour`, `--cooldown`).

### `--max-triggers` scope
Global single counter across all daemon workers. Workers handle per-process cooldown/backoff; controller handles the global limit.

### Simultaneous trigger merging
When multiple triggers fire in the same poll, actions execute once with a merged event name (e.g., `memory-limit+memory-peak-watch`).

### MemoryDumper extraction
Core dump logic extracted from `MemoryDumpCommand` into `MemoryDumper` service, shared by CLI command and watch actions.

### memory-dump action
Uses fast binary dump (same format as `inspector:memory:dump`), not the heavier full-analysis path (`MemoryLocationsCollector` + `RegionAnalyzer`).

### exec action
Fire-and-forget (non-blocking). Context passed via environment variables only (no template substitution in command string).

### FunctionDetectionTrigger
Exact match only. No partial/substring matching.

## Existing Bugs Fixed

- `ZendPropertyInfo::isStatic()`: wrong bit position (ZEND_ACC_STATIC is 0x01 on PHP 7.0-7.3, 0x10 on 7.4+)
- `ZendClassEntry::getStaticPropertyIterator()`: MAP_PTR double pointer not deref'd on PHP 7.4-8.1
- `ZendClassEntry` field name: PHP 7.0 uses `static_members_table` (not `__ptr`)
- `ZendExecutorGlobals::exception`: missing from `getFieldEager()` (ZTS builds)

## Performance (Measured)

| Configuration | Median Latency |
|---------------|---------------|
| Tier 1 (memory triggers) | ~680μs |
| Tier 1 + Tier 2 (+function/depth) | ~750μs |
| Tier 1 + 2 + 3 (+variable watch) | ~2,170μs |

Bottleneck was `chunk_finder->findAddress()` scanning `/proc/pid/maps` every poll. Fixed by caching chunk address per PID (60% improvement).

## References

- User documentation: [docs/watch-command.md](watch-command.md)
- FFI CData lifetime: [docs/internals/ffi-cdata-lifetime.md](internals/ffi-cdata-lifetime.md)
- Architecture: [docs/internals/watch-command-architecture.md](internals/watch-command-architecture.md)
- Variable reading: [docs/internals/php-variable-reading.md](internals/php-variable-reading.md)
- Container deployment: [docs/watch-command.md#container-deployment](watch-command.md#container-deployment)
