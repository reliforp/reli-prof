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

## Follow-up empirical round: heavy user-class target (target3)

A third target was added: 2000 user classes (10 public props, 5 methods,
3 class constants each) loaded via one-file-per-class `require_once`, on
top of 5000 object instances. opcache CLI enabled with
`memory_consumption=256`.

This target is the closest the benchmark harness gets to a real-world
PHP application shape, and it turned out to be the **first case where
`i:m:dump` actually loses to `gcore` on wall time**, not only on size:

| target | RssAnon | `i:m:dump` | `gcore`       | verdict vs. gcore      |
|--------|---------|------------|---------------|------------------------|
| target1 (light)  | 10 MiB | 17.5 MiB / 0.80 s | 152 MiB / 0.90 s  | faster **and** smaller |
| target2 (heavy user data) | 47 MiB | 89.7 MiB / 0.39 s | 324 MiB / 1.82 s  | faster, **bigger** (1.9× RssAnon) |
| target3 (heavy user code) | 21 MiB | 44.4 MiB / 1.76 s | 298 MiB / 1.18 s  | **slower**, bigger (2.1× RssAnon) |

target3 is the shape where the tool's original "PHP pool is small vs.
the rest of the heap, so we can beat gcore" premise visibly breaks.

### The 100× scale-up test

target3 has 100× more user classes than target1 (2000 vs. 20), and the
expectation was that user-defined metadata would blow up the `outside`
location count. The `context_node_locations.region` column says
otherwise:

| region            | target1            | target3            | ratio |
|-------------------|--------------------|--------------------|-------|
| `zend_mm_heap`    | 10 252 / 2.04 MiB  | 93 119 / 8.57 MiB  | ×9    |
| `compiler_arena`  | 80 / 17 KiB        | 40 000 / 4.76 MiB  | ×500  |
| **`outside`**     | **8 358 / 746 KiB** | **8 329 / 728 KiB** | **≈ 1** |

The counts inside `outside` are **literally unchanged** between the two
runs:

| location_type                  | target1 | target3 |
|--------------------------------|---------|---------|
| `ZendClassEntryMemoryLocation` | 205     | 205     |
| `ZendConstantMemoryLocation`   | 1 811   | 1 811   |
| `ZendPropertyInfoMemoryLocation` | 731   | 731     |
| `ZendClassConstantMemoryLocation` | 399  | 399     |
| `DefaultPropertiesTableMemoryLocation` | 93 | 93    |
| `ZendStringMemoryLocation`     | 4 258   | 4 254   |
| `ZendArrayTableMemoryLocation` | 643     | 622     |

Where did the 2000 user classes × metadata go?

| location_type                  | region         | target3 count | bytes    |
|--------------------------------|----------------|---------------|----------|
| `ZendClassEntryMemoryLocation` | compiler_arena | 2 000         | 1 000 KiB |
| `ZendPropertyInfoMemoryLocation` | compiler_arena | 20 000      | 1 120 KiB |
| `ZendClassConstantMemoryLocation` | compiler_arena | 6 000       | 336 KiB   |
| `ZendOpArrayHeaderMemoryLocation` | compiler_arena | 10 000      | 2 400 KiB |
| `ZendOpArrayBodyMemoryLocation` | zend_mm_heap   | 10 000        | 2 880 KiB |
| `DefaultPropertiesTableMemoryLocation` | zend_mm_heap | 2 000     | 320 KiB   |
| `ZendArgInfosMemoryLocation`   | zend_mm_heap   | 10 000        | 960 KiB   |
| `LocalVariableNameTableMemoryLocation` | zend_mm_heap | 10 000    | 160 KiB   |

Every single byte of user-class metadata — class entries, property
infos, constants, op-array headers, op-array bodies, default property
tables, arg infos, local variable name tables — is inside a ZendMM
chunk (`compiler_arena` is allocated from `CG(arena)`, which lives in
a chunk; `zend_mm_heap` is a direct ZendMM allocation). The `outside`
set picks up **only** internal classes/functions/constants from the
`pemalloc(..., 1)` calls made by PHP extensions at MINIT.

