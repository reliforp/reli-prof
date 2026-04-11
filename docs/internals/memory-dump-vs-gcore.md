# Why `inspector:memory:dump` can be slower and larger than `gcore`

## TL;DR

`i:m:dump` currently enumerates the same bytes of the target process from
several independent sources, writes them all to disk, and keeps every
region resident in PHP memory until the very end of the dump. The result
is that:

- A dump of a large PHP-FPM worker can be **~2× the size of `gcore`** on
  the same process, even though `gcore` writes every writable VMA as-is.
- The profiler's own RSS roughly **doubles** while dumping, because every
  region is first read into an FFI buffer and then copied into a PHP
  string held in `$regions[]`.
- The stop-the-world window (`--stop-process`, on by default) is longer
  than it needs to be, since all remote reads *and* the final `fwrite`s
  happen while the target is frozen.

Three independent issues combine to produce this. Fixing any one of them
helps; fixing all three lines the tool up with `gcore` in size and makes
it faster.

## Code under discussion

All the paths below live in `src/Inspector/MemoryDump/MemoryDumper.php`
(the `dump()` method) and `src/Inspector/MemoryDump/MemoryDumpWriter.php`.

`MemoryDumper::dump()` builds a flat `$read_list` of
`{address, size}` entries from many sources, then:

```php
foreach ($read_list as $entry) {
    $data = $this->memory_reader->read(
        $pid,
        $entry['address'],
        $entry['size'],
    );
    $regions[] = [
        'address' => $entry['address'],
        'size' => $entry['size'],
        'data' => \FFI::string($data, $entry['size']),
    ];
}

// ...later...
$writer->write(
    $output_path,
    $pid,
    $php_version,
    $eg_address,
    $cg_address,
    $all_areas,
    $regions,
);
```

## Finding 1: `$read_list` contains overlapping sources

The list is populated from all of these (current code, in order):

1. `EG` struct  (`sizeof(zend_executor_globals)`)
2. `CG` struct  (`sizeof(zend_compiler_globals)`)
3. ZendMM main chunks (2 MB each, walked via
   `$main_chunk->iterateChunks()`)
4. Huge allocations from the ZendMM huge list
   (`$main_chunk->heap_slot->iterateHugeList()`)
5. `[heap]` VMA (with pagemap-residency filter)
6. `/dev/zero` VMAs for opcache SHM (with pagemap filter)
7. Anonymous writable mmap regions (with pagemap filter)
8. The PHP binary's writable data/BSS segments
9. VM stacks (`$eg->vm_stack->iterateStackChain()`)
10. Compiler arenas and AST arenas
    (`$cg->arena`, `$cg->ast_arena`, each walked via `iterateChain`)
11. Optionally, read-only PHP binary segments when `--include-binary`

Sources 3, 4, 9, 10, 11 overlap with source 7 (and sometimes source 5)
because:

- ZendMM chunks are allocated via `mmap(MAP_ANONYMOUS | MAP_PRIVATE)` at
  2 MB granularity. They appear as ordinary anonymous writable VMAs in
  `/proc/pid/maps`, so source 7 already sees every page of them.
- Huge ZendMM allocations are also large anonymous mmaps and show up in
  source 7.
- VM stacks are allocated from ZendMM, so their pages come from inside
  the ZendMM chunks that source 7 already covers.
- Compiler and AST arenas come from `zend_arena_create()`, which is
  backed by `malloc(3)`. Those bytes live either in `[heap]` (source 5)
  or in an anonymous mmap region created by glibc malloc for allocations
  above `M_MMAP_THRESHOLD` (source 7).
- Sources 1 and 2 (EG/CG) live inside the PHP binary's `.bss`, which is
  source 8.

Every overlap pays twice:

- `memory_reader->read()` is called once per entry, so overlapped bytes
  are **read from the target process twice** (or more). For a PHP-FPM
  worker with a 500 MB+ ZendMM heap this is a non-trivial amount of
  `process_vm_readv` work while the target is stopped.
- `MemoryDumpWriter::writeRegions()` writes every region verbatim, so
  overlapped bytes are **stored on disk twice**, roughly doubling the
  dump size for ZendMM-heavy processes.

`gcore` has no such problem: it enumerates VMAs from `/proc/pid/maps`
exactly once and dumps each.

## Finding 2: pagemap residency filtering is silently defeated

The pagemap optimisation in `findResidentRuns()` (lines 398–473 of
`MemoryDumper.php`) exists so that opcache's ~128 MB SHM region and a
half-filled glibc `[heap]` do not balloon the dump. It is applied to:

