# Format-direct shard merge for `PdoMemoryOutput`

## Status

  - **Stage K (table b-tree merge): landed**, with parity tests
    passing and `PRAGMA integrity_check = ok` on synthetic data
    up to 5K rows.
  - **Stage L (integer index k-way merge): correct, but not yet a
    wall-clock win.** The merge produces SQLite-correct integer
    indexes (5K-row reproducer: `integrity_check = ok`, COUNT(*)
    matches the table's full-scan count, lookups by indexed
    column return the right row). At 100K nodes, however, the
    PHP-side IntegerIndexMerger overhead (sort-run dump in
    workers + k-way merge + leaf-page byte assembly via string
    operations in main) exceeds the CREATE INDEX time it saves.
    L only replaces 3 of 10 production indexes (the integer-only
    ones not depending on post-merge data); the remaining 7 are
    text-keyed or partial and still go through CREATE INDEX, so
    the upper bound on L's savings is ~30% of CREATE INDEX time.

    See **next-session followups for L** below.

### How the index b-tree convention bit us

The bug we hit on the way to L's correctness was subtle and worth
calling out so the next iteration doesn't re-introduce it:
SQLite's index b-tree convention *promotes* the last leaf cell
into the parent interior page as the divider key, and then
**removes** that entry from the leaf. So unique entries are split
between leaves (most) and interior cells (one per non-rightmost
leaf), and dbstat sums them as `(leaf_cells + interior_cells)`
to match the actual entry count.

Our first IntegerIndexMerger draft kept all entries in leaves
*and* added separate divider records in interior cells, so
dbstat reported 5013 entries for 5000 rows of input. SQLite's
COUNT(*) over the index trusted this, queries like
`SELECT COUNT(*) FROM t INDEXED BY idx WHERE col=…` returned
5013 instead of 5000, and `PRAGMA integrity_check` reported
`wrong # of entries in index idx_context_edges_run_child`.
The fix is in the current `IntegerIndexMerger::merge` shape:
when emitting a non-rightmost leaf, hold back its last cell and
pass the *payload* of that cell up as the interior divider, so
each entry lives in exactly one place in the b-tree.

### Next-session followups for L

L is correct but doesn't yet beat the wall-clock cost of CREATE
INDEX it replaces. Three meaningful followups, in priority order:

  1. **Extend coverage from 3 of 10 indexes to all 10**.
     The biggest leverage is the partial index
     `idx_context_edges_strong_nontree_links` (`WHERE is_tree=0
     AND strength='strong'`, 4-column key including `link_name`
     TEXT) and the 5 other text-keyed indexes. Each needs the
     IntegerIndexMerger generalised to handle TEXT columns —
     the encoder already does (`RecordEncoder::encodeIntegerRow`
     becomes `encodeRow` with a per-column type vector), but the
     k-way merge needs a comparator that respects SQLite's
     default BINARY collation for TEXT (bytewise compare),
     and the partial index needs the worker's SQL dump to add
     the `WHERE` predicate.

  2. **Replace string-based page assembly with FFI buffer ops.**
     `IntegerIndexMerger::buildLeafPage` currently does
     `substr_replace` for every cell pointer and cell payload —
     that's quadratic in cells per page (~370 substr_replace
     calls per leaf, each scanning a 4 KiB string). A `FFI::new`
     `unsigned char[4096]` plus direct offset writes via
     `FFI::memcpy` would drop this to linear. Same for
     `buildInteriorPage`. Same trick is already used in
     `Reli\Lib\File\NativeFileReader` so the pattern fits.

  3. **Skip the per-shard SQLite SELECT for sort-run dumps**.
     Workers currently `SELECT key, rowid FROM table ORDER BY …`
     into the sort run, which makes SQLite re-sort already-sorted
     data (rmem-derived rows arrive in node_id / rowid order
     anyway). Reading directly from the rmem section in the
     worker, projecting the indexed columns, and writing the
     sort run without round-tripping through SQLite would skip
     a full table scan + sort per index per shard. For 4 cores
     × 3 indexes × 100K rows that's ~12 saves of an n-log-n
     sort.

