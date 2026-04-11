# Binary Intermediate Format for analyze → report

Design notes for replacing SQLite as the **primary** intermediate between
`inspector:memory:analyze` and `inspector:memory:report` with a custom
binary format optimized for sequential write + mmap-friendly read.

This is an **unimplemented proposal** as of 2026-04-11. SQLite remains the
current default and is not removed by this design — it is demoted from
"primary intermediate" to "on-demand inspection export".

## Motivation

### What the report actually does with the DB

The substrate-backed report path (`FfiCsrGraphSubstrate::loadFromDb` →
Phase 3 passes) does not actually use SQL in any meaningful way. It runs
a handful of monolithic queries shaped like:

```sql
SELECT node_id, sum(size), min(class_name)
FROM context_node_locations
WHERE run_id = ? GROUP BY node_id
```

```sql
SELECT parent_node_id, child_node_id, link_name, is_tree, strength, id
FROM context_edges WHERE run_id = ? AND id > ? ORDER BY id LIMIT ?
```

…and feeds the rows into FFI int arrays via PHP loops. Once the substrate
is built, every pass walks the FFI buffers directly. There is no JOIN,
no WHERE filter that exercises the query planner, no indexed lookup that
actually pays for itself. The B-tree and index machinery is dead weight.

The **inspection** use case (humans running
`sqlite3 path.db "SELECT ..."` for ad-hoc queries) is genuinely useful
and should continue to be supported — but it is a fundamentally different
need from "load the graph into the substrate". Conflating them in a
single primary format is what produces the cost picture below.

### Cost picture (measured 2026-04-11)

Numbers from `analyze4.rbt` (1 run after dividing by 2; the trace
accidentally captured 2 runs into the same DB) and `report12.rbt`:

| Phase | Wall (per run) | What dominates |
| --- | --- | --- |
| analyze: `PDOStatement::execute` (bulk INSERT) | ~17% (~260 s) | row marshalling + B-tree append |
| analyze: `PDO::exec` (createIndexes) | ~22% (~270 s) | index B-tree builds |
| analyze: PRAGMA / commit / misc PDO | a few % | overhead |
| report: substrate load (`loadFromDb`) | ~79% (~478 s) | `PDOStatement::fetchAll` + `PDO::query` + per-row marshalling into FFI buffers |
| report: parallel Phase 3 passes | ~21% (~124 s) | actual analysis work |

So roughly **40% of analyze wall** and **80% of report wall** is paid
to the SQLite layer for a use case that never exercises SQL. A binary
format pessimised for nothing but "write fast, mmap fast" should be
able to recover most of this.

### Theoretical ceiling

For analyze, replacing PDO INSERT + createIndexes with sequential
`fwrite()` of pre-packed buffers:

- INSERT 260 s → 30–60 s (raw write speed dominated by PHP loop, not I/O)
- createIndexes 270 s → 0 s (no indexes; the substrate loader can build
  whatever lookup structures it needs in memory at load time, and
  inspection is no longer in the analyze critical path)
- **analyze ceiling: ~–500 s per run, roughly –30 to –35% of wall**

For report, replacing the per-row PDO marshalling with mmap + `FFI::cast`:

- substrate load 478 s → 30–60 s (mostly the same FFI buffer build, but
  reading from a flat memory region instead of PDO row dispatch)
- **report ceiling: ~–400 s per run, roughly –65 to –75% of substrate
  load and ~–55 to –65% of total report wall**

Combined ~ **–15 minutes per analyze + report cycle** on the multi-GB
captures the project actually targets.

