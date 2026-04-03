# Fix Request: Streaming Mode Issues Found During Memory Analysis Evaluation

## Context

During evaluation of reli-prof's `inspector:memory` against 15 real-world PHP memory
issues, three significant bugs were identified in streaming mode. All findings are
documented in `analysis-cases/REPORT.md` on branch `claude/analyze-php-memory-issues-dh5ET`.

---

## Bug 1: `-f report` produces fewer Findings than sqlite two-step (HIGH)

### Symptom

For cases over ~20 MB, `-f report` loses most or all Findings compared to the sqlite
two-step (`-f sqlite3 -o x.db` → `inspector:memory:report x.db`).

| Case | memory_get_usage | `-f report` | sqlite | Delta |
|------|-----------------|-------------|--------|-------|
| 2 | 38 MB | 1 finding | 34 findings | -33 |
| 5 | 45 MB | 0 findings | 29 findings | -29 |
| 6 | 26 MB | 1 finding | 14 findings | -13 |

Cases under ~15 MB (3, 4, 7–11, 13, 14) produce identical results on both paths.

### Likely Cause

Streaming mode uses `MemoryLocations::createLightweight()` which stores only addresses,
discarding size information needed for report generation passes.

### Location

- `src/Lib/PhpProcessReader/PhpMemoryReader/MemoryLocation/MemoryLocations.php:32-44`
- The `ReportMemoryOutput` path vs the `PdoMemoryOutput` + `memory:report` path

### Reproduction

```bash
# Direct (broken for large cases)
php target.php &; PID=$!
sudo php reli inspector:memory -p $PID -f report 2>&1 | grep -c '\[HIGH\|MEDIUM\|LOW\]'

# SQLite (correct)
sudo php reli inspector:memory -p $PID -f sqlite3 -o /tmp/test.db
php reli inspector:memory:report /tmp/test.db 2>&1 | grep -c '\[HIGH\|MEDIUM\|LOW\]'
```

Use `analysis-cases/case5_eloquent_hydration.php` or `case2_error_duplication.php` as targets.

---

## Bug 2: Cycle detection fails — defer optimization disconnects object from properties (HIGH)

### Symptom

`CycleClusterPass` (SCC/Tarjan) reports "No cycles" even for obvious circular references
like DataTransformer ↔ ItemNormalizer. The `[retained_exact] No cycles` message appears
despite 96K non-tree edges existing.

### Root Cause

In streaming mode, `defer_unseen_objects` causes objects to be collected in two phases:

1. **Phase 1 (objects_store, shallow):** ObjectContext emitted with `object_handlers` only
2. **Phase 2 (deferred resolution):** ObjectPropertiesContext emitted as a separate node

**The problem:** No edge connects the ObjectContext to its ObjectPropertiesContext in the DB.

Evidence from Case 10 (circular normalizer):
```
Node 262 (ItemNormalizer ObjectContext):
  OUT edges: 262 → 263 (object_handlers) — ONLY child
  IN edges:  252 → 262 (normalizer, non-tree) × 3 DataTransformers

Node 261 (ItemNormalizer ObjectPropertiesContext, DISCONNECTED):
  OUT edges: 261 → 40161 (transformers array) → ... → DataTransformer nodes
  No edge from 262 → 261 exists in context_edges
  canonical_node_id = NULL for both 262 and 261
```

The `canonical_node_id` Union-Find mechanism does not help because:
- It merges nodes for the **same address appearing multiple times**
- ItemNormalizer appears only once → canonical stays NULL
- The disconnect is between ObjectContext and ObjectPropertiesContext of the same object

### Location

- `src/Lib/PhpProcessReader/PhpMemoryReader/MemoryLocationsCollector.php:607-634`
  (deferred edge resolution)
- `src/Lib/PhpProcessReader/PhpMemoryReader/MemoryLocationsCollector.php:1269-1290`
  (defer_unseen_objects for object pointers)
- `src/Inspector/Output/MemoryOutput/Report/Substrate/GraphSubstrate.php:298-327`
  (canonical/Union-Find loading)

### Suggested Fix

When deferred property resolution emits properties for an object, either:

**(a) Emit an edge** from the ObjectContext node to its ObjectPropertiesContext node:
```
context_edges: (run_id, object_node_id, properties_node_id, "object_properties", is_tree=1)
```

**(b) Set canonical_node_id** to link the properties node to the object node, so
`buildSccAdjacency()` unifies them during SCC computation.

Option (a) is more natural — it matches the non-streaming behavior where `object_properties`
is a direct child of the object node in the tree.

### Reproduction

```bash
php analysis-cases/case10_circular_normalizer.php &; PID=$!
sudo php reli inspector:memory -p $PID -f sqlite3 -o /tmp/cycle.db
php reli inspector:memory:report /tmp/cycle.db 2>&1 | grep -i cycle
# Expected: cycle_cluster findings for DataTransformer ↔ ItemNormalizer
# Actual: "No cycles — retained size is exact"
```

---

## Bug 3: Pool flush breaks WeakMap dedup — DB explosion for closure-heavy graphs (MEDIUM)

### Symptom

Case 15 (78 MB PHP memory, 2000 Widgets with closures capturing `$this`) generates a
7.4 GB analysis DB. This is caused by the same Widget being re-expanded thousands of
times instead of being referenced.

### Root Cause

In streaming mode, `flushPoolsIfStreaming()` (line 199-208) clears the ObjectContextPool
after emitting each branch. When a Closure's `this_ptr` later references a Widget:

1. Widget was in the pool → emitted → pool cleared → ObjectContext instance discarded
2. Closure's `this_ptr` encounters the same address → creates NEW ObjectContext instance
3. The WeakMap memo uses object identity (`===`) → new instance ≠ old instance
4. Widget's entire subtree gets re-expanded instead of just emitting a reference edge

With 4000 closures referencing 2000 Widgets, duplication factor is massive.

### Location

- `src/Lib/PhpProcessReader/PhpMemoryReader/MemoryLocationsCollector.php:199-208`
  (`flushPoolsIfStreaming`)
- No `ClosureContextPool` exists (Object/Array/String have pools, Closure does not)

### Suggested Fix

1. **Add ClosureContextPool** for address-based closure deduplication (like ObjectContextPool)
2. **Preserve WeakMap identity across pool flushes:** When converting to sentinels, ensure
   the sentinel is registered in the memo so subsequent encounters get `emitReference`
   instead of re-expansion. The `convertToSentinels` method may already do this — verify
   that SentinelContext objects are properly stored in the WeakMap.
3. **Consider address-based memo** as a fallback: if WeakMap lookup fails, check by address

### Reproduction

```bash
php analysis-cases/case15_closure_cycle.php &; PID=$!
sudo php reli inspector:memory -p $PID -f sqlite3 -o /tmp/closure.db
# Watch DB grow to multi-GB for only 78 MB of PHP memory
ls -lh /tmp/closure.db
```

---

## Test Cases

All reproduction scripts are in `analysis-cases/case*.php` on branch
`claude/analyze-php-memory-issues-dh5ET`. Key cases for these bugs:

| Bug | Test Case | What to Check |
|-----|-----------|---------------|
| Bug 1 | case2, case5, case6 | Finding count: `-f report` vs sqlite |
| Bug 2 | case10 | `grep -i cycle` in report output |
| Bug 3 | case15 | DB file size vs PHP memory_get_usage |