Combined, (1) + (2) close the wall-clock gap to direct-write at
100K and put parallel solidly ahead at 1M+. (3) is gravy.

### Reproducing the L correctness check (now passing)

```php
$rmem = tempnam(sys_get_temp_dir(), 'x_') . '.rmem';
$dbp  = tempnam(sys_get_temp_dir(), 'x_') . '.db';

$sink = new BinaryContextTreeSink(batch_size: 1024);
for ($i = 1; $i <= 5000; $i++) {
    $sink->emitNode(
        node_id: $i,
        parent_node_id: $i === 1 ? null : intdiv($i, 2),
        link_name: "c$i",
        type: 'ObjectContext',
        locations: [new ZendObjectMemoryLocation(0x1000 * $i, 64, 1, 7, 'Cls' . ($i % 50))],
        attributes: [],
    );
}
(new BinaryMemoryOutput($rmem))->finalizeStreaming($sink, [['k' => 'v']]);
(new PdoMemoryOutput(new SqliteDriver($dbp)))->ingestFromRmem($rmem, [['k' => 'v']]);

$db = new PDO("sqlite:$dbp");
$db->query('PRAGMA integrity_check')->fetchColumn();                                           // "ok"
$db->query('SELECT COUNT(*) FROM context_edges INDEXED BY idx_context_edges_run_child WHERE run_id=1'); // 5000
$db->query('SELECT COUNT(*) FROM context_edges NOT INDEXED');                                 // 5000
```

## Why we want this

Step 3c.1 of the unify-memory-output project introduced parallel
rowid-range sharding for the rmem → SQLite ingest. At 100K nodes the
parallel path is ~15% faster than the serial single-process rmem ingest
but is still ~1.69x slower than the legacy "PDO writes directly during
collection" path. Profiling shows the post-pause cost breakdown at
100K nodes is roughly:

|                                | seconds | share of post-pause |
| ------------------------------ | ------- | ------------------- |
| Worker shard write (parallel)  | 0.20    | —                   |
| `INSERT INTO main SELECT FROM` | 0.40    | 35%                 |
| summaries + canonical-id       | 0.21    | 18%                 |
| `CREATE INDEX`                 | 0.54    | 47%                 |

Both ingest's INSERT phase and `CREATE INDEX` are SQLite-internal
serial work — they can't be parallelized via SQLite's API because of
the per-file writer lock. To beat the direct-write wall-clock at scale
(target: ≥1M-node heaps) we need to bypass both of those phases by
writing the b-tree pages directly.

The bench prediction (extrapolated from per-phase costs) is:

| size  | direct | parallel current | + table b-tree merge | + index merge      |
| ----- | ------ | ---------------- | -------------------- | ------------------ |
|  100K | 1.25 s | 2.11 s (1.69x)   | ~1.71 s (1.37x)      | ~1.35 s (1.08x)    |
|   1M  | ~13 s  | ~22 s (1.7x)     | ~16 s (1.23x)        | ~12-13 s (≈1.0x)   |
|  10M  | ~150 s | ~220 s (1.5x)    | ~150 s (1.0x)        | ~100 s (**0.67x**) |

`direct` cannot parallelize anything (single connection, single
process), so wall-clock crosses over in our favour as N grows. Target
pause time is always shorter on the rmem path because INSERT and
CREATE INDEX run after the target is sigcont'd.

## Why SQLite's own APIs don't suffice