### Why this split is structural, not accidental

Any data allocated during a request must be released at request end.
PHP's per-request cleanup is built on tearing down the ZendMM heap: at
`zend_deactivate()` time the engine simply resets the ZendMM chunks.
Anything that needs to survive across requests (internal class
definitions, interned strings, `pemalloc(..., 1)`'d extension state,
opcache-cached op arrays) **cannot** be in ZendMM — it must live in the
glibc heap, in opcache SHM, or in the PHP binary's own segments.

Conversely, anything created by user PHP code during a request **must**
be in ZendMM, otherwise it would leak. The split between "ZendMM
territory" and "persistent territory" is not a convention, it is a
hard lifetime boundary. That is why the `outside` count is workload-
invariant: the persistent territory is entirely populated at
MINIT/startup and then never grows (modulo `dl()` and preload, covered
below).

### Decision: the peek walk filter is "address ∈ already-bulk-covered"

Given the split above, `[heap]` vs. user classes does not need a
type-flag check (`ce->type == ZEND_INTERNAL_CLASS` etc.). A single
bound check — "is this pointer inside a ZendMM chunk or huge alloc?" —
already correctly partitions the walk. Everything we need to follow is
on the outside of ZendMM; everything on the inside is already covered
by the bulk chunk dump.

Concretely, the filter in the dump-time walker is:

```
pointer → skip if pointer ∈ (zend_mm_chunks ∪ zend_mm_huges ∪
                             opcache_shm_vma ∪ php_binary_rw_vma)
```

with all four sets precomputed before the walk starts. The check is a
handful of range comparisons per pointer, <100 ns.

## Preload: the one case that matters for the SHM bulk decision

`opcache.preload` is the cleanest counter-example to the "persistent
territory is tiny and static" observation. Classes loaded during a
preload script are compiled once at startup, persisted into opcache
SHM, and stay there forever. They pick up an unbounded amount of
user-authored code (Symfony projects routinely preload several
thousand classes). The runtime `zend_class_entry`, its op arrays,
arg infos, property infos, and per-class hash tables live in SHM,
not in `[heap]`.

This means **dropping the `/dev/zero` opcache SHM bulk scan is not
safe in general**. For a preload-heavy process, the peek walker would
have to chase every preloaded class into SHM and pull in a bounded
2 × (user class count) × (avg metadata per class) of peeks — at
Symfony scale that is tens of megabytes of small reads, which defeats
the speed goal of step E.

The preload-safe variant of E is therefore:

| source                          | today     | ABC-only  | ABC + E (preload-safe) |
|---------------------------------|-----------|-----------|------------------------|
| ZendMM chunks                   | bulk      | bulk (dedup) | bulk (dedup)          |
| ZendMM huge                     | bulk      | bulk (dedup) | bulk (dedup)          |
| **`/dev/zero` opcache SHM**     | bulk      | bulk      | **bulk kept**          |
| PHP binary RW                   | bulk      | bulk      | bulk                   |
| **`[heap]` (brk)**              | bulk      | bulk      | **dropped**            |
| **anonymous writable mmap**     | bulk      | bulk (merged) | **dropped**         |
| metadata peek walk              | —         | —         | **added (`[heap]` only)** |

The walker only needs to cover the `[heap]` gap. Every address it
encounters that falls inside a chunk/huge/SHM/binary-RW bulk region is
skipped because it is already in the dump. Preloaded classes in SHM
are therefore transparently handled by the SHM bulk scan; the walker
never has to chase into SHM, which makes its cost independent of the
preload size as well.

### Projected impact, rebenchmarked on target3

target3 already hit the concerning "reli is slower than gcore" point,
so it is the relevant data point for deciding whether the effort is
worth it.

Rough breakdown of the current 44.4 MiB dump3, based on region sizes
from `i:m:dump:inspect`:

| bucket in the dump                                 | size        |
|----------------------------------------------------|-------------|
| 9 × ZendMM chunks (full 2 MiB each)                | 18.0 MiB    |
| anonymous writable mmap scan (overlapping chunks)  | ~14-16 MiB  |
| compiler arena chain (78 × 64 KiB, all in chunks)  | ~5.0 MiB    |
| `[heap]` resident runs                             | ~2.6 MiB    |
| `/dev/zero` SHM resident runs                      | ~2.4 MiB    |
| PHP binary RW + BSS                                | ~100 KiB    |
| VM stack                                           | 256 KiB     |
| EG/CG                                              | ~2 KiB      |
| **total**                                          | **44.4 MiB** |

Applying the fixes:

| stage                                          | size      | time    | vs. gcore (298 MiB / 1.18 s) |
|------------------------------------------------|-----------|---------|------------------------------|
| current                                        | 44.4 MiB  | 1.76 s  | 6.7× smaller, **1.5× slower** |
| A/B/C only (dedup + unified pagemap + stream)  | ~25 MiB   | ~1.0 s  | 11.9× smaller, 1.2× faster   |
| A/B/C + preload-safe E                         | ~22 MiB   | ~0.8 s  | 13.5× smaller, 1.5× faster   |

The duplication-driven waste (chunks × anon-writable × compiler-arena
overlap, ≈ 19 MiB) dominates on target3, which is why A/B/C alone
already puts the tool back ahead of `gcore` on both axes. The incremental
value of E is another ~3 MiB shaved off and the conceptual cleanup of
"the dump file contains only PHP-related bytes" — nice to have, not
critical for beating `gcore`.

### Conclusion of the empirical round

- **A/B/C is the path to beating `gcore` on target3** (the only case we
  measured where we currently lose). It is also the path to shrinking
  `i:m:dump` peak profiler RSS by ~2× on every target.
- **E is viable** (the metadata walk is bounded and fast), but it is a
  second-order optimisation once A/B/C is done. Worth prototyping after
  ABC lands.
- **SHM bulk scan stays** for preload safety. The walker handles only
  the `[heap]` gap, so its cost is bounded by the fixed MINIT metadata
  set regardless of how much user code was preloaded.
- The dump-time filter for the walker can be purely address-range based;
  `ce->type` / `func->type` inspection is not necessary because of the
  request-scoped cleanup lifetime constraint.

## Preload measurement (target4)

A fourth target was added to verify the preload-safe variant of E
against an actual `opcache.preload` process:

- `opcache.preload=/work/preload.php`, `opcache.preload_user=root`,
  `opcache.memory_consumption=256`
- Preload script declares 2000 `PL{0..1999}` classes (each with 10
  properties, 5 methods, 3 class constants), same shape as target3
- After preload, the main script creates 3000 instances and sits idle

Runtime footprint:

| metric      | target3 (2000 user classes, no preload) | target4 (2000 preloaded) |
|-------------|-----------------------------------------|--------------------------|
| VmRSS       | 42 MiB                                  | 61 MiB                   |
| RssAnon     | 21 MiB                                  | 17 MiB                   |
| **RssShmem**| 2.4 MiB                                 | **25 MiB**               |

The 10× jump in `RssShmem` is the preload manifesting: the compiled
PL class structures (class_entry, op_arrays, property_info tables,
class constants, interned strings) stopped living in ZendMM and moved
into the persistent opcache SHM VMA.

### `context_node_locations.region` shift

| region           | target3 (no preload)  | target4 (preload)      |
|------------------|-----------------------|------------------------|
| `zend_mm_heap`   | 93 119 / 8.57 MiB     | 6 120 / 932 KiB        |
| `compiler_arena` | 40 000 / 4.76 MiB     | — (not emitted)        |
| `outside`        | **8 329 / 728 KiB**   | **174 343 / 20.2 MiB** |

`outside` grew ×25 both in count and bytes. Every single preloaded
class-metadata structure moved from `compiler_arena`/`zend_mm_heap`
into `outside`. Breakdown:

| location_type                    | target3 | target4 | delta            |
|----------------------------------|---------|---------|------------------|
| `ZendClassEntryMemoryLocation`   | 205     | 4 205   | +4 000           |
| `ZendOpArrayHeaderMemoryLocation`| —       | 20 000  | +20 000          |
| `ZendOpArrayBodyMemoryLocation`  | —       | 20 000  | +20 000          |
| `ZendPropertyInfoMemoryLocation` | 731     | 40 731  | +40 000          |
| `ZendClassConstantMemoryLocation`| 399     | 12 399  | +12 000          |
| `ZendArgInfosMemoryLocation`     | —       | 20 000  | +20 000          |
| `DefaultPropertiesTableMemoryLocation` | 93 | 4 093  | +4 000           |
| `LocalVariableNameTableMemoryLocation` | — | 20 000 | +20 000          |
| `ZendArrayTableMemoryLocation`   | 622     | 12 622  | +12 000          |
| `ZendStringMemoryLocation`       | 4 254   | 14 268  | +10 014          |
| `ZendConstantMemoryLocation`     | 1 811   | 1 811   | 0                |

The added items are exactly the expected preloaded-class metadata:
4000 class_entries, 20000 methods (2000 × 10, with two entries per
method — one in the op-array table and one as an internal form), each
method contributing arg_infos / local-variable-name tables / op-array
headers and bodies, etc.

### Where `outside` now lives

Address bucketing of target4 `outside` addresses:

| bucket range   | VMA                     | bytes     | what              |
|----------------|-------------------------|-----------|-------------------|
| `0x41c00000`–`0x42d00000` (16 buckets × ~1 MiB each) | `/dev/zero` opcache SHM | **~20 MiB** | preloaded class metadata |
| `0x562a09...` (3 buckets) | `[heap]` (brk)          | ~540 KiB  | internal classes etc. (same as target1/3) |
| `0x5629fb/fc...` (3 buckets) | `/usr/local/bin/php` RO/RW | ~36 KiB  | pre-baked hash tables |
| `0x41300000`/`0x41400000` | another `/dev/zero` SHM chunk | ~370 KiB | more preload content |

**99 % of the new `outside` bytes live in the `/dev/zero` opcache SHM
VMA.** The `[heap]` portion is the same ~540 KiB observed with the
non-preload targets: it is entirely internal-class metadata, not
preloaded user metadata.

### `i:m:dump` vs `gcore` on target4

| metric      | `i:m:dump`          | `gcore`              |
|-------------|---------------------|----------------------|
| file size   | **42.0 MiB**        | 292 MiB              |
| wall time   | **0.17 s**          | 0.86 s               |
| ratio       | 7× smaller          | 5× faster            |

**target4 is the case where `i:m:dump` wins the most.** That is
because the opcache SHM VMA is 256 MiB but only ~25 MiB is resident,
and reli's pagemap filter skips the non-resident pages while `gcore`
faithfully writes them as zeros. `gcore` ends up writing ~267 MiB of
SHM (most of which is zero) into the core file; reli writes ~25 MiB.

### Validating the preload-safe E walker on target4

The preload-safe E walker's pointer filter is:

    pointer → skip if pointer ∈ (zend_mm_chunks ∪ zend_mm_huges ∪
                                 opcache_shm_vma ∪ php_binary_rw_vma)

Applying it to target4's roots:

1. `class_table` has 2180 entries. 205 of them point into `[heap]`
   (internal classes) and are added to the peek set. The remaining
   **2000 preloaded class pointers all land in the `/dev/zero` SHM
   VMA and are skipped because they are already covered by the SHM
   bulk scan.** The walker does not read 2000 × 512 B = 1 MiB of
   class_entry bodies, does not chase their 40 000 property_info /
   20 000 class_constant / 20 000 op_array sub-pointers, etc.
2. `function_table` behaves the same way: internal functions peek,
   preloaded user functions skip (they live in SHM).
3. `zend_constants` is unchanged (all internal).
4. `module_registry` is unchanged.
5. `included_files` contains the 2000 preloaded filenames as
   `zend_string` pointers; those strings are interned in SHM and
   therefore skipped.

**Net walker cost on target4: identical to target1/target3** — the
~8 k-item peek set, ~10 ms wall time under batched
`process_vm_readv`, entirely independent of preload scale. The
preload-safe E design succeeds here.