- `[heap]`
- `/dev/zero` (opcache SHM)
- Anonymous writable mmap regions

It is **not** applied to:

- ZendMM chunks (source 3)
- Huge list entries (source 4)
- PHP binary RW (source 8)
- VM stacks (source 9)
- Compiler/AST arenas (source 10)

The combination of Finding 1 and this asymmetry is the real killer:

1. Source 7 scans a 2 MB anonymous VMA that happens to be a ZendMM chunk
   and turns it into, say, `[(addr+0, 128KB), (addr+1MB, 64KB)]` — a
   few resident runs totalling ~200 KB.
2. Source 3 then appends the **same VMA** as a single
   `{addr, 2 MB}` entry, unfiltered.
3. On disk the dump contains both copies. `MemoryDumpReader` uses a
   linear first-match lookup over `region_index`, so readers still
   work — but the 2 MB unfiltered copy is sitting right there in the
   file.

Net effect: the pagemap filter ends up optimising nothing for ZendMM
chunks, which are exactly the regions most likely to have non-resident
pages (ZendMM routinely `MADV_DONTNEED`s free pages). That is the main
reason the dump ends up larger than `gcore` on a real workload — gcore
relies on the kernel emitting only resident pages in the
`PT_LOAD`/core segment, while `i:m:dump` actively writes back the
non-resident ones via the redundant source 3 entry.

## Finding 3: peak profiler RSS is ~2× the dump size

Look at the `$regions[]` accumulation loop. For each entry we:

1. `FFI::new("unsigned char[N]")` — allocates an FFI buffer of the exact
   region size (no chunking, even for multi-MB regions).
2. Call `process_vm_readv` into it.
3. `\FFI::string($data, $size)` — allocates a **second** copy as a
   PHP string (strings in PHP are not zero-copy views over cdata).
4. Push the PHP string into `$regions[]`.

Only after *every* region has been read does the writer run. So the
peak working set of the profiler is roughly

    peak ≈ (largest FFI buffer currently alive) + Σ(all region sizes as PHP strings)

For a multi-gigabyte PHP-FPM worker that is (a) a huge amount of PHP
string data, which forces garbage collector activity and can push the
profiler over its `memory_limit`, and (b) a long runway before any byte
is flushed to disk. Since the caller holds `ProcessStopper::stop()`
around the entire `dump()` call, the stop-the-world window covers the
whole read-then-write sequence — including the `fwrite` loop, which
does not need the target to be frozen at all.

`gcore` avoids this entirely: it reads one region at a time into a
small kernel buffer and `write(2)`s it to the core file immediately.

## Consequences in numbers (rough order-of-magnitude)

For a PHP worker with:

- ~40 × 2 MB ZendMM chunks, ~50% resident (so ~40 MB live data)
- 128 MB opcache SHM, ~8 MB resident
- 32 MB `[heap]`, ~8 MB resident
- A handful of arena and VM stack regions, all inside the above

Approximate dump sizes:

| Tool            | Approx. size  | Notes                                    |
|-----------------|---------------|------------------------------------------|
| `gcore`         | ~55 MB        | Resident pages only, one copy each.      |
| `i:m:dump`      | ~135 MB       | ZendMM chunks duplicated, unfiltered.    |
| `i:m:dump` (ideal) | ~55 MB     | After deduping + uniform pagemap filter. |

Profiler peak RSS during dump is roughly `dump_size` × 2 today, versus
`O(largest_region)` for `gcore`.

## Fix directions (not yet implemented)

These are the minimum changes that would bring `i:m:dump` in line with
`gcore`:

### A. Merge intervals before reading

After `$read_list` is built, sort by address and merge overlapping
intervals (strict overlap, not "adjacent"). This makes sources 3, 4, 9,
10, 11 effectively no-ops whenever their bytes are already covered by
source 5 or 7, so each byte is enumerated at most once. Code-wise this
is a small `self::mergeIntervals(array $intervals): array` helper.

### B. Apply pagemap filtering to the merged list, not per source

Move the `findResidentRuns()` call from inside each source to a single
post-merge pass. This way ZendMM chunks, huge allocations, VM stacks
and arenas benefit from residency filtering too, without being
bypassed by a second unfiltered entry from a different source.

Keep the current fallback (if pagemap returns `null`, fall back to the
full range) to preserve behaviour on systems where `/proc/pid/pagemap`
is inaccessible.

### C. Stream the writer

Split `MemoryDumpWriter::write()` so that regions are fed in via an
iterable (or a callback) and each region is `fwrite`n as soon as it is
read. The header currently writes `region_count` up front; this can be
fixed up with a single `fseek` + `fwrite` at the end for the case where
some reads fail mid-stream.

