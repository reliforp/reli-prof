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
