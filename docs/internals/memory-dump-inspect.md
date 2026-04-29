# `inspector:memory:dump:inspect` — RDUMP dump introspection

Developer / debugging tool. Reads a `.rdump` file produced by
`inspector:memory:dump` (or by `inspector:sidecar`) and prints its
header, the captured `/proc/<pid>/maps` snapshot, and the list of
saved memory regions.

> [!NOTE]
> This command is intended for debugging the capture pipeline
> itself — verifying what got into a dump, comparing two dumps,
> investigating analyzer failures. Both the CLI shape and the
> printed layout are **not a stable interface**: they may change
> without notice as the RDUMP container format evolves.
> Production / scripting consumers should go through
> `inspector:memory:analyze` (which produces `.rmem` / `.sqlite` /
> `.json` with versioned schemas) instead of parsing this output.

## Usage

```bash
$ ./reli inspector:memory:dump:inspect path/to/snapshot.rdump
```

The single positional argument is the dump file. There are no
options other than the global Symfony Console flags.

## What the output looks like

Three sections, in order: `Header`, `Memory Map`, `Regions`.

```
=== Header ===
  Magic:            RDUMP
  Format Version:   2
  PHP Version:      v85
  PID:              23388
  EG Address:       0x55e3a43ba620
  CG Address:       0x55e3a43bade0
  RSS at dump:      31182848 bytes
  Memory Map Count: 471
  Region Count:     126

=== Memory Map ===
  55e3a3a00000-55e3a3b2e000 r--p 00000000 fe:00 2457602 /usr/bin/php8.5
  55e3a3b2e000-55e3a3fee000 r-xp 0012e000 fe:00 2457602 /usr/bin/php8.5
  ...

=== Regions ===
  #0   0x55e3a43a1000 - 0x55e3a43c1000  (128.0 KiB)
  #1   0x55e3e15a6000 - 0x55e3e19a4000  (4.0 MiB)
  ...

Total: 126 regions, 5.0 MiB
```

### Header

The fixed-layout prefix of every `.rdump` file:

| Field | Type | Meaning |
|---|---|---|
| `Magic` | `RDUMP\0\0\0` | Container marker; readers reject anything else. |
| `Format Version` | uint32 | `1` for early dumps, `2` adds `RSS at dump`. The inspect command reads either; older formats print `(unavailable)` for newer fields. |
| `PHP Version` | string | Resolved target version (`v74`, `v85`, …). Used by `inspector:memory:analyze` to pick struct layouts. |
| `PID` | int64 | Target PID at capture time. Cosmetic only — the analyzer doesn't re-attach. |
| `EG Address` / `CG Address` | int64 | Resolved Executor Globals / Compiler Globals addresses inside the target. The analyzer treats these as the entry points into the captured heap. |
| `RSS at dump` | int64 | Resident bytes from `/proc/<pid>/statm` at capture time, or `(unavailable)` on format v1. |
| `Memory Map Count` | uint32 | Number of `/proc/<pid>/maps` entries serialised in the next section. |
| `Region Count` | uint32 | Number of saved memory regions in the third section. |

### Memory Map

A snapshot of the target's `/proc/<pid>/maps` taken *at capture
time*, formatted to look like the kernel's own output. Each line is
`begin-end perms file_offset device inode name`. The analyzer uses
this to resolve which captured region belongs to which mapped
file (binary segments, opcache SHM, anon mmaps, etc.) without
having to re-read `/proc` later.

The list typically has more entries than the `Regions` section —
read-only file-backed mappings (`.text`, `.rodata`) are skipped at
capture time unless `--include-binary` was passed, but they still
show up here so the analyzer knows where to find them on disk.

### Regions

Every actually-captured region, with its `(address, size)` pair.
The body of each region (the bytes themselves) is skipped during
inspection — the command does not load region contents into
memory, so it is safe to run against multi-GB dumps.

Useful sanity checks against this section:

- **`memory:dump` did capture the heap**: at least one region under
  `[heap]` / inside a `r--p` opcache mapping should appear.
- **`--exclude-heap` actually dropped libc heap regions**:
  the count should be lower and `[heap]`-aligned regions absent
  compared to a default dump of the same target.
- **`--include-binary` actually included `.text` / `.rodata`**:
  read-only file-backed regions should appear at high addresses
  matching the binary's segment layout.

## Source

Implementation: `src/Command/Inspector/MemoryDumpInspectCommand.php`.
The on-disk container is read with raw `unpack()` — there's no
typed parser today, so this file is also the closest thing to a
RDUMP v2 schema reference. When you change the dump writer,
update both that command and this page.

## See also

- [../memory/memory-dump.md](../memory/memory-dump.md) — user-facing
  capture flow and `--include-binary` / `--exclude-heap` semantics.
- [memory-dump-vs-gcore.md](memory-dump-vs-gcore.md) — why a
  RDUMP dump and an ELF core file diverge.
