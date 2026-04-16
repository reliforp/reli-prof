# rmem Derived Cache

## Status

Proposed design. Not yet implemented.

## Problem

Several expensive derived structures are computed from the rmem graph
every time report, rmem:query, or rmem:explore loads a file:

| Structure | Cost (report16) | Size on disk |
|-----------|----------------|-------------|
| SCC (Tarjan) | ~10% of report time | int32[node_count] + profiles |
| subtree_sizes | ~1.5% of report time | int64[node_count] |
| canonical grouping | ~5% of report time | int32[node_count] |

These are pure functions of the graph — the result depends only on
the nodes and edges in the rmem file. Caching them avoids redundant
computation across tool invocations.

## Design

### Sidecar file

A `.rmem.derived` file stored alongside the `.rmem`:

```
/path/to/output.rmem
/path/to/output.rmem.derived    ← sidecar
```

### Validity

The sidecar is valid if and only if:
- the `.rmem` file exists at the expected path
- the `.rmem` file's mtime matches the recorded mtime
- the `.rmem` file's size matches the recorded size
- the sidecar format version matches the current code

If any check fails, the sidecar is discarded and rebuilt.
This avoids content hashing (expensive for multi-GB files)
while catching the common invalidation cases (re-analyze
overwrites the rmem, file moved/renamed).

Note: canonical grouping depends on locations (memory addresses),
not just nodes and edges. The validity check covers this because
any change to the rmem file changes mtime+size.

### File format

Little-endian, section-based with a TOC. Uses the same TOC
conventions as the rmem format but with a dedicated reader/writer
(the rmem `Reader` is RMEM-specific with hardcoded magic, version,
and string dictionary loading).

```
Header (32 bytes):
  magic:        "RMDC"          (4 bytes)
  version:      uint32          (4 bytes)
  rmem_mtime:   uint64          (8 bytes, unix timestamp × 1e6)
  rmem_size:    uint64          (8 bytes)
  section_count: uint32         (4 bytes)
  toc_offset:   uint32          (4 bytes)

Sections (same TOC layout as rmem):
  scc_node_map      int32[node_count]     node → SCC component ID
  scc_profiles      variable              serialized SCC profile data
  subtree_sizes     int64[node_count]     per-node retained subtree size
  canonical_map     int32[node_count]     node → canonical node ID
```

### Sections

**scc_node_map**: `int32[node_count]` — maps each node (by CSR
index) to its SCC component ID. -1 for nodes not in any non-trivial
SCC. First implementation copies into FFI buffers via fread + FFI::memcpy.
True zero-copy (mmap + FFI pointer with reader-owned lifetime) is a
possible future optimization.

**scc_profiles**: Serialized array of SCC profile structs. Each
profile contains the component ID, member count, total size, and
a few sample node IDs. Format TBD — could be packed binary or
JSON for simplicity.

**subtree_sizes**: `int64[node_count]` — retained subtree size per
node. Computed by bottom-up DFS over tree edges. Directly usable
by ChokePoint, DrillDown, BlameAllocation passes.

**canonical_map**: `int32[node_count]` — maps each node to its
canonical representative (the min node_id among all nodes sharing
the same memory address). Nodes that are their own canonical map
to themselves. Used by the substrate's canonical resolution.

### Section indexing axis

All dense-array sections use **CSR index** as the primary axis, not
raw node_id. This is consistent with FfiCsrGraphSubstrate's internal
representation and avoids axis confusion.

Section names encode the axis:

```
canonical_idx     int32[csr_node_count]   csrIdx → canonical csrIdx
subtree_by_idx    int64[csr_node_count]   csrIdx → retained size
scc_by_idx        int32[csr_node_count]   csrIdx → SCC component ID
```

When node-id based data is needed for public APIs, reconstruct via
`indexToNodeFfi[csrIdx]`.

### Build flow

Loading is split into phases to allow canonical to skip the
locations scan (which happens before CSR construction):

```
open rmem
open valid sidecar if present

Phase 1-2: load nodes, build node-id mapping
Phase 3a: if sidecar has canonical_idx → load canonical directly
          else → scan locations for canonical address grouping
Phase 3b: load node sizes/classes (from on-disk sections or locations)
Phase 4: load CSR (from on-disk sections or raw edges)
Phase 5a: if sidecar has subtree_by_idx → load
          else → compute subtree sizes
Phase 5b: if sidecar has scc_by_idx + scc_profiles → load
          else → compute SCC
Phase 6: if any section was computed → write sidecar
```