That shape also lets the caller resume the target as soon as the last
remote read is done, before the final `fwrite`s and fsync land, which
shrinks the stop-the-world window.

### D. (Nice to have) Chunk remote reads

`MemoryReader::read()` allocates `unsigned char[N]` at the exact region
size. A few-MB allocation is fine; a multi-hundred-MB single allocation
is not. Once (C) is in place it is easy to split very large regions
into, say, 1 MB sub-reads and stream them out, bounding both the FFI
allocation and the eventual PHP-string copy.

## Things that are *not* wrong (ruled out while investigating)

- `MemoryDumpReader::read()` in `MemoryDumpReaderFactory` uses a linear
  first-match scan over `region_index`, which *is* slow for a dump with
  many regions — but it only affects analyse-time, not dump-time.
  Deduping via fix (A) also shrinks this index as a bonus, so analyse
  time improves for free.
- `stop_process=true` is the right default for correctness; the goal is
  to shrink the window, not remove it.
- The explicit ZendMM chunk / arena / VM-stack walks are not wasted
  work for analysis — the analyzer still needs those data structures.
  They only need to stop contributing redundant byte ranges to the
  dump list, which fix (A) achieves without losing any semantics.

## Where this should live in code if/when we implement

- `MemoryDumper::dump()`: build intervals → merge → pagemap-filter →
  stream read+write.
- `MemoryDumpWriter`: add a `writeStreaming()` method that accepts an
  iterable and seek-back-patches `region_count`. The existing
  `write()` can become a thin wrapper so the unit tests in
  `tests/Inspector/MemoryDump/MemoryDumpWriterTest.php` and
  `MemoryDumpRoundtripTest.php` keep working unchanged.
- Existing integration tests already exercise opcache SHM pagemap
  filtering (`MemoryDumpOpcacheIntegrationTest::testLightweightDumpSmallerThanFullShm`);
  the uniform-filter change should tighten those assertions rather
  than relax them.

## Empirical measurements (2026-04-11)

Two PHP 8.3-cli containers dumped with both `gcore` and the current
`i:m:dump`, then the reli dump analysed via `inspector:memory:analyze`
into SQLite so the `context_node_locations.region` column could answer
"which dumped bytes ended up inside ZendMM vs. outside?".

| metric            | target1 (light)  | target2 (heavy, 50k objects) |
|-------------------|------------------|------------------------------|
| VmRSS             | 32 MiB           | 67 MiB                       |
| RssAnon           | 10 MiB           | 47 MiB                       |
| `i:m:dump` size   | **17.5 MiB**     | **89.7 MiB**                 |
| `gcore` size      | 152 MiB          | 324 MiB                      |
| `i:m:dump` time   | 0.80 s           | 0.39 s                       |
| `gcore` time      | 0.90 s           | 1.82 s                       |

Key observation: for target2 the `i:m:dump` file (89.7 MiB) is almost
**twice RssAnon (47 MiB)**. That is only possible if the dump is writing
the same bytes two or three times, which is exactly the duplication
described in Findings 1 and 2. On the same run gcore is ~3.6× bigger
than `i:m:dump` but that is mostly the 256 MiB opcache SHM that gcore
faithfully writes back as "all zero for non-resident pages".

### Where dumped bytes live, per analyser region

Queried via `SELECT region, COUNT(*), SUM(size) FROM context_node_locations GROUP BY region`:

| region            | target1 (n / bytes) | target2 (n / bytes)          |
|-------------------|---------------------|-------------------------------|
| `zend_mm_huge`    | 1 / 3.00 MiB        | 6 / 23.00 MiB                |
| `zend_mm_heap`    | 10 252 / 2.04 MiB   | 229 028 / 17.05 MiB          |
| `compiler_arena`  | 80 / 17 KiB         | 242 / 37 KiB                 |
| **`outside`**     | **8 358 / 746 KiB** | **8 359 / 745 KiB**          |

The remarkable data point is that **`outside` is ~745 KiB in both
runs**. Off by one location between light and heavy — same content, same
sizes. It is *workload-independent*: every PHP process ends up with the
same ~745 KiB of "reachable-from-EG-but-not-in-ZendMM" metadata after
MINIT finishes.

### What is in those 745 KiB of `outside`

Breakdown by `location_type` (target1, target2 differs by <1 %):

