# Memory Dump (`inspector:memory:dump`)

Captures a binary snapshot of a PHP process's memory for offline
analysis. The dump file (`.rdump` format) can later be analyzed with
`inspector:memory:analyze` on a different machine or at a different time.

## Quick start

```bash
# Dump a running PHP process
sudo php ./reli inspector:memory:dump --pid=<pid> --output=snapshot.rdump

# Analyze the dump (.rmem is the fastest format; every analyser reads it)
php ./reli inspector:memory:analyze snapshot.rdump -f rmem -o snapshot.rmem

# Browse or report on the graph
php ./reli rmem:explore snapshot.rmem
php ./reli inspector:memory:report snapshot.rmem
```

`-f sqlite3` is also supported by `inspector:memory:analyze` / `inspector:memory:report` /
`inspector:memory:compare` if you want to query with SQL tools, but `rmem:explore`,
`rmem:query`, `rmem:serve`, and `rmem:mcp` read `.rmem` only.

## How it works

The dump captures the following memory regions from the target process
via `process_vm_readv`:

- ZendMM chunks and huge allocations (PHP-managed heap)
- Opcache shared memory (interned strings, cached scripts)
- Compiler arenas
- VM stacks
- PHP binary's writable segments (EG, CG, engine globals)
- `EG(objects_store).object_buckets`
- `CG(map_ptr_base)` table (MAP_PTR indirect pointers)
- glibc `[heap]` and anonymous mmap regions (by default)

Non-resident pages are skipped using `/proc/pid/pagemap`, so large
mmap reservations (e.g. 128 MB opcache SHM) only contribute their
actually-used pages to the dump.

The target process is stopped (`SIGSTOP`) during the dump to ensure a
consistent snapshot. Use `--no-stop-process` to skip this, but be aware
that the dump may contain inconsistent state.

## Options

```
--pid, -p           Target process PID (or pass a `cmd` after `--` to spawn one)
--output, -o        Output file path
--stop-process      Stop the target during dump (default: on)
--no-stop-process   Don't stop the target
--include-binary    Include read-only binary segments (self-contained dump)
--exclude-heap      Exclude [heap] and anonymous mmap regions (see below)
```

`./reli inspector:memory:dump --help` is the source of truth for the
full flag list and defaults.

## `--exclude-heap`

By default the dump includes everything, including the glibc `[heap]`
and anonymous writable mmap regions. This gives complete coverage for
any analysis.

`--exclude-heap` skips these regions. Use it when:

- **Recurring monitoring** (`i:watch`): smaller dumps mean less I/O.
- **Large RSS from C extensions**: libxml2, sqlite3, ImageMagick etc.
  allocate via system `malloc` into `[heap]`. If you only care about
  PHP-managed memory (`memory_get_usage()`), these bytes are noise.
  A process with 500 MB RSS but 10 MB `memory_get_usage()` produces
  a ~6 MB dump instead of ~170 MB.
- **Disk/network constrained environments**.

In `--exclude-heap` mode, a metadata peek walker reads engine-level
metadata (class/function definitions, constants, interned strings)
from the live process and injects it into the dump, so the analyzer
can still resolve class names and function signatures.

## Analyzing the dump

```bash
# To .rmem (recommended — fastest, consumed by every analyser)
php ./reli inspector:memory:analyze snapshot.rdump \
    -f rmem -o snapshot.rmem

# To SQLite (for SQL tooling; inspector:memory:report / inspector:memory:compare accept either)
php ./reli inspector:memory:analyze snapshot.rdump \
    -f sqlite3 -o snapshot.sqlite

# To JSON
php ./reli inspector:memory:analyze snapshot.rdump \
    -f json -o snapshot.json

# With dependency root for binary fallback (when --include-binary was not used)
php ./reli inspector:memory:analyze snapshot.rdump \
    -f rmem -o snapshot.rmem \
    -r /path/to/target/root
```

## Comparison with `gcore`

| | `inspector:memory:dump` | `gcore` |
|---|---|---|
| Output format | `.rdump` (analyzer-ready) | ELF core (needs post-processing) |
| Size | Pagemap-filtered (resident pages only) | All writable VMAs |
| Speed | Comparable or faster | Comparable |
| PHP awareness | Captures exactly what the analyzer needs | Captures everything blindly |
| `--exclude-heap` | Drops C-extension data, keeps PHP metadata | Not available |

## See also

- [memory-profiler.md](memory-profiler.md) — online analysis (`inspector:memory`)
- [memory-profiler-database.md](memory-profiler-database.md) — SQL querying of analysis results
- [memory-report.md](memory-report.md) — automated analysis reports
- [watch-command.md](../monitoring/watch-command.md) — condition-triggered dumps
- [sidecar.md](../monitoring/sidecar.md) — daemon mode for on-demand dumps
- [internals/memory-dump-inspect.md](../internals/memory-dump-inspect.md) — `inspector:memory:dump:inspect`, a developer tool that prints the `.rdump` header / memory map / region list (output schema is unstable, intended for debugging the capture pipeline itself)