We checked four short-cuts that would have let us skip the file-format
work, and none of them apply on the supported runtime:

  - `sqlite_dbpage` virtual table — would let us read/write raw pages
    via SQL. Not compiled into PHP's `pdo_sqlite` (compile-time
    `SQLITE_ENABLE_DBPAGE_VTAB` flag is off) and also not in the
    system libsqlite3 (`sqlite3_compileoption_used` returns 0).
  - `sqlite3_serialize` / `sqlite3_deserialize` — bytes ↔ in-memory DB.
    Not exposed via PDO, and `SQLITE_OMIT_DESERIALIZE` is the default
    in the system build.
  - `sqlite3_backup_*` — page-level copy from one DB to another.
    Could be reached via FFI, but only does whole-DB overwrite, no
    partial merge.
  - Loadable SQLite extensions — `ENABLE_LOAD_EXTENSION` is on, so a
    custom C extension implementing dbpage-equivalent could be
    `load_extension()`'d. Pulled into a separate ticket because it
    needs a build pipeline.

## SQLite file format quick reference

We only care about ROWID tables (the only kind `PdoMemoryOutput`
creates) with auto-vacuum off (the SqliteDriver's default). Indexes are
covered in the section after that.

### Page 1: header + sqlite_master root

```
0..15   "SQLite format 3\0"
16..17  page size (u16 BE; 1 means 65536)
24..27  file change counter (u32 BE; bump on every write transaction)
28..31  database page count (u32 BE)
40..43  schema cookie (u32 BE; bump on schema change)
56..59  text encoding (1 = UTF-8 — the only one we emit)
```

The b-tree page header for sqlite_master starts at byte 100 of page 1.

### B-tree page header

```
0     page type:
        0x02 = interior index    0x05 = interior table
        0x0a = leaf index        0x0d = leaf table
1..2  first freeblock offset (or 0)
3..4  cell count
5..6  cell content area start (0 means 65536)
7     fragmented free bytes
8..11 right-most child page number (interior pages only)
```

Cell pointer array: `cell_count` × u16 BE, immediately after the
header. Each entry is the byte offset (within the page) of a cell.

### Table leaf cell (page type 0x0d)

```
varint  payload size in bytes (P)
varint  rowid
P bytes payload (record format) — but only U bytes inline, where
        U = page_size - reserved - 35 if it fits, else
        the spilled-payload formula:
            X = page_size - reserved - 35   (max payload that fits)
            M = ((page_size - reserved - 12) * 32 / 255) - 23
            K = M + ((P - M) % (page_size - reserved - 4))
            inline = K if M ≤ K ≤ X, else M
4 bytes overflow page number, present iff inline < P
```

The "reserved" byte count is the per-page reserved-space field at
header offset 20 — zero in everything we emit.

For our schemas, payload is small enough to inline most of the time;
the exception is `string_value` in `context_node_locations`, which can
be arbitrarily long. Overflow handling is mandatory.

### Table interior cell (page type 0x05)

```
4 bytes  left child page number
varint   maximum rowid in left child's subtree
```

The "right-most child" of the interior page is stored separately at
header offset 8..11.

### Record format (cell payload)

```
varint  header length, including this varint
[per column: varint type code]
[per column: value bytes per type code]
```

Type codes:

| code     | meaning                                 |
| -------- | --------------------------------------- |
| 0        | NULL                                    |
| 1        | i8                                      |
| 2        | i16 BE                                  |
| 3        | i24 BE                                  |
| 4        | i32 BE                                  |
| 5        | i48 BE                                  |
| 6        | i64 BE                                  |
| 7        | f64 IEEE 754                            |
| 8        | integer 0 (no bytes)                    |
| 9        | integer 1 (no bytes)                    |
| N≥12 even| BLOB of (N - 12) / 2 bytes              |
| N≥13 odd | TEXT of (N - 13) / 2 bytes              |

For an INTEGER PRIMARY KEY (a.k.a. "rowid alias"), the column value in
the record is stored as type-code 0 — the actual rowid lives in the
cell header instead.

## Stage K: table b-tree merge

### Pre-conditions on shards

1. Per-shard worker assigns *globally-disjoint, contiguous rowids* to
   each row (worker `i` of `N` workers gets rowids
   `[i * total / N + 1, (i + 1) * total / N]`). Today the workers
   leave the rowid to be auto-assigned by the shard SQLite; for
   format-direct merge we have to pin it explicitly with
   `INSERT INTO ... (rowid, ...) VALUES (?, ?, ...)`.
2. Worker uses `PRAGMA page_size = 4096` and the same other PRAGMAs
   the main DB uses. The shard's `auto_vacuum` must be 0 (no ptrmap
   pages).