| Location type                  | count | bytes    | What it is                                              |
|--------------------------------|-------|----------|---------------------------------------------------------|
| `ZendArrayTableMemoryLocation` | 643   | 307 KiB  | `HashTable::arData` for internal class/method/const tables |
| `ZendStringMemoryLocation`     | 4 258 | 169 KiB  | Interned strings (function/class/const names)           |
| `ZendClassEntryMemoryLocation` | 205   | 105 KiB  | Internal class entries (Exception, SQLite3, ...)        |
| `ZendConstantMemoryLocation`   | 1 811 | 43 KiB   | Registered constants (`PHP_VERSION`, `SODIUM_*`, ...)   |
| `ZendPropertyInfoMemoryLocation` | 731 | 41 KiB   | Internal class property info                            |
| `ZendArrayTableOverhead`       | 6     | 33 KiB   | Oversized `arData` buckets                              |
| `ZendClassConstantMemoryLocation` | 399 | 22 KiB  | Internal class constants                                |
| `DefaultPropertiesTable`       | 93    | 11 KiB   | Pre-baked default property zvals for internal classes   |

All of these are produced by extensions during `PHP_MINIT_FUNCTION` via
`zend_register_*` / `pemalloc(..., 1)`. They never move, never grow
during normal execution, and never reference anything in ZendMM — the
edges go in the other direction (ZendMM zvals point *out* at these).

### Where they physically live, by 1 MiB bucket

Address-based bucketing of `outside` locations:

| Bucket             | VMA (from the dump's memory map)             | bytes   | Content                       |
|--------------------|----------------------------------------------|---------|-------------------------------|
| `0x560f4c...` ×3   | `[heap]` (glibc brk)                         | 540 KiB | ClassEntry / HashTable `arData` / Constants / PropertyInfo |
| `0x40c00000` + `0x41200000` | `/dev/zero` opcache SHM             | 170 KiB | Interned strings              |
| `0x560f17d00000`   | `/usr/local/bin/php` `.rodata` (RO file-backed) | 36 KiB | Pre-baked hash tables for static arrays |
| `0x560f18200000`   | `/usr/local/bin/php` `.data` (RW file-backed) | 512 B  | Pre-baked object handlers      |
| `0x560f18100000`   | `/usr/local/bin/php` `.rodata`               | 200 B   | Extra object handlers          |

So `outside` clusters into three very narrow places: **glibc heap
(72 %)**, **opcache SHM (23 %)**, and **PHP binary .rodata/.data (5 %)**.

### The killer observation: `[heap]` is 77 % wasted

For target1:

- `[heap]` resident bytes dumped by `i:m:dump`: 2 324 KiB
- `[heap]` bytes the analyser actually touches: **540 KiB**
- Wasted bytes: **1 784 KiB (76.8 %)**

What is in that wasted 1.8 MiB? It is not PHP metadata — it is glibc
malloc internal bookkeeping (chunk headers, fastbins, tcache), plus
per-handle state from the target's extensions (sqlite3 connection
state, curl easy/multi handles, opcache process-private state) that
the memory-analysis pass never visits because it is not reachable from
EG/CG.

The same is true of the opcache SHM region: the dump writes ~2 491 KiB
of resident SHM runs, but the analyser only reads ~170 KiB of that
(just the interned strings). 93 % of the SHM bytes we read, copy, and
fsync are never looked at by the analyser.

`[heap]` and `/dev/zero` bulk scans are therefore the biggest waste,
not the ZendMM-chunk duplication from Finding 1 (although that is
significant too — it roughly doubles the ZendMM portion).

## Fix direction E — "reachability-guided peek list" (outside heuristic)

The `outside` total is bounded by a small, workload-independent count
(~8k locations ≈ 750 KiB). Every single location in it is reachable
from a short list of root `HashTable`s in EG/CG. We do not need to scan
`[heap]` or the bulk of opcache SHM at all if we can generate the peek
list from pointer walks.

### Algorithm

At dump time, while the target is stopped:

1. **ZendMM bulk phase (same as today, minus Findings 1-3)**
   - Merge intervals to dedupe overlapping sources.
   - Apply pagemap residency filter uniformly on merged intervals.
   - Covers `zend_mm_heap` + `zend_mm_huge` + compiler arena + VM stacks.

