# `inspector:memory:report` tuning knobs

This page documents the advanced tuning options on
`inspector:memory:report`. The `--help` text intentionally keeps these
to one-line summaries; this is where the rationale, defaults, and
"which knob to reach for first" guidance live.

`inspector:memory:compare` shares the underlying substrate loader and
graph substrate, so the substrate-related notes here apply to it
mechanically — but at the time of writing only `--ffi-csr` is
surfaced on the `compare` CLI itself; the rest are
`memory:report`-only. If a future change exposes more of them on
`compare`, they will behave identically to what is documented here.

These knobs only matter on **large snapshots** — typically
multi-GB SQLite databases, or `.rmem` files with millions of nodes.
A small / typical snapshot needs none of them.

For the user-facing flow (capture → analyse → report), see
[memory/memory-report.md](../memory/memory-report.md). For the
internal architecture (substrate, computation passes, finding
catalogue), see
[memory-report-architecture.md](memory-report-architecture.md).

---

## When to reach for which

| Symptom | First knob to try |
|---|---|
| Report OOMs while loading the substrate | `--link-cache=lazy` |
| Substrate load is slow on a multi-GB SQLite DB | `--prefetch` (default on) — or check it's not no-op'd; otherwise raise `--mmap-size` |
| Phase 3 (analysis passes) dominates the run | `--report-workers=N` |
| The report has run before on this `.rmem` and feels slow on warm runs | check the `.rmem.derived` sidecar exists; clear with `--rebuild-derived-cache` if it looks stale |
| You just want everything fresh, no caches | `--rebuild-derived-cache` (and pass `--no-derived-cache` to also skip writing) |

---

## Substrate / loading

### `--ffi-csr` / `--no-ffi-csr` (default: auto)

Force the FFI-backed CSR (compressed sparse row) graph substrate on
or off. The default `auto` picks based on size: small graphs go
through the simpler PHP-array path, large graphs go through the FFI
CSR path because flat int arrays cost a fraction of a per-element
zval. Forcing on is occasionally useful for benchmarking; forcing off
is mainly a debugging tool when an FFI-side issue is suspected.

### `--link-cache` (default: `auto`)

Strategy for the tree-edge link cache used during retained-size and
ownership analysis:

- `auto` — bulk-read on small graphs, lazy on huge ones
- `eager` — always bulk-read; faster, more memory
- `lazy` — per-edge with bounded cache; slower, flat memory

Reach for `lazy` first when the report OOMs during substrate load on
a large snapshot — it trades wall time for a flat memory ceiling.

### `--substrate-bulk-fetch-chunk` (default: `200000`)

Rows per chunked `fetchAll` when loading the substrate from SQLite.
Larger values trade memory for speed (fewer PHP/PDO round trips).
The default `200000` keeps per-chunk peak under ~80 MB on the wide
`loadEdgesFfi` row layout.

The chunked loaders rely on a `(run_id, node_id, type)` covering
index on `context_nodes` and a `(run_id, id)` index on
`context_edges`; both are installed lazily on the first report run,
so the very first run after `inspector:memory:analyze -f sqlite3`
will take a one-time index-creation cost.

### `--mmap-size` (default: `2G`)

SQLite `mmap_size` for the report read connection (and worker
connections). Suffix-aware: `K` / `M` / `G` are KiB / MiB / GiB;
plain integers are bytes; `0` disables mmap.

Bigger means SQLite memory-maps more of the database file instead of
paying `pread()` per page on substrate load. The default is pinned
at 2 GiB because that matches the typical SQLite compile-time cap
(`SQLITE_MAX_MMAP_SIZE = 0x7fff0000` ≈ 2 GiB − 16 KiB on most distro
builds). Asking for more is harmless — SQLite silently clamps —
but the help would lie about the effective value, so the default is
the realistic ceiling.

For DBs larger than 2 GiB the trailing pages still go through
`pread()` + the kernel page cache, which is usually fine if the file
is already cache-warm (which is what `--prefetch` is for).

### `--prefetch` / `--no-prefetch` (default: on)

Hint the kernel to read-ahead the entire input file into the page
cache via `posix_fadvise(POSIX_FADV_WILLNEED)` before opening it.
Applies to both input shapes:

- **SQLite `.db` / `.sqlite`** — the dominant lever on multi-GB analyze
  DBs that overflow the SQLite mmap cap (~2 GiB). On Linux this kicks
  off async read-ahead so SQLite hits a warm cache from the first
  `pread` instead of paging in synchronously page by page.
- **Binary `.rmem`** — same `posix_fadvise(POSIX_FADV_WILLNEED)` is
  issued before the substrate parser starts walking the file, so the
  binary path also benefits from a warm page cache on first read.

Silently no-ops on platforms without `posix_fadvise` (e.g. macOS) or
when PHP was built without FFI.

---

## Parallelism

### `--report-workers` (default: `1`)

Number of parallel workers for Phase 3 passes. `1` (the default)
runs sequentially in the parent process. Higher values fork children
via `pcntl_fork`; each child inherits the substrate via copy-on-write
and runs a subset of the independent Phase 3 passes in parallel.

Requires `ext-pcntl`; silently falls back to sequential if the
extension is missing.

The substrate is the part of the report run that costs memory, not
the Phase 3 passes themselves, and `pcntl_fork` makes the substrate
available to children at zero copy cost (until they touch it). So
raising `--report-workers` typically improves wall time without a
proportional memory increase, until you start hitting CPU saturation
on the node-set the passes touch.

---

## Derived-cache sidecar

`inspector:memory:report` writes a per-`.rmem` sidecar
(`<file>.rmem.derived`) that caches expensive once-per-snapshot
results — primarily subtree sizes and the SCC decomposition. By
default the sidecar is read on hit and written on miss, so a second
report run on the same `.rmem` is markedly faster.

Implementation details:
[rmem-derived-cache.md](rmem-derived-cache.md).

### `--no-derived-cache`

Skip reading and writing the sidecar. Useful for benchmarks, or when
you suspect the sidecar is stale and don't want to write a fresh one
either.

### `--rebuild-derived-cache`

Ignore the existing sidecar and recompute + rewrite it. Useful after
a reli upgrade that changed the sidecar schema, or when a sidecar
was generated against a now-removed `.rmem.derived` schema.