These are ceilings; real implementation will leave some on the table
(string dict construction, attribute serialisation, the bits of report
that aren't substrate load). A realistic Stage 1 win is in the
**–10 to –12 minute** range.

## Design

### Layering

```
analyze ──▶ [primary intermediate (binary)] ──▶ report
                    │
                    └──▶ (on demand, separate command) ──▶ SQLite for inspection
```

The binary format is the new primary intermediate. The SQLite output
becomes a **secondary export target**:

- Human ad-hoc inspection: `reli inspect:export-sqlite path.rmem` produces
  a SQLite DB on demand. SQL is then available for arbitrary queries.
- MySQL / PostgreSQL output (`PdoMemoryOutput` driver abstraction)
  remains as a separate output path. It does not move to binary, and
  it is not a substitute for binary either — they target different
  audiences.

### File format

Working name: `*.rmem` (Reli Memory). Proposed layout:

```
+----------------------------------+
| Header (fixed 64 bytes)          |  magic, version, flags, TOC offset
+----------------------------------+
| Section TOC                      |  N entries × 32 bytes
|   - name (16 byte fixed)         |
|   - offset (uint64)              |
|   - length (uint64)              |
|   - element_count (uint64)       |
+----------------------------------+
| Section: string_dict             |  all strings deduped, indexed
|   - len-prefixed string × N      |
+----------------------------------+
| Section: nodes                   |  16 byte/row (fixed)
|   uint32 node_id                 |
|   uint32 canonical_id            |
|   uint16 type_id                 |
|   uint16 class_id                |
|   uint32 _padding                |
+----------------------------------+
| Section: edges                   |  16 byte/row (fixed)
|   uint32 parent_node_id          |
|   uint32 child_node_id           |
|   uint32 link_name_id            |
|   uint8  is_tree                 |
|   uint8  strength                |
|   uint16 _padding                |
+----------------------------------+
| Section: locations               |  48 byte/row (fixed)
|   uint32 node_id                 |
|   uint16 location_type_id        |
|   uint16 class_id                |
|   uint64 address                 |
|   uint64 size                    |
|   uint64 string_value_id         |  0xffffffff_ffffffff = NULL
|   uint64 type_info               |
|   uint32 refcount                |
|   uint32 region_id               |
|   uint32 bin_overhead            |
|   uint32 _padding                |
+----------------------------------+
| Section: attributes              |  variable, with offset table
+----------------------------------+
| Section: summary                 |  small key/value table
+----------------------------------+
| Section: runs                    |  metadata (created_at etc.)
+----------------------------------+
```

Design choices:

- **Little-endian fixed**, with a flag bit in the header so a future
  big-endian reader can refuse cleanly. We do not bother making the
  format byte-order independent; everywhere Reli is meaningfully used
  is little-endian.
- **Fixed-width rows** wherever possible so the substrate loader can
  do `FFI::cast("Row[N]", $base + $offset)` and walk the resulting
  array without per-row PDO dispatch.
- **String dictionary** for the high-cardinality string columns
  (`link_name`, `class_name`, `region`, `location_type`,
  `string_value`). On a typical PHP heap dump, repeated values
  (`'object_properties'`, `'array_elements'`, etc.) appear millions of
  times — the dict collapses them to one entry plus a uint32 id per
  row. Saves both bytes and per-row marshalling cost.
- **CSR is not pre-built in the file**. The substrate loader continues
  to build CSR offsets and per-direction views in memory at load time
  (the same logic `loadEdgesFfi` already runs today). Keeping the
  serialised format raw avoids freezing CSR layout choices into the
  on-disk schema and matches the substrate loader's existing API.
- **Section TOC** for forward compatibility — adding a new section
  later does not require bumping the format version, as long as old
  readers tolerate unknown section names.
- **mmap-friendly** but never relying on a specific virtual address —
  every internal pointer is a file offset, never a real pointer.

Estimated total file size for a typical multi-GB analyze: roughly
**1–2 GB** vs the current 7–14 GB SQLite output, mostly because the
string dict collapses repeated link/class names and there are no
indexes.

## Migration plan

This is a substantial refactor. Stage it.

### Stage 1: binary as alternative format (additive only)

Goal: produce a `*.rmem` file from analyze and consume it from report,
without touching the SQLite default path.

New files:

- `src/Inspector/Output/MemoryOutput/BinaryFormat/Format.php`
  Magic, version, section name constants. Shared by writer and reader.
- `src/Inspector/Output/MemoryOutput/BinaryFormat/StringDict.php`
  Two-mode helper: writer accumulates strings and assigns ids, reader
  loads the dict section into a `list<string>`.
- `src/Inspector/Output/MemoryOutput/BinaryFormat/Writer.php`
  Section-by-section append, builds the TOC, rewinds to write the
  header at the end.
- `src/Inspector/Output/MemoryOutput/BinaryFormat/Reader.php`
  Loads the file (Stage 1: `fopen` + `fread` into a string + `FFI::new`
  copy; Stage 2: real mmap via FFI). Returns offsets/lengths so the
  substrate loader can `FFI::cast` each section.
- `src/Inspector/Output/MemoryOutput/BinaryMemoryOutput.php`
  Implements `MemoryOutputInterface`. `finalizeStreaming` writes the
  buffered sink data through `BinaryFormat\Writer`.
- `src/Lib/PhpProcessReader/PhpMemoryReader/ContextAnalyzer/BinaryContextTreeSink.php`
  Implements `ContextTreeSink`. Buffers nodes / edges / locations /
  attributes into PHP int arrays (id-ised through StringDict) during
  collection. `flush()` is a no-op until `finalizeStreaming`.

Edited files:

- `src/Inspector/Output/MemoryOutput/Report/Substrate/FfiCsrGraphSubstrate.php`
  Add `loadFromBinary(string $path, int $bulk_fetch_chunk = 200000): static`
  alongside the existing `loadFromDb`. Same end state (FFI buffers
  populated, CSR built, etc.), different source.
- `src/Command/Inspector/MemoryAnalyzeCommand.php`
  New `--format` option: `sqlite` (default) | `binary`. Picks the
  matching `MemoryOutputInterface` implementation.
- `src/Command/Inspector/MemoryReportCommand.php`
  Detect input format from extension (`.rmem` vs `.db` / `.sqlite`)
  or magic bytes. Route to the matching report path.
- `src/Inspector/Output/MemoryOutput/Report/ReportGenerator.php`
  Add `generateFromBinary(string $path, ...): ReportResult` parallel
  to `generateFromDb`. Phase 1 / Phase 3 share the substrate-backed
  passes; Phase 2 SQL passes only run on the SQLite path (see Stage 1
  constraint below).

Stage 1 constraint: **binary input runs full Phase 3 substrate only**.
The Phase 2 SQL passes (`DynamicPropertiesPass`, `PropertyScalingPass`,
`StructuralDedupPass`, etc.) live in the SQLite path. A small graph
captured in binary format goes straight to Phase 3 without the SQL
fallback. Justification: substrate-backed equivalents already exist
for most of them, and writing a binary path that recreates SQL passes
defeats the point.

LOC estimate: **1500–2500** new + edited.

### Stage 2: real mmap, retire SQL fallback passes

- Replace the `fread`-into-string-then-`FFI::new`-copy path in
  `BinaryFormat\Reader` with a real `mmap()` via FFI (extend
  `LibcFileReader` with `mmap` / `munmap` cdefs). Saves the load-time
  copy and gets the report ceiling closer to the theoretical 30 s.
- Audit Phase 2 SQL passes. For each:
  - If a substrate-backed equivalent exists, drop the SQL one.
  - If not, port it to the substrate.
  - If neither is feasible, mark the pass "SQLite-only" and have
    binary input refuse to run it (or auto-build a temporary SQLite
    for that pass — probably not worth it).

Goal of Stage 2: binary becomes a complete drop-in for the report
side, no path-dependent feature gaps.

### Stage 3: flip the default

Once Stage 2 is solid:

- analyze defaults to `--format=binary`. SQLite is opt-in via
  `--format=sqlite`.
- Update README / docs.
- Ship a release note explaining the break.

### Stage 4: SQLite as inspection-only export

- `reli inspect:export-sqlite path.rmem` (new command). Reads the
  binary format and writes a SQLite file with the schema we currently
  emit, for ad-hoc SQL inspection.
- The existing `--format=sqlite` analyze path stays for users who
  want to skip the export step and write SQLite directly. Both paths
  produce equivalent SQLite output.

This is the long-term shape: binary is the wire format, SQLite is a
human-friendly export, MySQL / PostgreSQL are separate sinks for
external systems.

## Open questions

1. **Endianness flag granularity** — bit flag vs full byte vs separate
   `endianness` field? Probably bit flag in `flags` is enough.
2. **String dict ordering** — write order matters for forward
   compatibility (id reuse across schema changes). Probably "write
   order = encounter order" is fine, with the dict serialised as a
   simple array.
3. **64-bit `node_id` future-proofing** — current schema uses int32 in
   the proposal. PHP heaps with > 2^31 nodes don't exist today, but
   if they ever do, we'd need a format version bump. Worth declaring
   "version 1 uses int32, version 2 may use int64" up front.
4. **Compression** — should sections be optionally gzip'd? Saves disk
   for cold storage but breaks mmap. Probably out of scope for Stage 1.
5. **Endianness conversion on read** — if a future big-endian reader
   wants to consume a little-endian file, it pays a per-row byte swap.
   Format spec should mandate a header check that refuses cross-endian
   reads in v1 to keep things simple.
6. **Multiple runs in one file** — current SQLite supports `run_id` to
   stack multiple snapshots. Binary v1 should probably be one run per
   file (simpler, matches the actual usage pattern). Multi-run can be
   a future format version.
7. **Concurrent access** — SQLite supports multiple readers + one
   writer. Binary mmap supports multiple readers but no writers. The
   analyze write phase is exclusive to one process anyway, so this
   only affects "is anyone running report while analyze is still
   writing?" — answer: no, that's never been supported.
8. **`PdoContextTreeSink` parity** — the binary sink needs to handle
   every edge case the PDO sink handles (region classification,
   attribute encoding, etc.). Worth a side-by-side parity test
   harness during Stage 1.

## Why this is being deferred

The current branch has accumulated several real wins on the existing
SQLite path (createIndexes shrink, memo_node_id refactor, mmap_size
CLI, posix_fadvise, chunked loadNodeSizes, UNION query removal, etc.).
Those are safe, additive, and worth shipping as-is.

The binary format is a multi-week refactor that touches output,
sink, substrate loader, command CLIs, and report pipeline routing.
It deserves its own branch and its own session, so the current
branch can land without being held up.

This document captures the current understanding so the next session
can pick up without re-running the analysis.

## See also

- `docs/internals/memory-report-architecture.md` — the existing report
  architecture, including how Phase 2 SQL and Phase 3 substrate
  interact.
- `docs/internals/memory-report-performance-hotspots.md` — the
  Phase 3 substrate-level hotspots that motivated `FfiCsrGraphSubstrate`
  in the first place.
- `docs/internals/memory-analysis-optimization.md` — earlier notes on
  the analyze write path.