### Residual observation: "other anon writable" regions are 0 % useful

While inspecting dump4 regions a separate finding fell out: the dump
contains three 2 MiB+ regions from source 7 (anon writable mmap
scan) that are **not** ZendMM chunks (source 3 only enumerated one
chunk), yet a `SELECT * FROM context_node_locations WHERE address
BETWEEN 0x7f51efc00000 AND 0x7f51f2800000` returns zero rows. The
analyser never reads a single byte from these ~5-6 MiB of anon
writable memory.

These are presumably glibc malloc arenas, opcache's working buffers,
or similar libc-internal state. They are correctly pagemap-filtered,
they do not duplicate ZendMM chunks (because they are outside the
chunk ranges), but they also carry no PHP data. On target4 they add
~5-6 MiB of completely unreachable bytes to the dump.

A single measurement is not enough evidence to change the current
policy (anon writable scan remains, merged via fix A), but this is
a useful data point for a future optimisation: if we can detect
"writable-anon that is not owned by ZendMM and not opcache SHM" at
dump time, we can drop it outright. An easy proxy is "the analyzer
never references it" — but that is only known after analysis, which
is too late. A cheaper proxy is "writable-anon whose page contents
look random/binary rather than containing recognisable PHP struct
signatures", which is a heuristic and carries risk.

For now the conservative stance stands: **keep the anon writable
scan, but rely on fix A to dedupe it against chunks**. This might
be revisited if target measurements in the future consistently show
>5 MiB of unreachable bytes in those regions.

### Cross-target summary (after this round)

| target | workload shape        | RssAnon+RssShmem | `i:m:dump` today   | `gcore` today     | reli vs gcore  |
|--------|-----------------------|-------------------|--------------------|-------------------|-----------------|
| target1 | light CLI             | 12 MiB            | 17.5 MiB / 0.80 s  | 152 MiB / 0.90 s  | 8.7× small, 1.1× fast |
| target2 | 50 k object instances | 49 MiB            | 89.7 MiB / 0.39 s  | 324 MiB / 1.82 s  | 3.6× small, 4.7× fast |
| target3 | 2 k user classes      | 23 MiB            | 44.4 MiB / **1.76 s** | 298 MiB / 1.18 s | 6.7× small, **0.67× (slower)** |
| target4 | 2 k preloaded classes | 42 MiB            | **42.0 MiB / 0.17 s** | 292 MiB / 0.86 s | 6.9× small, **5.1× fast** |

**target3 is the only shape where `i:m:dump` is currently losing to
`gcore`**, and it is the shape closest to a real-world PHP request
handler. The other three shapes (light CLI, heavy user data, preload)
are already wins on both axes or on size alone. A/B/C is predicted to
reclaim target3 while leaving the others unchanged or improved.
target4 in particular is already reli's strongest case and the
preload-safe E design leaves it untouched.

## Extension-heap-heavy measurement (target5) — where E becomes critical

The target3/target4 rounds made E look like polish: a ~2-3 MiB win
after A/B/C had done the heavy lifting. That conclusion was wrong. It
was an artefact of the test targets having a small glibc heap (~2-3
MiB). Real-world PHP workloads routinely drive glibc heap well past
100 MiB via C-extension state (DOMDocument, SQLite3, PDO result sets,
curl multi-handles, ...). target5 was built to cover that shape.

### target5 workload

- `DOMDocument::load()` of a ~20 MiB XML file with 100 000 `<item>`
  elements. libxml2 builds its tree via system `malloc`; every node,
  attribute, content string, and namespace lives in `[heap]`.
- `SQLite3(':memory:')` with a 50 000-row table. sqlite3 uses
  `sqlite3_malloc`, which is a thin wrapper over system `malloc` when
  PHP does not install a custom allocator (it does not).
- ~200 small `stdClass` instances on top, so the ZendMM side has
  something to analyse.
- `opcache.enable_cli=0` (we are not testing preload here).

Runtime footprint:

