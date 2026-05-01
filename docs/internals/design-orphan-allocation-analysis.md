# Orphan Allocation Analysis — Design Note

## Motivation

Reli's current memory analysis can pinpoint leaks whose roots live inside
the PHP object graph (objects_store, function_table, class_table, symbol
tables, etc.). It struggles when the leak is **C-extension-side emalloc'd
memory that is not reachable from any of those roots**, because such
allocations show up as "unanalyzed" bytes with no node, no type, and no
shape — only an `Analyzed: X%` figure that goes down.

This was demonstrated end-to-end while investigating amphp/file issue #88
(uv driver leak). The actual workflow that landed on the culprit looked
like this:

1. `inspector:memory:report` on T1 / T2 → notice `Analyzed 62.1% → 45.8%`
2. Diff `Type Breakdown` between T1/T2 by hand → confirm typed delta = 0
3. Run `inspector:memory:dump:inspect` twice → text-diff region maps
   (shifted indexes had to be stripped, then `comm -23` on sorted
   addresses) → find `+728 KB at 0x7f9b92200000`
4. Read `/proc/<pid>/mem` directly with `dd | od` to see the leaked
   bytes; identify a `~480 byte` then later a `~192 byte` repeating
   structure
5. Manually decode the bytes against `struct stat` / `uv_stat_t` /
   `Bucket` / `zval` layouts; resolve `.text` pointers to PHP binary
   symbols by hand
6. After several wrong hypotheses, identify the leak as **per-instance
   property name `Bucket` entries** in object property tables

Most of those steps are mechanical and could be done by the tool. This
document proposes the smallest set of features that would have collapsed
the workflow above into running `inspector:memory:compare` once and
reading the output.

## Non-goals

- Building a full `valgrind`-style memcheck. The aim is to surface
  unaccounted allocations and label them with confidence-scored
  shape detectors, not to track allocation lifetimes.
- Decoding C-extension-specific structs in core. Extension-specific
  detectors (`uv_fs_t`, `curl_easy`, libxml node, …) belong in a
  contrib registry; only POSIX / glibc / PHP-core types ship in core.
- Replacing the existing root-traversal analysis. The new path is
  complementary: bin walker enumerates allocations from the allocator
  side; root traversal continues to claim what it can.

## Architecture

```
                        ┌────────────────────────┐
   live target /        │   ZendMM bin walker    │
   rdump file    ────►  │   (chunk page_map +    │
                        │    free_slot freelist) │
                        └──────────┬─────────────┘
                                   │ slot list
                                   │ (addr, size, bin_id)
                                   ▼
                        ┌────────────────────────┐
                        │   detector pipeline    │
                        │   (PHP / POSIX / glibc)│ ◄── symbol map
                        │   + negative-evidence  │     (existing)
                        └──────────┬─────────────┘
                                   │ labeled allocations
                                   ▼
                        ┌────────────────────────┐
                        │   rmem sidecar tables  │
                        │   ・bin_slots          │
                        │   ・region_map         │
                        │   ・detected_shapes    │
                        └──────────┬─────────────┘
                                   │
                ┌──────────────────┼──────────────────┐
                ▼                  ▼                  ▼
          memory:report     memory:compare     rmem:query --bin
          (new sections)    (new deltas)       (drill-down)
```

Key design constraint: rmem stays the single sink. analyze produces it
once; report / compare / query consume it. No new file formats; new
data lives as rmem sidecar tables (same pattern as `.rmem.derived`).

## A. ZendMM bin walker

### Goal

Enumerate every live allocation in ZendMM, grouped by bin size, with
addresses preserved for drill-down.

### Inputs

- `zend_mm_heap*` — already located by reli at cold-attach time
  (`PhpGlobalsFinder` / `findZendMmMainChunk`)
- `zend_mm_chunk*` linked list — followed from `heap->main_chunk`

### Walk

```
for each chunk in heap:
    free_set = collect_freelist(heap->free_slot[*], chunk)
    for each page in chunk->map[ZEND_MM_PAGES]:
        kind = chunk->map[page]
        if kind == 0: skip (free page)
        elif kind & ZEND_MM_IS_LRUN:
            n = ZEND_MM_LRUN_PAGES(kind)
            emit Slot(addr=chunk + page*4096, size=n*4096, bin=LARGE)
            advance n pages
        elif kind & ZEND_MM_IS_SRUN:
            bin_num = ZEND_MM_SRUN_BIN_NUM(kind)
            slot_size = bin_data_size[bin_num]
            for slot in run:
                if slot.addr ∉ free_set:
                    emit Slot(addr, slot_size, bin_num)
```

