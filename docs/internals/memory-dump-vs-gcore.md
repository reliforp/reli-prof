# `inspector:memory:dump` vs `gcore`

## Summary

`i:m:dump` produces a minimal, analyzer-ready dump that beats `gcore`
on both size and speed for typical PHP workloads. The key techniques:

- **Pagemap residency filter**: reads `/proc/pid/pagemap` to skip
  non-resident pages in large mmap regions (opcache SHM, ZendMM chunks).
  This is the single biggest size win.
- **Selective region capture**: only ZendMM chunks, huge allocs, opcache
  SHM, compiler arenas, VM stacks, MAP_PTR table, and PHP binary
  RW segments are captured. The glibc `[heap]` and anonymous mmap
  regions are included by default but can be excluded with
  `--exclude-heap`.
- **Metadata peek walker**: in `--exclude-heap` mode, a lightweight
  walker reads MINIT-time metadata (function_table, class_table,
  constants, interned strings, static_members_table) from the live
  process and injects it into the dump.

### Results: 30 OSS library test cases

| Metric | `i:m:dump` (default) | `gcore` |
|---|---|---|
| Size (typical) | **2-50x smaller** | baseline |
| Size (extension-heavy) | **50x smaller** | baseline |
| Speed (Dompdf 435MB) | ~0.4s | ~0.7-2.9s |
| Analysis coverage | min=full on 17/17 cases | N/A |

## Architecture

```
Phase 1: Collect intervals
         ZendMM chunks, huge allocs, SHM, [heap]*, anon mmap*,
         PHP binary RW, VM stacks, compiler arenas,
         EG(objects_store).object_buckets, CG(map_ptr_base) table,
         BSS VMA
         (* = only with default full mode)

Phase 2: Sort + merge into disjoint intervals

Phase 3: Pagemap residency filter (unified, one pass over merged list)

Phase 4: MetadataPeekWalker (--exclude-heap only)
         Walks class_table, function_table, constants, interned_strings
         from the live process. Peeks uncovered metadata into the dump.
         Resolves static_members_table via MAP_PTR.
         Page-aligns peeks, then re-merges with Phase 3 output.

Phase 5: Bulk read + write
         memory_reader->read() per region, FFI::string, MemoryDumpWriter
```

## `--exclude-heap` usage guide

By default `i:m:dump` captures the full process memory including the
glibc `[heap]` and anonymous writable mmap regions. This gives complete
coverage: every byte the analyzer might need is present in the dump.

The `--exclude-heap` option skips these regions, producing a much smaller
and faster dump. The trade-off is that data allocated by C extensions via
system `malloc` (not PHP's ZendMM) will be absent from the dump. The
analyzer gracefully skips such unreachable data, so the dump is still
valid — it just covers PHP-managed memory only.

### When to use `--exclude-heap`

- **Recurring lightweight dumps** (`i:watch`, monitoring scripts):
  dumping every few seconds at 6 MB instead of 170 MB matters.
- **Large RSS from C extensions**: processes where `RSS >> memory_get_usage()`
  because libxml2, sqlite3, ImageMagick, curl multi, etc. are holding
  large C-heap allocations. Without `--exclude-heap`, the dump includes
  all that C data (which the analyzer cannot interpret anyway).
- **Disk/network constrained environments**: when dumps are shipped
  off-host for analysis, smaller is better.

### When NOT to use `--exclude-heap`

- **One-shot diagnosis**: when you are not sure what you are looking for,
  use the default (full) so nothing is lost.
- **Extension-state analysis**: if you specifically want to see how much
  memory libxml2 or sqlite3 is holding, you need the heap.
- **Fiber call-frame recovery**: Fiber C stacks live in anonymous mmap;
  `--exclude-heap` drops them.

### Typical size impact

| Workload shape | Default (full) | `--exclude-heap` | Saving |
|---|---|---|---|
| Pure PHP (ZendMM dominates) | 110 MB | 105 MB | ~5% |
| Extension-heavy (libxml2+sqlite3) | 173 MB | 6 MB | **97%** |
| gcore equivalent | 307 MB | — | — |

## Key implementation details

### Pagemap residency filter

`findResidentRuns()` reads `/proc/pid/pagemap` to identify which 4 KiB
pages within a region are physically present in RAM. Non-resident pages
(never faulted, or returned via `MADV_DONTNEED`) are excluded from the
dump. Page count uses ceiling division to ensure the last partial page
is always checked.

### MAP_PTR table

When opcache is enabled, class_entry fields like `static_members_table`
use indirect resolution via the MAP_PTR table (`CG(map_ptr_base)`).
The table itself lives in `[heap]` but is explicitly captured as an
interval so that the analyzer can resolve MAP_PTR offsets in offline
mode.

For PHP 8.0+ the biased base is `CG(map_ptr_base) + 1`; for PHP 7.4
the base is `CG(map_ptr_base)` directly.

### MetadataPeekWalker

In `--exclude-heap` mode, the walker reads engine-level root HashTables
from the stopped target process and adds uncovered addresses to the
"peek set". Scope:

- `EG(function_table)` — internal function structs + name strings
- `EG(class_table)` — class entry structs + methods + properties +
  constants + name strings + `static_members_table` (MAP_PTR resolved)
- `EG(zend_constants)` — constant name + value strings
- `EG(included_files)` — filename strings
- `CG(interned_strings)` — interned string keys
- Opcache SHM interned string buffer walk

Peeks are page-aligned (4 KiB boundaries) before merge, so nearby
allocations on the same page are captured for free.

### EmitClassTableJob resilience

The analyzer's class_table walk uses a plain `for` loop over arData
(not a generator) with per-bucket `try-catch`. A single unreadable
class entry cannot abort the walk of remaining classes. This is critical
for `--exclude-heap` dumps where some class data may be in uncaptured
`[heap]` regions.

### Symbol resolution: findSymbol vs findGlobals

`findGlobals()` enters the TSRM resolution path on ZTS builds, looking
for `{name}_offset` / `{name}_id` symbols. This is correct for
TSRM-managed globals (executor_globals, compiler_globals) but wrong
for plain BSS symbols like `zend_one_char_string` that are
process-global regardless of ZTS.

`findSymbol()` resolves ELF symbols directly without TSRM indirection.
Used for interned string array symbols in `--exclude-heap` mode.