| metric   | value    |
|----------|----------|
| VmRSS    | 194 MiB  |
| RssAnon  | 174 MiB  |
| RssShmem | 0        |
| `[heap]` VMA | **167 MiB** |

### What `i:m:dump` and `gcore` produce

| metric    | `i:m:dump`         | `gcore`            |
|-----------|--------------------|--------------------|
| file size | **170.5 MiB**      | 177 MiB            |
| wall time | **1.49 s**         | 0.74 s             |

This is the **worst case for reli vs. `gcore`** out of anything measured
so far. The dump is only 4 % smaller than `gcore`'s core file and
runs **2× slower**. The whole point of reli's selective scan —
"the PHP pool is small compared to the rest of the heap, so we can
dump just the interesting bits" — is inverted when the rest of the
heap grows to 167 MiB of extension state that the current dumper
bulk-copies anyway.

### What the analyser actually reads

Querying `context_node_locations.region`:

| region          | count        | bytes        |
|-----------------|--------------|--------------|
| `outside`       | **8 320**    | **713 KiB**  |
| `zend_mm_heap`  | 1 606        | 395 KiB      |
| **total**       | **9 926**    | **1.08 MiB** |

The analyser reads **1.08 MiB** out of a **170.5 MiB** dump. That is
**99.4 % waste by bytes.** Of the reachable portion, the `outside`
breakdown is literally identical to target1/3/4 — same 205
ClassEntry, 1 811 ZendConstant, 731 PropertyInfo, 399 ClassConstant,
93 DefaultPropertiesTable, etc. Neither the 100 000 libxml2 nodes nor
the 50 000 SQLite3 rows contribute a single location to `outside`,
because the extension's C-level state is invisible to the analyser:
PHP objects for DOMDocument/SQLite3 only hold opaque pointers through
object handlers, and the analyser does not (and cannot) cross that
boundary without extension-specific adapters.

### What the analyser reads *from `[heap]` specifically*

| quantity                               | value                                  |
|----------------------------------------|----------------------------------------|
| `[heap]` VMA size                      | **167 MiB**                            |
| Analyser `outside` locations in `[heap]`| 8 014                                 |
| Analyser `outside` bytes in `[heap]`   | **692 KiB**                            |
| Address range actually touched         | `0x557b653854e0` – `0x557b655d3f20`    |
| Width of that range                    | **~2.4 MiB** out of 167 MiB            |
| Analyser coverage of `[heap]`          | **0.4 %**                              |

The address range is clustered at the very bottom of the VMA. That is
not an accident: `brk`-based glibc heaps grow upward, and PHP's MINIT
sequence runs **before** any extension allocates its bulky buffers, so
the persistent `pemalloc(..., 1)` metadata from MINIT lives in the
low addresses of `[heap]` while the later libxml2 / sqlite3 growth
lives above. A dumper that only wanted the MINIT metadata could
literally stop reading after the first ~2.5 MiB of `[heap]`.

### Projected impact of dropping `[heap]` bulk

| stage                            | dump size    | dump time    | vs `gcore` (177 MiB / 0.74 s) |
|----------------------------------|--------------|--------------|-------------------------------|
| current                          | 170.5 MiB    | 1.49 s       | 1.04× smaller, **2.01× slower** |
| A/B/C only (dedup + pagemap + stream) | ~165 MiB | ~1.3 s   | marginally smaller, still slower than `gcore` |
| **A/B/C + preload-safe E (drop `[heap]` bulk)** | **~10 MiB** | **~0.2–0.3 s** | **17× smaller, 3× faster** |

On target3, E was predicted to shave a couple of MiB off after ABC.
On target5, E saves **~155 MiB** on its own, and is **the only way to
beat `gcore` at all**. A/B/C by itself touches zero bytes of `[heap]`
and therefore leaves the 99 % waste intact.

### Revised judgement on E's priority

The earlier measurement rounds are consistent with each other, but
they all under-weighted E because they ran against targets with a
~2 MiB glibc heap. The earlier cross-target summary table recorded
`[heap]` bulk waste in the 2-3 MiB range per dump, which made E look
marginal. target5 breaks that pattern decisively:

| target  | `[heap]` VMA | `[heap]` reachable by analyser | `[heap]` waste |
|---------|--------------|-------------------------------|----------------|
| target1 | ~2.6 MiB     | ~540 KiB                      | ~2.1 MiB (80 %) |
| target3 | ~2.6 MiB     | ~540 KiB                      | ~2.1 MiB (80 %) |
| target4 | ~2.7 MiB     | ~540 KiB                      | ~2.2 MiB (80 %) |
| **target5** | **167 MiB** | **692 KiB**                   | **~156 MiB (99.6 %)** |

Extension-heap-heavy workloads (any PHP process that has actually
parsed XML, queried SQLite, run a curl multi-handle, opened a PDO
result set, built an image with gd, or processed an mbstring text)
sit closer to target5 than to target1/3/4. **E is not a polish, it is
a correctness fix for the "small dump" premise.**

### Walker sanity on target5

The walker's pointer filter is still "skip if inside chunks/huges/
SHM/binary-rw". On target5:

- `zend_mm_heap` locations span `0x7f76...000000` – `0x7f76...FFFFFF`
  (inside a ZendMM chunk). The walker skips these at the chunk-range
  check — they are already bulk-dumped.
- `outside` locations in `[heap]` span `0x557b653854e0` – `0x557b655d3f20`.
  These are outside every bulk-covered region, so the walker peeks
  them: ~8 000 peek reads, batched, ~10-15 ms of remote-read time.
- `outside` locations in the PHP binary RO/RW: ~306 additional items
  at `0x557b4f...`, also peeked.
- Total walker peek set: ~8 300 items / ~750 KiB of data.
- Walk time prediction: well under 50 ms end-to-end, including all
  the sub-pointer chasing inside class entries.

libxml2's 100 000 `xmlNode` structs, sqlite3's B-tree pages, result
row buffers, column name arrays, etc. — **none of them are on the
walker's reachable path from EG/CG**, so they contribute zero to the
peek set regardless of how much extension code was loaded or how long
the process has been running.

### Cross-target summary, final version

| target | shape | RSS | dump today | gcore today | reli vs gcore | E savings |
|--------|-------|------|-------------|--------------|----------------|-----------|
| target1 | light CLI | 32 MiB | 17.5 MiB / 0.80 s | 152 MiB / 0.90 s | 8.7× small, 1.1× fast | ~2 MiB |
| target2 | 50 k user object | 67 MiB | 89.7 MiB / 0.39 s | 324 MiB / 1.82 s | 3.6× small, 4.7× fast | ~2 MiB |
| target3 | 2 k user classes | 42 MiB | 44.4 MiB / **1.76 s** | 298 MiB / 1.18 s | 6.7× small, **0.67× (slow)** | ~2 MiB |
| target4 | 2 k preloaded classes | 61 MiB | 42.0 MiB / 0.17 s | 292 MiB / 0.86 s | 6.9× small, 5.1× fast | ~2 MiB |
| **target5** | **DOM+sqlite (extension heavy)** | **194 MiB** | **170.5 MiB / 1.49 s** | **177 MiB / 0.74 s** | **1.04× small, 0.50× (slow)** | **~156 MiB** |

### Updated implementation priority

1. **A/B/C** (dedup + unified pagemap + stream writer): unblocks
   target2 (too big) and target3 (too slow). Does nothing for target5.
2. **Preload-safe E** (drop `[heap]` bulk + anon-writable-mmap cleanup
   + metadata peek walker): unblocks target5 (too big **and** too
   slow). Also trims target1/2/3/4 by a couple of MiB each.

target5 changes the decision: **E is now the bigger unlock of the
two fixes by absolute bytes saved**, because it is the only one that
addresses the extension-heap case. A/B/C still has to land first
because E reuses the merged chunk interval set as the walker filter,
but E can no longer be deferred as "polish" — skipping it leaves the
tool structurally unable to beat `gcore` on any extension-heavy
workload, which is the majority of real PHP processes.