Subtleties:

- `free_slot[bin]` is a singly-linked list whose link pointer lives at
  the start of each free slot. The walker has to traverse the list
  for every bin first, build `free_set`, then sweep the run.
- `cached_chunks` are free.
- ZendMM chunk size is fixed (2 MiB, 512 pages of 4 KiB). Bin sizes
  follow the standard PHP table (8, 16, 24, 32, 40, 48, 56, 64, 80,
  96, 112, 128, 160, 192, 256, 320, 384, 448, 512, 640, 768, 896,
  1024, 1280, 1536, 1792, 2048, 2560, 3072 — 30 bins).

### Existing reuse

The `zendmm_heap_fragmentation_high` finding already walks `chunk->map`
to compute free-page space. The new walker is the same traversal with
slot-level emission added; both should share the underlying iterator.

### Outputs (rmem)

Both stored directly in rmem, always populated (no opt-in):

- `bin_histogram`: `{ bin_id → { count, total_bytes } }`
- `bin_slots`: `{ bin_id → address[] }` (drill-down)

### Sizing

Worst case for a daemon with hundreds of MiB ZendMM is on the order
of 10M slots × 8 B = ~80 MiB raw uint64 list. Acceptable to land in
rmem as-is; rmem is already in this size range for non-trivial
captures.

Compression knobs available if needed later:

- vbyte / delta-encode addresses inside a chunk (consecutive slots in
  a small run differ by a constant) — 3-5× in practice
- Skip per-bin slot lists for bins below a threshold count, keeping
  histogram only

Default is no compression; revisit if profiling shows a real problem.

## B. compare deltas

### B.1 Type Breakdown delta

The information already exists in analyze output but is not surfaced
by compare. Join the two `Type Breakdown` maps, compute deltas, sort
by absolute delta, print top N.

When the join produces all-zero deltas, **emit the row anyway** with
an explicit note `(no typed allocation grew)` — that is the signal
that the leak is not in PHP-managed objects.

### B.2 Region map delta

Currently lost: rdump → rmem drops the region map. Two options:

- (a) Preserve region map as rmem sidecar at analyze time
- (b) Require both rdumps when comparing

(a) is preferred — keeps the rmem-only workflow. Region maps are small
(few hundred entries × 32 bytes), negligible cost.

Compare output groups regions into:

- **Added**: present in target, absent in baseline
- **Grown**: same address, larger size
- **Shrunk**: same address, smaller size
- **Removed**: present in baseline, absent in target

### B.3 Bin histogram delta

Falls out of A. Top N bins by `|count_delta|`, with size annotation:

```
bin    size  baseline  target  delta
─────────────────────────────────────
bin_3  32 B   ...        ...    +16,041
bin_8  192 B  567        567        0
```

### B.4 Unaccounted delta finding

Synthetic finding triggered when:

```
heap_usage_delta > threshold AND sum(type_breakdown_delta) ≈ 0
```

(threshold ≈ 100 KiB or 1% of heap, whichever is smaller). Output:

```
[HIGH] Heap +Δ but typed delta = 0
       Likely orphan / C-extension emalloc.
       See: bin histogram delta, region map delta.
```

This is the signpost that takes the user to B.3 / B.2.

## C. Detector pipeline

### Interface

```php
interface AllocationDetector
{
    public function name(): string;

    public function apply(Slot $slot, DetectorContext $ctx): ?Detection;
}

final class Detection
{
    public function __construct(
        public readonly string $label,
        public readonly Confidence $confidence, // LOW / MED / HIGH
        public readonly array $fields,           // structured decoded fields
        public readonly ?string $hexdumpExcerpt, // first ~64 bytes
    ) {}
}

final class DetectorContext
{
    public function __construct(
        public readonly SymbolMap $symbols,         // existing reli symbol map
        public readonly MemoryReader $reader,       // can reach outside the slot
        public readonly ReachableAddressSet $reachable,
    ) {}
}

final class Slot
{
    public function __construct(
        public readonly int $address,
        public readonly int $size,
        public readonly int $binId,
        public readonly string $first64Bytes,
    ) {}
}
```

### Negative-evidence rule

