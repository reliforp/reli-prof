# Alpine (musl libc) Investigation Notes

## Status

Work in progress. PR #610.

## Test results

Two categories of failure on `php:8.4-cli-alpine`:

### 1. MemoryAddressNotInDumpException (7 tests)

All dump-based tests fail at `MemoryLocationsCollector.php:250`:

```
MemoryAddressNotInDumpException: no memory region found for address: 0x7f...f90 (size: 56)
```

- size=56 = `sizeof(zend_array)` — this is `$eg->function_table` deref
- All failing addresses are in the `0x7f...` range (shared library region)
- All end in `f90` — consistent with a fixed offset within a struct

The dump includes heap by default (`exclude_heap=false` → `include_heap=true`),
so anonymous writable mmap regions should be captured via
`findByNameRegex('^$')`.

#### Observations from Alpine PHP process maps

```
65430c400000-65430c4c2000 rw-p 01400000 ...  /usr/local/bin/php  (BSS)
65430c4c2000-65430c4e4000 rw-p 00000000 00:00 0                  (anon after BSS)
65434348c000-654343499000 rw-p 00000000 00:00 0  [heap]
7063e5ea6000-7063e60cd000 rw-p 00000000 00:00 0                  (large anon)
7063e6200000-7063e6400000 rw-p 00000000 00:00 0  [anon:zend_alloc]
7063e6400000-7063e647c000 rw-p 00000000 00:00 0                  (anon after ZendMM)
7063e65b-... many small anon rw-p from .so BSS ...
```

Key findings:
- Alpine's musl DOES have a `[heap]` region (brk-based)
- PHP 8.4 names ZendMM chunks as `[anon:zend_alloc]`
- `function_table` is a persistent alloc (`pemalloc(1)` = libc malloc)
- musl's malloc uses anonymous mmap for larger allocations
- These anonymous mmap regions have no name (empty), so
  `findByNameRegex('^$')` should match them

#### Hypothesis

The `function_table` pointer stored in `executor_globals` points
to an address that should be within one of the anonymous rw-p
regions. Either:

a) The MemoryDumper captures the region but the
   `DumpFileMemoryReader` fails to find it (region index issue), or

b) The specific page containing `function_table` is within a
   library's rw-p segment (e.g., a `.bss` area of a `.so`) rather
   than an anonymous region — and the dump logic skips named
   library segments that aren't the PHP binary itself.

### Root cause identified

CI debug output (PR #610) shows the failing address is inside a
**shared library's rw-p segment**:

```
failing address: 0x7efccbb07f90
maps entry:      7efccbb07000-7efccbb08000 rw-p 0003a000 ... /usr/lib/libcares.so.2.19.5
dump regions:    ← this VMA is NOT captured
```

`function_table` is allocated via `pemalloc(persistent=true)` →
musl `malloc` → musl places the allocation in a 4KB page at
`0x7efccbb07000`. This page shows up in `/proc/<pid>/maps` as
part of `libcares.so`'s writable segment (file_offset=0x3a000,
which is the `.bss`/`.data` area of the shared library).

**Why the MemoryDumper misses it:** The anonymous mmap filter
(`findByNameRegex('^$')`) only matches VMAs with empty names.
Library rw-p segments like `/usr/lib/libcares.so.2.19.0` have a
non-empty name. On glibc, persistent allocations land in `[heap]`
(captured) or anonymous mmap (captured). On musl, `malloc` may
reuse pages adjacent to (or within) library BSS regions, which
appear as named VMAs in maps.

### Verification

libcares.so's `.data` starts at file offset `0x3a000` (32 bytes),
`.bss` at `0x3a020` (88 bytes, ends at `0x3a078`). The failing
address `0x...f90` corresponds to file offset `0x3af90` — well
past the end of both sections. The 4KB page `0x3a000-0x3afff` is
mapped as a single VMA named `libcares.so`, but the space after
`0x3a078` is unused by libcares.

