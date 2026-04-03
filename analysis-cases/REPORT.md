# reli-prof PHP Memory Analysis Report

**Date:** 2026-04-03
**Tool:** reli-prof (reliforp/reli-prof)
**Environment:** PHP 8.4.19, Linux x86_64, NTS

## Summary

Five real-world PHP memory issues were collected from GitHub, reproduction scripts
were created, and each was analyzed using reli-prof's `inspector:memory` command.
This report evaluates the tool's usefulness and documents issues found during testing.

### Heap Analysis Coverage

At the start of testing, all cases showed 0–2% heap analysis coverage due to a bug.
The root cause was identified during this evaluation and subsequently fixed
(see Appendix for details). Final results after fix:

| Case | Coverage |
|------|----------|
| 1: Worker leak | 87.8% |
| 2: Error duplication | 98.8% |
| 3: Chunk fragmentation | 99.5% |
| 4: number_format | 94.0% |
| 5: ORM hydration | 91.2% |

---

## Case 1: FrankenPHP Worker Mode Memory Leak

**Source:** [php/frankenphp#1797](https://github.com/php/frankenphp/issues/1797)

**Problem:** FrankenPHP worker mode accumulates memory across requests when using
Symfony's `dump()`/`dd()`, leading to "Allowed memory size exhausted".

**Reproduction:** `VarCloner` deep-clones objects per request, `ProfilerStorage`
accumulates them, and `RequestContext` forms a linked list.

### reli-prof Results

```
memory_get_usage(): 1.38 GB | Heap: 1.22 GB (87.8% analyzed)

[HIGH] dominant_type: ZendString (99.9% of heap)
[HIGH] bottleneck_path: VarCloner::cloneVar:23::$result[0] (881.56 MB)
[HIGH] choke_point: VarCloner::cloneVar:23::$result[4] (172.85 MB)
[HIGH] dominant_class: RequestContext: 14 instances (91.7% of objects)
[LOW]  dedup_candidate: 700 copies x 176.65 KB (100% identical) = 120.76 MB

Root Blame: call_frames 97.7%, global_variables 1.3%
Call Stack: str_repeat → VarCloner::cloneVar → <main>
```

### Evaluation

| Aspect | Rating |
|--------|--------|
| Problem identification | **Excellent** — immediately identified `VarCloner::cloneVar:23::$result` as bottleneck |
| Root cause inference | **Excellent** — detected 700 identical copies (dedup_candidate), suggesting clone bloat |
| Remediation hints | **Good** — flagged "unbounded accumulation", suggested streaming |
| Call stack | **Excellent** — accurately captured `str_repeat → VarCloner::cloneVar → <main>` |

---

## Case 2: PHPStan Error Duplication OOM

**Source:** [phpstan/phpstan#13813](https://github.com/phpstan/phpstan/issues/13813)

**Problem:** PHPStan exponentially duplicates the same error under certain control
flow patterns, exhausting memory. A single undefined variable error gets recorded
hundreds of thousands of times.

**Reproduction:** `TypeAnalyzer` recursively analyzes branches, generating the same
`AnalysisError` per branch. `ErrorCollector` accumulates without deduplication.

### reli-prof Results

```
memory_get_usage(): 38.43 MB | Heap: 37.98 MB (98.8% analyzed)

[HIGH] dominant_class: AnalysisError: 19,680 instances x 104 B (100% of objects)
       "Unbounded accumulation — likely a loop without limit"
```

### Evaluation

| Aspect | Rating |
|--------|--------|
| Problem identification | **Good** — detected accumulation of 19,680 AnalysisError instances |
| Root cause inference | **Good** — correctly flagged "Unbounded accumulation — likely a loop without limit" |
| Remediation hints | **Good** — suggested checking if count scales with input size |
| Detail level | **Lacking** — only one Finding; no breakdown of arrays/strings consuming the remaining memory |

---

## Case 3: Zend MM Chunk Fragmentation

**Source:** [php/php-src#13599](https://github.com/php/php-src/issues/13599)

**Problem:** Allocating many ~1MB strings causes ZendMM chunk fragmentation.
`memory_get_usage()` reports only 50% of actual consumption because each 1MB
string requires a 2MB chunk, and small pinning objects prevent chunk release.

**Reproduction:** 30 x 1MB strings with small `stdClass` objects interleaved to
pin chunks in memory.

### reli-prof Results

```
memory_get_usage(): 15.56 MB | memory_get_usage(true): 32.00 MB
Heap: 15.48 MB (99.5% analyzed)
(Script self-report: Waste ratio 51.4%)

[HIGH] dominant_type: ZendString (91.2% of heap)
[HIGH] bottleneck_path: global_variables[strings][15] (15.05 MB)
[HIGH] choke_point x10: each strings[N] holds 1 MB
[HIGH] dominant_class: stdClass: 1,500 instances (chunk pinners)
[MEDIUM] large_array: global_variables[strings] (15 elements, 15 MB)
[LOW]  empty_object: stdClass 1,500 instances (no properties)
```

### Evaluation

| Aspect | Rating |
|--------|--------|
| Problem identification | **Excellent** — individually identified 15 x 1MB strings with choke_points |
| Fragmentation detection | **Lacking** — no direct detection of the reported-vs-real memory gap |
| Pin detection | **Good** — detected 1,500 empty stdClass objects, hinting at chunk pinning |
| Remediation hints | **Partial** — suggested SplFixedArray but not `gc_mem_caches()` |

---

## Case 4: Unbounded Allocation (number_format pattern)

**Source:** [php/php-src#17384](https://github.com/php/php-src/issues/17384)

**Problem:** `number_format()` with an excessively large `$decimals` parameter
allocates unbounded memory without validation.

**Reproduction:** User-derived large format parameters cause massive string
accumulation in an array.

### reli-prof Results

```
memory_get_usage(): 15.71 MB | Heap: 14.78 MB (94.0% analyzed)

[HIGH] dominant_type: ZendString (92.5% of heap)
[HIGH] dominant_class: ReportGenerator: 1 instance
[LOW]  large_string: formattedData[0][amount]: "000000..." (48.85 KB)
[LOW]  large_string: formattedData[0][rate]: "000000..." (48.85 KB)
... (many similar formattedData entries)
```

### Evaluation

| Aspect | Rating |
|--------|--------|
| Problem identification | **Good** — detected ZendString dominance, enumerated large strings in formattedData |
| Pattern detection | **Good** — displayed repeating patterns within the array |
| Remediation hints | **Lacking** — did not flag missing input validation (arguably out of scope) |
| Practicality | **Good** — accurately pinpointed memory consumption locations |

---

## Case 5: Eloquent-like ORM Hydration

**Source:** [firefly-iii/firefly-iii#9864](https://github.com/firefly-iii/firefly-iii/issues/9864)

**Problem:** Firefly III exhausts memory loading 4,000 transactions with eager-loaded
relations after upgrading to v6.2.7. Regression from the previous version.

**Reproduction:** 4,000 `Transaction` objects with `Account`, `Category`, `Tag`
relations eager-loaded, each Transaction carrying 3 Carbon date objects.

### reli-prof Results

**Note:** `-f report` direct output produces empty Findings for this case.
Use the sqlite two-step approach (`-f sqlite3 -o x.db` → `inspector:memory:report x.db`)
for detailed results. See Issue 2 below.

```
memory_get_usage(): 45.85 MB | Heap: 41.82 MB (91.2% analyzed)

[HIGH] bottleneck_path: objects_store->10520->relations[tags][0] (85.57 MB)
[HIGH] choke_point: ObjectsStoreMemoryLocation (512 KB) holds 85.57 MB via 41,978 children
[HIGH] choke_point: global_variables[transactions] (62.51 KB) holds 30.91 MB via 4,000 children

[MEDIUM] structural_duplicate: Tag: 13,978 identical shapes x 120 B = 1.60 MB
[MEDIUM] structural_duplicate: Account: 8,000 identical shapes x 120 B = 937 KB
[MEDIUM] structural_duplicate: Carbon: 8,000 identical shapes x 104 B = 812 KB
[MEDIUM] structural_duplicate: Category: 4,000 identical shapes x 120 B = 468 KB
[MEDIUM] structural_duplicate: Transaction: 3,999 identical shapes x 120 B = 468 KB

[LOW] dedup_candidate: "translation_string" 602,668 copies x 42 B (100% identical) = 24.14 MB
[LOW] dedup_candidate: Transaction::$dateFormat 145,911 copies x 35 B (100% identical) = 4.87 MB

Root Blame: objects_store 98.2%
shared_fanin: dateFormat -> 2 targets (72,956 refs each)
shared_fanin: relations -> 3 targets (43,301 refs each)
```

### Evaluation

| Aspect | Rating |
|--------|--------|
| Problem identification | **Excellent** — immediately identified objects_store as 98.2% of memory |
| Class analysis | **Excellent** — detected structural_duplicates for Tag/Account/Carbon/Category |
| Remediation hints | **Excellent** — suggested flyweight/sharing pattern, flagged dateFormat sharing gap |
| Dedup detection | **Excellent** — "translation_string" 600K copies = 24MB, dateFormat 146K copies = 4.8MB |
| shared_fanin | **Excellent** — detected dateFormat with 72,956 refs to only 2 targets |

---

## Open Issues

### Issue 1: Unhelpful error message on process exit

When the target process exits before analysis completes, an internal TypeError stack
trace is displayed instead of a clear message like "Target process (PID: XXXX) has exited".

**Location:** `src/Lib/Process/MemoryMap/ProcessModuleMemoryMap.php:82`

### Issue 2: `-f report` produces empty Findings for large cases

For Case 5 (48MB, ~40K objects), `-f report` outputs only the Overview section with
no Findings. The sqlite two-step (`-f sqlite3` → `memory:report`) produces detailed
results for the same data.

The likely cause is that streaming mode uses `MemoryLocations::createLightweight()`
which stores only addresses, discarding size information needed for report generation.

**Location:** `src/Lib/PhpProcessReader/PhpMemoryReader/MemoryLocation/MemoryLocations.php:32-44`

**Workaround:** Use sqlite two-step for large-scale analysis.

### Issue 3: No direct fragmentation detection

When `memory_get_usage()` and `memory_get_usage(true)` diverge significantly
(as in Case 3: 15MB vs 32MB), ZendMM chunk fragmentation is the likely cause.
The tool does not automatically detect or report this gap.

**Suggestion:** Add a fragmentation indicator to Overview:
```
Fragmentation: 51.4% (reported: 15.56 MB, real: 32.00 MB)
  → Consider gc_mem_caches() after freeing large allocations
```

### Issue 4: dedup_candidate lacks source path identification

Dedup detection is excellent at finding duplicated data and estimating savings,
but cannot identify which code path is **generating** the copies.

---

## Resolved Issues

### [RESOLVED] Heap analysis percentage near zero in streaming mode

**Root cause:** `PdoContextTreeSink` received no `RegionBoundaries` during streaming,
so the `region` column was NULL for all rows. Additionally, `queryRegionSums` summed
duplicate rows (same object reachable via multiple paths), inflating past 100%.

**Fix (3 stages):**
1. `backfillRegions()` — UPDATE region column after collectAll() using RegionBoundaries
2. Address deduplication in `queryRegionSums` via subquery GROUP BY
3. Covering index `(run_id, region, address, size)` for fast dedup queries

**Details:**
- Chicken-and-egg: RegionBoundaries requires chunk/huge data from collectAll(),
  but the sink INSERTs during collectAll() → region stays NULL
- >100% issue: Same object reachable via multiple paths (e.g., global_variables +
  objects_store) creates duplicate rows. Case 5 had 3.3x duplication factor.
  After address dedup: all cases converged to 87–99%.

---

## Overall Assessment

### Strengths

1. **Out-of-process analysis** — inspects memory without modifying the target process
2. **Bottleneck identification** — `bottleneck_path` immediately pinpoints the heaviest memory path
3. **Choke point detection** — finds small objects retaining large memory subtrees
4. **Dedup candidate detection** — identifies duplicated data with savings estimates
5. **Structural duplicate detection** — finds identical object shapes, suggests flyweight pattern
6. **Dominant class/type ranking** — memory breakdown by class and type
7. **Root Blame Allocation** — traces memory ownership from roots
8. **shared_fanin detection** — flags abnormal reference concentration patterns
9. **Call stack snapshots** — captures the call stack at the moment of memory capture

### Weaknesses

1. **Streaming mode limitation** — `-f report` produces empty Findings for large cases (sqlite workaround available)
2. **Error handling** — unhelpful error on target process exit
3. **No fragmentation detection** — does not flag ZendMM chunk fragmentation

### Usefulness Score by Case

| Case | Issue Type | Score | Note |
|------|-----------|-------|------|
| 1 | Worker leak | **9/10** | Accurately identified bottleneck, duplicates, and call stack |
| 2 | Error duplication | **7/10** | Detected class accumulation, somewhat lacking in detail |
| 3 | Chunk fragmentation | **6/10** | Found strings and pin objects, but not fragmentation itself |
| 4 | Unbounded alloc | **7/10** | Pinpointed consumption locations and patterns |
| 5 | ORM hydration | **9/10** | structural_duplicate/dedup/shared_fanin extremely useful (via sqlite) |

**Overall:** reli-prof excels at analyzing accumulation-type memory leaks (Case 1) and
ORM object bloat (Case 5). The ability to extract this level of detail from a running
process without any modification is remarkably useful. Adding fragmentation detection
and improving `-f report` for large cases would complete coverage of all common
PHP memory issue patterns.

---

## Appendix: Heap Analysis Percentage Fix History

| Stage | Case 1 | Case 2 | Case 3 | Case 4 | Case 5 |
|-------|--------|--------|--------|--------|--------|
| Before fix | 0.0% | 0.8% | 2.0% | 2.0% | 0.7% |
| After backfill | 80.7% | 201.9% | 99.9% | 94.6% | 284.4% |
| After address dedup | **87.8%** | **98.8%** | **99.5%** | **94.0%** | **91.2%** |
