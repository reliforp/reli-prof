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

[HIGH] bottleneck_path: objects_store->3->context[branch][subBranches][0] (65.42 MB)
[HIGH] dominant_class: AnalysisError: 19,680 instances x 104 B (100% of objects)
       "Unbounded accumulation — likely a loop without limit"
[MEDIUM] property_scaling: AnalysisError::$context: 9,840 copies x 6.50 KB = 62.44 MB
[MEDIUM] choke_point + large_array: $scope[branches] subtree (4.13 MB)
```

### Evaluation

| Aspect | Rating |
|--------|--------|
| Problem identification | **Excellent** — identified 19,680 AnalysisError instances + 65 MB bottleneck path |
| Root cause inference | **Excellent** — "Unbounded accumulation" + property_scaling shows $context at 6.5 KB/instance |
| Remediation hints | **Good** — per-instance property analysis suggests lazy init or default values |
| Detail level | **Good** — 34 Findings covering bottleneck, class accumulation, property scaling, and arrays |

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

### Issue 2: `-f report` produces fewer Findings than sqlite two-step

Systematic comparison across all 14 cases revealed significant gaps:

| Case | memory_get_usage | `-f report` | sqlite | Delta |
|------|-----------------|-------------|--------|-------|
| 1 | 1.38 GB | 28 | 29 | +1 |
| **2** | **38 MB** | **1** | **34** | **+33** |
| 3 | 15 MB | 32 | 32 | 0 |
| 4 | 15 MB | 19 | 19 | 0 |
| **5** | **45 MB** | **0** | **29** | **+29** |
| **6** | **26 MB** | **1** | **14** | **+13** |
| 7 | 7 MB | 15 | 15 | 0 |
| 8 | 6 MB | 7 | 7 | 0 |
| 9 | 3 MB | 14 | 14 | 0 |
| 10 | 4 MB | 11 | 11 | 0 |
| 11 | 3 MB | 8 | 8 | 0 |
| 12 | 326 MB | 2 | (too large) | ? |
| 13 | 4 MB | 18 | 18 | 0 |
| 14 | 23 MB | 19 | 19 | 0 |

Cases 2, 5, 6 lose nearly all Findings in direct mode. Cases under ~15 MB are generally
unaffected. This appears to be a bug — `--full-analysis` is the default, so the direct
path should produce the same results as the sqlite path.

**Workaround:** Use sqlite two-step for any case over ~20 MB.

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

## Cases 6–15: Additional Analysis (Round 2)

Ten more real-world PHP memory issues were analyzed to further validate reli-prof.

### Case 6: Monolog Logger Accumulation — [php/php-src#20724](https://github.com/php/php-src/issues/20724)

Monolog caches all log records; PHP 8.4 deprecation notices amplify this in batch processes.

```
memory_get_usage(): 26.23 MB | Heap: 23.80 MB (90.7%)
[HIGH] dominant_class: DeprecationLogger: 5 instances (68.6% of objects)
[LOW]  dedup_candidate: file path 20,000 copies x 90 B = 1.72 MB (100% identical)
[LOW]  dedup_candidate: "->" 35,000 copies, "logDeprecation" 20,000 copies
[LOW]  dedup_candidate: "item_id" 20,000 copies, "line" 25,000 copies
```

**Score: 7/10** — Dedup candidates reveal the stack trace structure within log records:
file paths, method names, and keys repeated 10K-20K times. This directly points to
`debug_backtrace()` output being stored per record as the memory amplifier.

### Case 7: Symfony Mailer Event Dispatcher Leak — [symfony/symfony#59702](https://github.com/symfony/symfony/issues/59702)

Event dispatcher retains cloned message+envelope references, leaking per send().

```
memory_get_usage(): 7.94 MB | Heap: 7.14 MB (89.9%)
[HIGH] bottleneck_path + choke_point (7.24 MB)
[MEDIUM] large_array (4.16 MB), structural_duplicates for EmailMessage/Envelope/MessageEvent
```

**Score: 8/10** — Correctly identified the bottleneck path and structural duplicates.
Event dispatcher's `dispatchedEvents` array clearly visible as the accumulator.

### Case 8: Doctrine ORM Identity Map + SQL Logger — [doctrine/orm#8891](https://github.com/doctrine/orm/issues/8891)

EntityManager.clear() resets identity map but leaves SQL logger untouched.

```
memory_get_usage(): 6.31 MB | Heap: 6.06 MB (96.1%)
[LOW] dedup_candidate: "SELECT * FROM entities WHERE id = ?" 10,002 copies (576 KB)
[LOW] dedup_candidate: "params" 10,004 copies, "time" 10,020 copies
```

**Score: 7/10** — Dedup detection found 10K identical SQL strings, directly pointing to
the uncleaned SQL logger. Root Blame showed class_table 12.6% but no clear path to
the logger accumulation in HIGH findings.

### Case 9: PDO Circular Reference Leak — [doctrine/dbal#3047](https://github.com/doctrine/dbal/issues/3047)

Extending PDO creates circular refs between connection and prepared statements.

```
memory_get_usage(): 3.87 MB | Heap: 1.45 MB (37.5%)
[HIGH] bottleneck_path + choke_point (1.50 MB)
[HIGH] dominant_class (218 KB)
```

**Score: 6/10** — Found the accumulation but only 37.5% coverage. PDO internal objects
live outside ZendMM's regular heap, limiting reli-prof's visibility. The circular
reference pattern itself was not explicitly flagged.

### Case 10: API Platform Circular Normalizer Chain — [api-platform/core#5016](https://github.com/api-platform/core/discussions/5016)

Circular dependency between ItemNormalizer and DataTransformers causes deep object graph.

```
memory_get_usage(): 4.18 MB | Heap: 3.95 MB (94.4%)
[HIGH] bottleneck_path (1.43 MB), choke_point (1.43 MB)
[HIGH] dominant_class (609 KB)
```

**Score: 7/10** — Identified the bottleneck path through the normalizer chain and
dominant class accumulation. The circular dependency itself was not explicitly detected
as a cycle but the memory impact was correctly attributed.

### Case 11: PHP-FPM Static Session Accumulation — [php/php-src#13775](https://github.com/php/php-src/issues/13775)

Static session store persists across simulated FPM requests, accumulating per-request data.

```
memory_get_usage(): 3.01 MB | Heap: 2.94 MB (97.8%)
[HIGH] bottleneck_path (3.28 MB), choke_point (2.45 MB)
[HIGH] dominant_class (688 B)
[MEDIUM] large_array (2.55 MB)
```

**Score: 8/10** — Clearly identified the static accumulation pattern. Bottleneck path
and choke point accurately pointed to the session store.

### Case 12: Unserialize Cache Bloat — [ezyang/htmlpurifier#270](https://github.com/ezyang/htmlpurifier/issues/270)

Repeated unserialize of cached definitions creates duplicate object trees.

```
memory_get_usage(): 326.54 MB | Heap: 322.50 MB (98.8%)
[HIGH] dominant_class: CacheDefinition: 10,000 instances (unbounded accumulation)
[MEDIUM] dominant_type: ZendArrayTable 52.6% of heap (169.94 MB)
```

**Score: 7/10** — Correctly flagged CacheDefinition accumulation (10K instances) and
ZendArrayTable dominance. Could benefit from tracing the path from unserialize()
to the accumulation.

### Case 13: Schema Introspection Quadratic Growth — [doctrine/dbal#5588](https://github.com/doctrine/dbal/issues/5588)

Schema manager's index buffer grows across table introspection calls without clearing.

```
memory_get_usage(): 4.37 MB | Heap: 3.97 MB (90.7%)
[HIGH] bottleneck_path (4.77 MB), choke_point (1.98 MB)
[MEDIUM] large_array (3.04 MB), structural_duplicate: Index (1015 KB)
```

**Score: 8/10** — Bottleneck path led directly to the index buffer. Structural duplicate
detection for Index objects was particularly useful, identifying 3000 accumulated indexes.

### Case 14: Laravel Bootstrap Static Accumulation — [laravel/framework#44214](https://github.com/laravel/framework/issues/44214)

Static `$bootstrappers` array in Application class accumulates across test refreshes.

```
memory_get_usage(): 23.23 MB | Heap: 13.66 MB (58.8%)
[HIGH] bottleneck_path: objects_store->8->bindings[Broadcasting_binding_0] (13.16 MB)
[HIGH] dominant_class: Closure: 27,600 instances x 344 B = 9.05 MB (90.8% of objects)
[MEDIUM] large_array: class_table->application->static_properties->bootstrappers (3.06 MB, 200 elements)
[MEDIUM] structural_duplicate: ServiceProvider: 4,577 identical shapes
[LOW]  dedup_candidate: ServiceProvider::$bindings[value] (Closure): 55,200 copies (18.11 MB)
```

**Score: 9/10** — Excellent. Directly identified `static_properties->bootstrappers` as the
leak source (200 elements = 200 test iterations). Closure accumulation (27,600 instances)
and ServiceProvider structural duplicates were also flagged. This would immediately
point a developer to the static accumulation bug.

### Case 15: Closure Circular Reference in Event System — [PHP bug #69639](https://bugs.php.net/bug.php?id=69639)

Closures capturing `$this` create cycles: Widget → EventEmitter → Closure → Widget.

```
memory_get_usage(): 78 MB
Analysis DB: 7.4 GB (disk full at 2.2 GB on first attempt)
```

**Score: N/A** — Analysis DB grew to 7.4 GB for 78 MB of PHP memory.

**Root cause (partial bug):** Two factors combine:

1. **Pool flush breaks WeakMap dedup (bug):** In streaming mode,
   `flushPoolsIfStreaming()` clears the ObjectContextPool after emitting each branch.
   When a Closure's `this_ptr` later references the same Widget, a *new* ObjectContext
   instance is created (pool was cleared). The WeakMap memo uses object identity (`===`),
   so the new instance is not recognized as already visited → the Widget's entire subtree
   is re-expanded. With 4000 closures referencing 2000 Widgets, the same Widget can be
   expanded up to 4000 times.
   - Location: `MemoryLocationsCollector.php:199-208` (`flushPoolsIfStreaming`)
   - Missing: `ClosureContextPool` (Object/Array/String have pools, Closure does not)

2. **Test case O(n²) state growth:** `emit('update')` calls all registered listeners,
   so Widget.state accumulates ~2M entries total, contributing to the 78MB footprint.

**Suggested fixes:**
- Add `ClosureContextPool` for address-based closure deduplication
- Preserve object identity across pool flushes (e.g., address-based memo instead of
  WeakMap object identity, or convert to SentinelContext without clearing the pool)
- Consider capping context depth for scalar-dominated subtrees

### Round 2 Summary

| Case | Issue Type | Coverage | Score |
|------|-----------|----------|-------|
| 6 | Logger accumulation | 90.7% | 7/10 |
| 7 | Event dispatcher leak | 89.9% | 8/10 |
| 8 | ORM SQL logger | 96.1% | 7/10 |
| 9 | PDO circular ref | 37.5% | 6/10 |
| 10 | Normalizer chain | 94.4% | 7/10 |
| 11 | Static session store | 97.8% | 8/10 |
| 12 | Unserialize bloat | 98.8% | 7/10 |
| 13 | Schema introspection | 90.7% | 8/10 |
| 14 | Bootstrap static leak | 58.8% | 9/10 |
| 15 | Closure cycles | — | N/A |

**Key observations from Round 2:**
- **Static property accumulation** (Cases 11, 14) is detected excellently via
  `class_table->...->static_properties` paths
- **Closure-heavy graphs** (Cases 14, 15) are challenging — Case 14 worked because
  closures were one part of a broader pattern, but Case 15's pure closure cycles
  caused the analysis DB to explode
- **PDO internals** (Case 9) are partially opaque — only 37.5% coverage because PDO
  allocates outside ZendMM's tracked heap
- **Dedup detection** continues to excel — found 10K identical SQL strings in Case 8

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

### Usefulness Score (All 15 Cases)

| Case | Issue Type | Score | Note |
|------|-----------|-------|------|
| 1 | Worker leak | **9/10** | Bottleneck, duplicates, call stack all accurate |
| 2 | Error duplication | **9/10** | bottleneck_path + property_scaling (6.5 KB/instance) = precise |
| 3 | Chunk fragmentation | **6/10** | Found strings and pin objects, not fragmentation itself |
| 4 | Unbounded alloc | **7/10** | Pinpointed consumption locations and patterns |
| 5 | ORM hydration | **9/10** | structural_duplicate/dedup/shared_fanin excellent |
| 6 | Logger accumulation | **7/10** | Dedup reveals stack trace bloat (20K copies of paths/methods) |
| 7 | Event dispatcher leak | **8/10** | Bottleneck + structural duplicates identified |
| 8 | ORM SQL logger | **7/10** | Dedup found 10K identical SQL strings |
| 9 | PDO circular ref | **6/10** | Low coverage — PDO internals outside ZendMM |
| 10 | Normalizer chain | **7/10** | Bottleneck path found, cycles not explicitly flagged |
| 11 | Static session store | **8/10** | Clear accumulation pattern identified |
| 12 | Unserialize bloat | **7/10** | 10K instances flagged, array type dominance shown |
| 13 | Schema introspection | **8/10** | Index buffer + structural duplicates identified |
| 14 | Bootstrap static leak | **9/10** | static_properties path + Closure accumulation = precise |
| 15 | Closure cycles | **N/A** | Pool flush breaks WeakMap dedup — DB explosion |

**Average score (excluding N/A): 7.6 / 10**

**Overall:** Across 15 diverse PHP memory issues, reli-prof demonstrated strong diagnostic
capability without requiring any modification to target processes. It excels at:
- **Accumulation-type leaks** (Cases 1, 7, 11, 13, 14) — bottleneck_path and choke_point
  accurately trace the accumulator
- **ORM/framework object bloat** (Cases 5, 8, 12) — structural_duplicate and dedup_candidate
  identify redundancy patterns
- **Static property leaks** (Cases 11, 14) — `class_table->...->static_properties` path
  directly reveals the persistence mechanism

Remaining challenges:
- **Closure-heavy object graphs** (Case 15) — context tree explosion makes analysis impractical
- **PDO/extension internals** (Case 9) — allocations outside ZendMM are partially opaque
- **Streaming mode detail** (Cases 6, 15) — large cases need sqlite workaround

---

## Appendix: Heap Analysis Percentage Fix History

| Stage | Case 1 | Case 2 | Case 3 | Case 4 | Case 5 |
|-------|--------|--------|--------|--------|--------|
| Before fix | 0.0% | 0.8% | 2.0% | 2.0% | 0.7% |
| After backfill | 80.7% | 201.9% | 99.9% | 94.6% | 284.4% |
| After address dedup | **87.8%** | **98.8%** | **99.5%** | **94.0%** | **91.2%** |