3. Shard table b-trees are SQLite-correct on disk — pages are
   integrity-checkable by `PRAGMA integrity_check`.

### Algorithm (single table, multi-shard)

```
fn merge_table(main, shards, table_name):
    main_root = sqlite_master.rootpage where name = table_name
    assert main is currently empty for this table
        (rootpage points at a leaf with cell_count == 0)

    leaf_records = []        # accumulated (max_rowid, new_page_no)
    next_page    = main.page_count + 1

    for shard in shards (in rowid-range order):
        shard_root = shard.sqlite_master.rootpage(table_name)
        shard_leaves = walk_btree_collect_leaves(shard, shard_root)

        for shard_leaf_pgno in shard_leaves:
            page = shard.read_page(shard_leaf_pgno)
            (page, overflow_remaps) = renumber_overflow_pointers(
                page, base_offset = next_page - shard_leaf_pgno
            )
            for ovf_old_pgno in overflow_remaps:
                ovf_pages = shard.read_overflow_chain(ovf_old_pgno)
                for op in ovf_pages:
                    op = renumber_next_overflow(op,
                        base_offset = next_page - shard_leaf_pgno)
                    main.append_page(op)
                    next_page += 1
            main.append_page(page)
            max_rowid_in_leaf = read_last_cell_rowid(page)
            leaf_records.append((max_rowid_in_leaf, next_page))
            next_page += 1

    new_root_pgno = build_table_btree_above_leaves(main, leaf_records)
    main.update_sqlite_master_rootpage(table_name, new_root_pgno)
    main.update_header_page_count(next_page - 1)
    main.bump_change_counter()
```

`build_table_btree_above_leaves` builds bottom-up: chunk the leaves
list into groups that fit in one interior page (at ~510 cells per
4 KiB page for our column counts), emit interior pages, then chunk
those into the next level, etc., until one page remains. That page is
the new root.

`renumber_overflow_pointers` walks the cell pointer array of a leaf
page; for each cell whose payload size exceeds the inline threshold,
the trailing 4 bytes are an overflow page number. Add `base_offset` to
those.

`update_sqlite_master_rootpage` is the trickiest piece: we have to
parse page 1's sqlite_master leaf, find the row whose `name = X`,
and rewrite its `rootpage` column in the cell record. This requires
record-format encode/decode. Because the rootpage column is stored as
an integer of variable byte width (type codes 1..6, 8, 9), the cell
size can change as a result. Handle the size delta by either:

  - shifting subsequent cells within the page (fast path, common case
    where 4 bytes is enough room), or
  - falling back to writing through `PDO::exec("UPDATE sqlite_master ...
    WHERE name = ?")` after reopening the file (slow path; needs
    `PRAGMA writable_schema=ON`).

The fast path is preferred because it doesn't reopen the SQLite
connection; the slow path is the safety net for the case where the
new rootpage number's varint width differs.

### Test plan (Stage K)

`tests/Inspector/Output/MemoryOutput/SqliteRaw/TableMergerTest.php`:

  1. **Single-leaf shard**: a shard whose table b-tree fits in one
     leaf. Merge into an empty main; verify the row count and SELECT
     contents. `PRAGMA integrity_check` must return `ok`.
  2. **Multi-leaf single-shard**: shard with enough rows to force
     internal pages. Same checks.
  3. **Two-shard sequential rowid**: shard 0 rowids 1..N, shard 1
     rowids N+1..2N. Verify rowids preserved and merged b-tree
     respects rowid order.
  4. **Overflow payload**: row with a 100 KiB string (`string_value`
     in `context_node_locations`). Walk the overflow chain, verify
     the payload reads back identical.
  5. **Cross-check vs PDO INSERT**: build a main DB via
     `INSERT INTO main SELECT * FROM shard.t` and another via the
     format-direct merger; assert that `SELECT * FROM main ORDER BY
     rowid` returns the same rows on both, and that
     `PRAGMA integrity_check` and `PRAGMA quick_check` both pass.