This is more granular than the original "load all or compute all"
proposal, but correctly handles the dependency ordering.

The build happens transparently on the first invocation. Subsequent
invocations of any tool on the same rmem file hit the cache.

### Integration points

**FfiCsrGraphSubstrate::loadFromBinary**:
- After Phase 4 (CSR construction), before Phase 5 (derived):
  try loading `.rmem.derived`. If valid, populate SCC arrays,
  subtree sizes, and canonical map directly. Skip computeSccFfi
  and computeSubtreeSizesFfi entirely.
- If cache miss: compute as before, then write the sidecar.

**GraphSubstrate::createFromBinary**:
- Pass the rmem file path through so the substrate can locate the
  sidecar. Currently the path is not retained after `Reader::open`.

**rmem:query / rmem:explore**:
- These tools can load the sidecar independently for specific
  queries (e.g. "which SCC does this node belong to?") without
  building the full substrate.

### Concurrency

Multiple processes may try to write the sidecar simultaneously
(e.g. parallel report workers). Use atomic write: write to a
temporary file, then rename. On conflict, the last writer wins.
All writers produce the same content for the same rmem, so any
result is correct.

Stale-writer guard: record the rmem stat tuple (mtime + size) at
build start. Before rename, re-stat the rmem. Only rename if the
stat still matches. Otherwise discard the temp sidecar (the rmem
was rewritten while we were computing).

### Cache eviction

No automatic eviction. The sidecar is small relative to the rmem
(~0.5 GB for 30M nodes vs ~2 GB rmem). Manual deletion if needed:
`rm foo.rmem.derived`.

Cache flags belong on the consumer side (report/query/explore),
not on analyze:
- `--no-derived-cache`: skip sidecar read and write
- `--rebuild-derived-cache`: ignore existing sidecar, recompute
  and write

Sidecar write failures must be **fail-open**: if the directory is
read-only or disk is full, report should compute in memory and
continue without error.

### Incremental growth

Future derived data can be added as new sections without
invalidating existing ones:

- **address_index**: sorted (address, node_id) pairs for binary
  search in rmem:query
- **owner_paths**: pre-computed root-to-node path labels for
  report findings
- **node_labels**: function_name:lineno labels resolved from
  the attributes section

Each section has its own element_count in the TOC, so a partial
sidecar (missing newer sections) is still valid for the sections
it does contain. Tools check `hasSection` before using each one.

### Cost estimate

For a 30M-node graph:
- scc_node_map: 30M × 4 = 120 MB
- subtree_sizes: 30M × 8 = 240 MB
- canonical_map: 30M × 4 = 120 MB
- Total: ~480 MB on disk

Write time: `FFI::string` + fwrite, ~1 second.
Read time: fread + `FFI::cast`, ~0.5 second.

vs. current computation time:
- SCC: ~30 seconds
- subtree_sizes: ~5 seconds
- canonical: ~15 seconds

Payoff: **50 seconds → 0.5 seconds** on cache hit.

### Alternatives considered

**Embed derived data in the rmem itself**: Would require re-writing
the rmem after report generates the derived data, which is fragile
(interrupted writes corrupt the file). The sidecar approach keeps
the rmem immutable after analyze.

**SQLite sidecar**: Heavier than needed. The derived data is all
dense int arrays — no relational queries needed.

**In-memory LRU across invocations**: PHP CLI processes don't share
memory across invocations. A persistent daemon could serve this but
is overkill.

## Implementation order

Following the review recommendation:

1. Implement sidecar reader/writer and validation (RMDC format).
2. Cache `subtree_by_idx` first — simplest, no axis complexity,
   validates the machinery.
3. Add `scc_by_idx` and `scc_profiles`.
4. Add `canonical_idx` with early-load timing to skip the
   locations canonical pass.

## Open questions

1. Should scc_profiles be packed binary or JSON? JSON is easier to
   debug but wastes bytes for what is typically a small array.
   Decision: start with JSON, mark as provisional.

2. Should `rmem:explore` pre-compute all derived data on startup, or
   compute lazily per query? For an interactive TUI, eager loading
   avoids lag on the first SCC query, but wastes time if the user
   never asks about cycles.
