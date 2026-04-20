# Core dump inspection (`inspector:coredump`)

`inspector:coredump` runs the same memory analysis as `inspector:memory`
against an **ELF core file** instead of a live process. It's the
post-mortem counterpart to the online analyzer, and a useful fallback
when live dumping cannot be used.

## When to use

- **The process has already died**: you have a core file (from the
  kernel `core_pattern`, `systemd-coredump`, Docker runtime dump,
  cloud platform artifact, CI sidecar, etc.) but no live PID to attach
  to.
- **Live dump fails**: if `inspector:memory` / `inspector:memory:dump`
  cannot attach (ptrace denied, sandboxed container, unusual glibc /
  musl layout, ZTS TLS scan failing on an exotic target), taking a
  core dump with a tool you already have (`gcore`, `kill -SIGSEGV`,
  kernel-level dump) and feeding it to `inspector:coredump` is a
  reliable workaround — it reads ELF notes and memory pages out of
  the core file instead of going through `/proc/<pid>/mem`.
- **Reproducing an incident from an archived dump**: core files are
  portable. A dump taken in production can be analyzed later on a
  dev machine, as long as the matching PHP binary and shared libraries
  can be provided (see `--dependency-root`).

For live analysis of a still-running process, use
[`inspector:memory`](memory-profiler.md) or
[`inspector:memory:dump`](memory-dump.md). For reuse of an analyzer
output across tools, use the SQLite pipeline described in
[memory-report.md](memory-report.md) and [rmem-explore-and-serve.md](rmem-explore-and-serve.md).

## Quick start

```bash
# Take a non-destructive core dump of a live process
$ sudo gcore -o /tmp/myapp 12345
# → /tmp/myapp.12345

# Run the memory analyzer against the core file
$ php ./reli inspector:coredump /tmp/myapp.12345 --pid 12345 \
    -f sqlite3 -o snapshot.db

# Feed the output into the normal analysis pipeline
$ php ./reli inspector:memory:report snapshot.db
$ php ./reli inspector:rmem:explore snapshot.db
```

The output of `inspector:coredump` uses the same
[`MemoryProfilerSettings`](../src/Inspector/Settings/MemoryProfilerSettings/MemoryProfilerSettingsFromConsoleInput.php)
as `inspector:memory`, so every output format (`json`, `sqlite3`,
`binary`, `mysql`, `postgresql`, `report`, `report-json`) and every
downstream tool (`memory:report`, `memory:compare`, `rmem:explore`,
`rmem:serve`) works the same way.

## Arguments and options

### Required

| Argument / option | Description |
|---|---|
| `<core-file>` | Path to the ELF core file to read. |
| `--pid, -p` | The PID the target process had **at the time of the dump**. This is used to resolve the binary / shared-library layout that matches the core. |

### Core-file specific

| Option | Description |
|---|---|
| `--dependency-root, -r` | Path prefix applied to every binary / shared-library path referenced by the core. Use this when the dump was taken inside a container or chroot and the PHP binary / libs are not at the same absolute path on the analysis host. For example, `-r /proc/<pid>/root` when the target container is still alive on the same host, or `-r /var/lib/docker/.../merged` / a locally extracted image root. |
| `--memory-limit` | Set the analyzer's own `memory_limit` (e.g. `2G`). Core files can be large; the analyzer holds data structures proportional to the live heap. |
| `--no-cache` | Disable the binary analysis cache (`~/.cache/reli/binary-analysis/`). Useful if you are analyzing a dump whose binary differs from one the cache already knows about. |

### Target PHP options (same as other inspector commands)

`--php-version`, `--php-regex`, `--libpthread-regex`,
`--zts-globals-regex`, `--php-path`, `--libpthread-path` behave the
same way as in `inspector:trace` / `inspector:memory`. Auto-detection
usually works; override only if the binary layout inside the core
differs from what reli can infer.

### Output options (same as `inspector:memory`)

`--output-format` / `-f`, `--output` / `-o`, `--pretty-print`,
`--db-host`, `--db-port`, `--db-name`, `--db-user`, `--db-password`,
`--memory-limit-error-file`, `--memory-limit-error-line`,
`--memory-limit-error-max-depth` — see
[memory-profiler.md](memory-profiler.md) for full semantics.

## Taking a core dump

Any tool that produces a standard ELF core will work. A few common
options:

- **`gcore` (from gdb)** — non-destructive, the target keeps running:

  ```bash
  sudo gcore -o /tmp/myapp <pid>
  ```

- **Signal-based** — for a crash-repro workflow:

  ```bash
  kill -SIGSEGV <pid>        # process dies, kernel writes a core
  ```

  Make sure `ulimit -c` and `/proc/sys/kernel/core_pattern` are set
  so that the core actually lands somewhere you can read.

- **`systemd-coredump`** — on systems that route cores to the journal:

  ```bash
  coredumpctl dump <pid-or-unit> --output=/tmp/core
  ```

- **Containerized / production artifact** — many orchestrators capture
  a core on OOM-kill or segfault automatically; consult your platform.

### Include enough memory in the dump

The analyzer needs to read back PHP heap chunks, the VM stack, and
several read-only segments of the binary for symbol resolution. The
kernel's per-process `coredump_filter` controls which VMAs end up in
the core. For live captures via `gcore`, set it to `0x7f` to include
private + shared + file-backed + ELF-header pages before dumping:

```bash
echo 0x7f | sudo tee /proc/<pid>/coredump_filter
sudo gcore -o /tmp/myapp <pid>
```

See `man 5 core` for the full bitmask.

## Limitations

- **NTS targets only** today. For ZTS PHP 8.2+, `_tsrm_ls_cache` is
  not in `.dynsym` on stripped binaries and is resolved via a
  brute-force TLS scan on the live process. That scan cannot always
  be reproduced against a core file, so ZTS post-mortem analysis is
  currently best-effort. (See
  `tests/Inspector/CoreDumpReader/CoreDumpReaderIntegrationTest.php`
  for the current skip condition.)
- **Dependencies must be available**: the analyzer resolves symbols
  from the PHP binary and linked shared objects. If the analysis host
  doesn't have the same files at the paths the core references, use
  `--dependency-root` to point at a directory that does (e.g. a
  locally extracted container root filesystem).
- **Core must contain the relevant memory pages**. A dump taken with
  a restrictive `coredump_filter` may be missing pages the analyzer
  needs; it will report an error or produce a partial result.
- **Status**: still marked `[experimental]`; output schema and CLI
  are stable in practice but may evolve alongside the rest of the
  memory pipeline.

## See also

- [`inspector:memory`](memory-profiler.md) — the live-process
  equivalent. Output format and downstream pipeline are shared.
- [`inspector:memory:dump`](memory-dump.md) — reli's own compact
  `.relimem` dump format for offline analysis; prefer it over
  `gcore + inspector:coredump` when the target is still alive and
  reli can attach.
- [`inspector:memory:report`](memory-report.md) — generate an
  automated analysis report from the output.
- [`inspector:rmem:explore`](rmem-explore-and-serve.md) — interactive
  TUI over the SQLite output.
- [`gcore` comparison](internals/memory-dump-vs-gcore.md) — internals
  note on trade-offs between reli's native dump and ELF core files.