## Stage L: index merge

The same pattern, but indexes are not rowid-keyed — they're keyed by
the indexed column(s), with the rowid trailing. So shards' index
b-trees overlap key-wise; we cannot just stack their leaves.

### Algorithm

```
fn merge_index(main, shards, index_name, index_def):
    main_root = sqlite_master.rootpage where name = index_name

    # k-way merge of pre-built shard indexes' leaves.
    iters = [shard.iterate_index_cells(index_name) for shard in shards]
    heap  = min-heap keyed by (key, rowid) — the SQLite index sort key

    for iter in iters:
        if iter has cells: heap.push(iter.peek())

    leaf_records = []
    leaf_buf = empty leaf page (type 0x0a)
    next_page = main.page_count + 1
    while heap is non-empty:
        cell = heap.pop_min()
        if cell does not fit in leaf_buf:
            main.append_page(finalize_leaf(leaf_buf))
            leaf_records.append((leaf_buf.last_key, next_page))
            next_page += 1
            leaf_buf = empty leaf page
        leaf_buf.append_cell(cell)
        cell.iter.advance()
        if cell.iter has more: heap.push(cell.iter.peek())
    flush leaf_buf

    # Then build the index interior pages bottom-up, same shape as
    # build_table_btree_above_leaves but with index-style cells (the
    # interior cell payload contains the divider key, not just a
    # rowid).
    new_root = build_index_btree_above_leaves(main, leaf_records)
    main.update_sqlite_master_rootpage(index_name, new_root)
```

### Pre-conditions on shards (Stage L)

Each shard worker must `CREATE INDEX` the same indexes the main DB
will have. This adds ~25% to per-worker time but parallelizes across
N cores, which is the win we're after — `CREATE INDEX` on the merged
main DB would be fully serial.

### Test plan (Stage L)

  6. **Single index, two shards**: build shards with their own
     indexes, merge tables and the index, run `PRAGMA integrity_check`
     and verify a `WHERE` query that uses the index returns the same
     rows as the same query on a `INSERT INTO main` reference DB.
  7. **All indexes from `PdoMemoryOutput::createIndexes`**: the full
     production set, on synthetic and real-fixture data.
  8. **Partial index** (`idx_context_edges_strong_nontree_links`):
     this one has a `WHERE is_tree = 0 AND strength = 'strong'`
     clause; shard workers must filter at index-build time and the
     merger must respect the filter.

## Why we have a `SqliteRaw\Format` module already

The varint encode/decode (with full round-trip coverage on the
boundaries 0, 127/128, 16383/16384, 2^28, 2^32, 2^55/2^56, 2^56) is
the most error-prone part of the file format. Getting it wrong
silently corrupts every cell. Landing the codec separately, with its
own tests, lets the next session start with a verified piece and
focus on the structural work.

## Cleanup that follows

Once Stage K + L are in and benched, the cleanup todo (already on the
unify-memory-output branch) removes:

  - `MemoryAnalysisResult::pre_populated_db` field and the
    pre_populated_run_id pivot
  - `StreamingJsonFromDbExporter` (no longer reachable)
  - `PdoMemoryOutput::copyFromPrePopulatedDb`
  - The `createStreamingSink` legacy 5-tuple API
  - The `ingestFromRmemSerial` fallback (kept while parallel might
    not apply on non-SQLite drivers; remove after MySQL/PostgreSQL
    decision)
  - Legacy `readPdo` methods in MemoryDumpReader / CoreDumpReader

Total expected diff at cleanup: -800 to -1200 lines.