2. **Metadata peek phase (new)**
   Run a lightweight reachability walk from the roots:
   - `EG(function_table)` → each `zend_function *` (~96 B for internal functions) and its `arg_info[]`.
   - `EG(class_table)` → each `zend_class_entry` (512 B) and, one hop deep, its `properties_info` `HashTable`, `constants_table` `HashTable`, `function_table` `HashTable`, `default_properties_table[]`.
   - `EG(zend_constants)` → each `zend_constant` (~48 B).
   - `EG(included_files)` → each included-file entry.
   - `EG(module_registry)` → each `zend_module_entry`.
   - For any `zend_string *` encountered along the way that is outside
     a ZendMM chunk/huge, record a peek for it too (its header + body
     length are in the first 24 bytes, so the size is bounded per string).

   Classify each peek: if the target address falls inside a ZendMM
   chunk/huge we already cover it — skip. Otherwise record
   `(address, sizeof(zend_struct))`.

3. **PHP binary RW segment**: keep bulk-copying (it is ≤ a few pages).

4. **Drop the `[heap]` bulk scan entirely.**
5. **Drop the anonymous-writable-mmap scan entirely** (ZendMM chunks
   are enumerated by step 1, glibc malloc overflow is not PHP data).
6. **Drop the `/dev/zero` opcache SHM bulk scan.** Any interned string
   addresses that fall inside SHM are picked up by the peek walk in
   step 2 via `CG(interned_strings)` or the opcache interned-strings
   hash table.

### Expected size savings (projected from target2 data)

| Source                               | Current          | Proposal      |
|--------------------------------------|------------------|---------------|
| ZendMM chunks (with duplicates)      | ~45 MiB          | ~20 MiB       |
| ZendMM huge (sometimes dumped twice) | ~46 MiB          | ~23 MiB       |
| `[heap]` bulk                        | ~3 MiB           | 0 (peeks only)|
| `/dev/zero` SHM bulk                 | ~2.5 MiB         | 0 (peeks only)|
| Anonymous writable mmap              | ~ few hundred KiB| 0             |
| PHP binary RW                        | ~30 KiB          | ~30 KiB       |
| Metadata peeks                       | n/a              | ~750 KiB      |
| **Total**                            | **89.7 MiB**     | **≈ 44 MiB**  |

The dump shrinks to roughly half and ends up essentially the same size
as RssAnon, which is the structural lower bound.

### Stop-the-world window

Bulk remote-read volume drops from ~89 MiB to ~44 MiB, which directly
shortens the `process_vm_readv` work. The peek phase adds ~8 000 small
`process_vm_readv` calls; at ~10–20 µs each that is ~100–200 ms of
extra latency. On the heavy target, the current implementation already
runs in ~390 ms (dominated by sending 90 MiB through FFI and into PHP
strings), so the proposal should be in the same ballpark or faster,
while using ~2× less peak profiler RSS thanks to fix (C) streaming.

### Implementation shape

`MemoryLocationsCollector` already knows how to walk every root
`HashTable` and every Zend struct the analyser cares about. Instead of
writing a second walker in `MemoryDumper`, reuse the collector with a
new "address-recording sink": instead of emitting full location
objects, the sink appends `(address, size)` to a peek list. The
existing `ContextTreeSink` interface should be a close fit.

`MemoryDumper::dump()` then becomes:

```
$intervals  = collectZendMMIntervals();   // chunks, huge, stacks, arenas
$merged     = mergeIntervals($intervals); // (A)
$filtered   = applyPagemapFilter($merged);// (B)

$peek_sink  = new AddressRecordingSink();
$collector->collectAll(..., $peek_sink);  // (E)
$peeks      = $peek_sink->dropInsideZendMM($intervals); // keep only "outside"

$writer->writeStreaming(
    $path,
    ...,
    iterable: mergeAndStream($filtered, $peeks, $php_rw_areas),
);
```

### Open risks

1. **Coverage drift**: the analyser could, in some version of PHP,
   reach an address the dump-time peek walk did not record. Mitigation:
   run the same dump through `i:m:analyze` in CI, compare the
   `outside` location count and total bytes against a snapshot. Today's
   number for a canary PHP-CLI workload is ~8 358 locations / ~745 KiB;
   any new version bumping that by more than, say, 5 % fails the test.

2. **Per-version Zend struct sizes**: peek sizes need to match the
   `zend_type_reader` for the target PHP version. The existing
   dereferencer already handles this; we just need the peek walker to
   pull sizes from `ZendTypeReader::sizeOf(...)`.

3. **opcache SHM coverage**: if the peek walker misses an interned
   string that lives in SHM (for example because the analyser reached
   it via a path the dumper did not walk), analyse will fail for that
   address. CI gate as above catches this.

4. **Pagemap permissions**: dropping the `[heap]` bulk scan removes one
   of the places that used to fall back to "dump the whole range if
   pagemap returned null". Since the new path does not rely on pagemap
   at all for the metadata peeks, the fallback is inherently safer,
   not worse.
