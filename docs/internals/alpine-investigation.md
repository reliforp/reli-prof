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

Need to add debug output to CI to compare the failing address
against the actual dump regions.

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