```php
final class ReachabilityFilter
{
    /**
     * Drop detections whose slot is reachable through the standard PHP
     * roots (objects_store, function_table, class_table, symbol_table,
     * interned_strings). Such allocations are not "orphan"; they are
     * normal data that detector heuristics matched by accident.
     */
    public function isLikelyOrphan(Slot $slot): bool { ... }
}
```

Run as the last step of the pipeline, before persisting Detection
records. This single rule eliminates the largest class of false
positives (function-pointer-bearing structs that look like leaked
op_arrays but are actually live compiled bytecode).

### Built-in detectors

| Detector | Primary signal | Confidence |
|---|---|---|
| `FunctionPointerDetector` | 8-byte values that fall inside any loaded module's `.text`; resolved to symbol via `SymbolMap` | HIGH (when symbol resolves) |
| `ZendStringDetector` | `gc + hash + len` at +0/+8/+16, NUL terminator at `+24+len` | HIGH |
| `BucketDetector` | 32 B; +0..+15 looks like a `zval` (valid `type` byte 0..21); +16 looks like a hash; +24 → readable `zend_string` | HIGH |
| `ZendOpDetector` | 32 B; first 8 B is an opcode-handler symbol (cluster of related `.text` addresses) | MED |
| `StatDetector` | 144 B; `S_IFMT` set in mode; uid/gid reasonable; nlink ≥ 1; timestamps within ±50 years | HIGH |
| `TimespecDetector` | `(sec, nsec<1e9)` pairs in sequence | LOW (use as evidence for higher detectors) |
| `SockaddrDetector` | `sa_family` is an `AF_*` enum | HIGH |
| `IovecDetector` | `(ptr, len)` pairs with valid pointers and reasonable lengths | MED |
| `GlibcMallocChunkHeader` | `size_with_flags` low-3-bit pattern + `prev_size` consistency | MED |

PHP / POSIX / glibc only in core, registered statically. Extension-
specific detectors (`uv_fs_t`, `curl_easy`, `xmlNode`, `pdo_stmt_dbh`,
`event_t`, …) are out of scope for the initial implementation; the
packaging story (in-tree contrib vs. Composer-discovered plugin) is
deferred until external authors need it.

### Pipeline

```
slots from bin walker
    ↓
group by fingerprint (first 24 bytes)  ← run detectors once per group
    ↓
each detector applied → candidate Detections
    ↓
ReachabilityFilter drops reachable matches
    ↓
pick best per slot:
    1. confidence: HIGH > MED > LOW
    2. specificity: more specific type wins on tie
    3. keep up to 3 candidates if all HIGH (multi-label)
    ↓
persist to rmem.detected_shapes
```

False-positive control is grounded in three principles:

1. **Confidence visibility**: every Detection is rendered with its
   confidence. No silent inference.
2. **Hex always available**: the slot's first 64 bytes are kept in the
   Detection so the user can sanity-check.
3. **Reachability gate**: the negative-evidence filter is mandatory,
   not optional.

## D. Periodicity detection

Implemented as a post-processing step on bin walker output, not a
separate signal-processing pass:

```
for each bin:
    for each chunk:
        slots = ordered_by_addr(slots_in_bin_in_chunk)
        groups = group_by(slots, key=fingerprint(slot.first_24_bytes))
        for group:
            stride = mode of consecutive slot.addr deltas
            count  = len(group)
            if count ≥ THRESHOLD:
                emit PeriodicGroup(bin, fingerprint, count, stride)
```

The "stride 192, count 565" / "stride 32, count 16,000" patterns from
the issue #88 investigation surface directly here. No FFT / autocorr
needed; the bin walker already gives us enough structure.

Surfaced in `memory:report` as a `Periodic groups` section.

## E. Drill-down commands

### `rmem:query --bin=N`

Existing query gains:

```
rmem:query <file> --bin=N [--sample=K]
```

Lists all addresses in bin N. With `--sample=K`, prints the first K
with hexdump.

### `inspector:memory:peek` (new)

Raw byte read from a live target or rdump:

```
inspector:memory:peek -p PID --address=0x... --length=256
inspector:memory:peek --rdump=t2.rdump --address=0x... --length=256
```

Prints hexdump + ASCII. This is the bottom of the drill-down funnel
when detectors don't quite reach a confident label.

## Output integration

### `inspector:memory:report` — new sections