### Root cause confirmed in musl source

musl's dynamic linker (`ldso/dynlink.c:597-623`) explicitly
reclaims the unused tails of shared library writable pages:

```c
/* A huge hack: to make up for the wastefulness of shared libraries
 * needing at least a page of dirty memory even if they have no global
 * data, we reclaim the gaps at the beginning and end of writable maps
 * and "donate" them to the heap. */

static void reclaim(struct dso *dso, size_t start, size_t end)
{
    // ...
    __malloc_donate(base, base+(end-start));
}

static void reclaim_gaps(struct dso *dso)
{
    // For each PT_LOAD RW segment:
    // 1. Donate gap from page start to segment start
    // 2. Donate gap from segment end (bss) to page end
}
```

`__malloc_donate` (`src/malloc/mallocng/donate.c`) adds these
donated regions as free slots in mallocng's size-class freelists.
Subsequent `malloc()` calls can return addresses within these
donated regions — which map to library-named VMAs in
`/proc/pid/maps`.

For libcares.so: `.data` + `.bss` occupies 120 bytes of a 4KB
page. The remaining 3976 bytes (`0x3a078-0x3afff`) are donated
to malloc. PHP's `pemalloc(persistent=true)` for `function_table`
allocates a `zend_array` (56 bytes) from this donated pool,
landing at offset `0x3af90` within the page.

glibc does not do this — it uses `[heap]` (brk) or separate
anonymous mmap pages for malloc, so library VMAs never contain
unrelated allocations.

**Fix options:**

1. **Capture all rw-p VMAs** when `include_heap=true` — simplest,
   but increases dump size by including every library's BSS
2. **Capture rw-p VMAs that are anonymous** (inode=0, even if named
   by `PR_SET_VMA_ANON_NAME` or kernel heuristic) — more targeted
3. **Probe EG pointer targets** after initial dump: deref
   `eg->function_table`, `eg->class_table`, etc., check if their
   addresses are covered, and add the containing VMA if not —
   most precise but requires a second pass

### 2. NativeTraceCollectorTest (1 test)

```
At least one frame should have a symbol name
Failed asserting that an array is not empty.
```

Stack frames are collected (count > 0 assertion passes) but no
frame has a resolved symbol name.

#### Analysis

- Alpine PHP is `stripped` but has `.dynsym` (3887 symbols)
- `.eh_frame` and `.eh_frame_hdr` present (unwinding should work)
- `executor_globals`, `execute_ex`, `zend_execute` all in `.dynsym`
- Symbol count matches Debian (3891 vs 3887)
- Dynamic linker is `/lib/ld-musl-x86_64.so.1` (musl is both libc
  and dynamic linker in one binary)

Possible causes:
- Library path resolution via `/proc/<pid>/root/<path>` may fail
  for musl's combined ld/libc at `/lib/ld-musl-x86_64.so.1`
- PIE base address calculation from maps may differ
- The unwinder collects frames but address → binary → symbol
  resolution fails for all binaries

Need debug output showing: collected frame addresses, maps lookup
results, and which binary each frame resolves to.

## Alpine-specific characteristics

| Feature | glibc (Debian) | musl (Alpine) |
|---------|---------------|---------------|
| libc | `/lib/x86_64-linux-gnu/libc.so.6` | `/lib/ld-musl-x86_64.so.1` (combined) |
| dynamic linker | `/lib64/ld-linux-x86-64.so.2` | same as libc |
| `[heap]` region | yes (brk) | yes (brk) |
| malloc large allocs | anonymous mmap | anonymous mmap |
| ZendMM chunk naming | `[anon:zend_alloc]` (8.4+) | same |
| `.dynsym` symbols | ~3891 | ~3887 |
| `.eh_frame` | present | present |
| `.debug_*` | absent (stripped) | absent (stripped) |
| `libpthread` | separate `.so` | part of musl |