```
=== Bin Histogram ===
  bin   size    count   total
  ─────────────────────────────
  bin_3  32 B  23,041  720.0 KB
  bin_8  192 B    567  106.3 KB
  …

=== Unclassified slots by inferred shape ===
  size  count   fingerprint   inferred shape                   confidence
  ──────────────────────────────────────────────────────────────────────
  32 B  16,042  ab12cd…       Bucket(zval IS_STRING,           HIGH
                              key→zend_string @0x…
                              "…UvFile…poll")
  192 B    234  00…0e24…      struct stat (mode 0644,          HIGH
                              size 0, blksize 4096)
  64 B    331   07310b…       orphan? (.text ptr but           MED
                              not reachable)

=== Periodic groups ===
  bin     stride  count    fingerprint    sample addr
  ──────────────────────────────────────────────────────
  32 B       32  16,000   ab12cd…         0x7f…1145000
  192 B     192     567   00…0e24…        0x7f…1578000
```

### `inspector:memory:compare` — additional deltas

```
=== Type Breakdown Delta ===
  (no typed allocation grew)

=== Region Map Delta ===
  Added:
    +728.0 KiB at 0x7f9b92200000

=== Bin Histogram Delta ===
  bin   size  baseline  target   delta
  ────────────────────────────────────────
  bin_3  32 B    7,000  23,041  +16,041
  bin_8  192 B     567     567        0

=== Findings ===
  [HIGH] Heap +Δ but typed delta = 0 — likely orphan / C-extension
         emalloc. See bin histogram delta and region map delta.
```

## Decisions

1. **Bin walker cost on large heaps.** ~10M slots for hundreds of MiB
   of ZendMM is acceptable; the walker always runs and always emits
   the full slot list. Compression is a future knob, not a v1 concern.
2. **rmem size.** Slot lists land directly in rmem alongside
   `bin_histogram`. No sidecar file; rmem already sits in the same
   order of magnitude for non-trivial captures.
3. **Best-detection selection rule.** Confidence-first, then
   specificity on tie. Bias toward fewer wrong labels at the cost of
   occasional generic ones.
4. **PHP version coverage.** ZendMM layout is stable across 7.x / 8.x
   in shape but the chunk-map encoding macros differ. Branch via
   `PhpVersionDetector` (already used elsewhere). FrankenPHP-style
   embedded SAPIs are in scope (see CLAUDE.md notes on libphp
   preemption).

## Deferred

- **Detector plugin packaging.** In-tree contrib vs. Composer
  discovery is left open; revisit when external authors actually
  ask for it. For now built-in detectors are registered statically.

## Phasing

### Phase 1 — collapse 80% of the manual workflow

- A.1 ZendMM bin walker (histogram + slot list, opt-in)
- B.1 Type Breakdown delta in compare
- B.2 Region map delta in compare (with rmem sidecar)
- D Periodicity detection on bin walker output

After Phase 1: today's manual workflow ("two `dump:inspect` runs +
sort + comm + od") collapses to a single `compare` invocation that
prints the right-shaped output, but the user still has to interpret
the bytes.

### Phase 2 — automate culprit identification

- A.2 Promote unclassified slots to `UnclassifiedAllocation` virtual
  nodes in rmem (lets the existing compare node-diff path see them)
- B.3 Bin histogram delta in compare
- B.4 "Heap +Δ, typed delta=0" finding
- C.1 `FunctionPointerDetector` + `ReachabilityFilter`
- C.2 `ZendStringDetector` / `BucketDetector` / `ZendOpDetector`
- E.2 `inspector:memory:peek`

After Phase 2: the issue #88 investigation outputs
`bin[32B] +16,000 BucketDetector → key="…UvFile…poll"` directly,
which is the actual conclusion of the manual session.

### Phase 3 — generality

- C.3 POSIX / glibc detectors (`StatDetector`, `SockaddrDetector`,
  `IovecDetector`, `TimespecDetector`, `GlibcMallocChunkHeader`)
- E.1 `rmem:query --bin`

Extension-specific detectors and the plugin packaging question are
deferred until there is real demand.

## Validation

amphp/file issue #88 becomes the regression test for Phases 1-2:

- Add `tests/Regression/AmphpFileIssue88Test.php` (group:
  `target-version`, requires ext-uv).
- Test fixture: scripted daemon, baseline + post-load dumps captured
  with `inspector:memory:dump`.
- Assertions:
  - Phase 1: compare output contains a non-zero `bin[32 B]` delta
    and the "heap grew but typed delta = 0" finding.
  - Phase 2: compare output contains
    `BucketDetector ... key contains "UvFile"` for the leaked bin.

If a future change to detectors / walker / compare breaks the issue
#88 chain, this test fails loudly with a recognizable diff.
