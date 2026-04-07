# Fix Request: Streaming Mode Issues Found During Memory Analysis Evaluation

**Status:** Bugs 1 and 2 are FIXED by `claude/fix-memory-analysis-2WiRL`. Bug 3 is partially fixed.

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

---

## Bug 4: Closure `this_ptr` not collected in streaming mode — cycle detection blind spot (HIGH)

**Status:** Open. Discovered after Bugs 1-3 were fixed.

### Symptom

Case 15 (Widget ↔ EventEmitter ↔ Closure cycle) reports "No cycles" despite
obvious circular references through Closure `$this` capture.

4000 Closure ObjectContext nodes exist in the DB, but only 2 have `closure` link.
Zero `this_ptr` edges from user Closures exist.

### Root Cause

`MemoryLocationsCollector.php:1778-1786`:
```php
// When defer is active (shallow collection for objects_store),
// skip dynamic properties, closures, generators, and fibers.
if ($this->defer_unseen_objects) {
    $this->defer_unseen_objects = $saved_defer;
    return $object_context;  // ← Skips collectClosure() entirely
}
```

In streaming mode, `defer_unseen_objects` is true during objects_store traversal.
This causes `collectClosure()` (which creates `this_ptr` and `func` links) to be
skipped for all Closures collected from objects_store.

The comment says "They will be collected when the object is reached from another phase,
or via deferred edge resolution." But:
- Deferred edge resolution only handles property references, not `collectClosure()`
- Closures reached from `global_variables` or `call_frames` DO get full collection
  (hence the 2 `closure` links — likely from class_table reflection entries)
- The 4000 user Closures in EventEmitter.listeners are only reached from objects_store

### Evidence

```
ObjectContext nodes with class=Closure: 4000
Edges with link_name="closure": 2    ← should be 4000
Edges with "this_ptr": 0             ← should be 4000
```

### Impact

Any cycle involving Closure `$this` capture is invisible to SCC when Closures are
collected from objects_store (the common case for application objects).

### Suggested Fix

After the defer early-return, schedule Closure-specific collection for later resolution,
similar to how deferred property edges work. When deferred edges are resolved
(line 607-634), also resolve pending Closure `this_ptr` / `func` links:

```php
if ($this->defer_unseen_objects) {
    // Still record that this is a Closure needing collectClosure() later
    if ($class_entry->getClassName($dereferencer) === 'Closure') {
        $this->deferred_closure_nodes[] = [$object_context, $object->getPointer()];
    }
    $this->defer_unseen_objects = $saved_defer;
    return $object_context;
}
```

Then in the deferred resolution phase, iterate `$this->deferred_closure_nodes` and
call `collectClosure()` + `$object_context->add('closure', ...)` for each.

**Status update:** Closure case fixed by `claude/fix-memory-analysis-2WiRL` commit
`848090af` using `deferred_closure_addresses` list. Verified: Case 15 now detects
`cycle_cluster: 4000x Closure + 2000x Widget + 1x EventEmitter`.

---

## Bug 5: Generator and Fiber have the same defer-skip problem as Closure (LOW)

**Status:** Open. Same root cause as Bug 4, affecting `collectGenerator()` and `collectFiber()`.

### Root Cause

The same `defer_unseen_objects` early-return at line 1833 that skipped `collectClosure()`
also skips:

- **`collectGenerator()`** (line 1887) — Generator's yield values, execution context,
  and `$this` reference. If a Generator captures `$this` (e.g., `yield` inside a method),
  the Generator → object back-reference is lost.
- **`collectFiber()`** (line 1908) — Fiber's stack and execution context.
  If a Fiber holds references back to its creator, the cycle is invisible.
- **`dynamic_properties`** (line 1847) — `$object->properties` array for objects with
  dynamic properties. Less impactful since typed properties are collected separately,
  but objects using `__set()` or `stdClass`-like patterns could lose data.

### Impact

Cycles involving Generator `$this` capture or Fiber back-references are not detected
by SCC when these objects are collected from objects_store (streaming mode).

This is lower priority than Closure (Bug 4) because:
- Generator cycles are less common in practice
- Fiber usage is still relatively rare in PHP applications
- Dynamic properties on typed classes are uncommon

### Suggested Fix

Same pattern as the Closure fix: record addresses during defer, resolve after all phases.

```php
if ($this->defer_unseen_objects) {
    assert(!is_null($object->ce));
    $className = $dereferencer->deref($object->ce)->getClassName($dereferencer);
    if ($className === 'Closure' && !$zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V71)) {
        $this->deferred_closure_addresses[] = $object->getPointer()->address;
    } elseif ($className === 'Generator' && !$zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V71)) {
        $this->deferred_generator_addresses[] = $object->getPointer()->address;
    } elseif ($className === 'Fiber' && !$zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V81)) {
        $this->deferred_fiber_addresses[] = $object->getPointer()->address;
    }
    $this->defer_unseen_objects = $saved_defer;
    return $object_context;
}
```

Then resolve each list in the deferred resolution phase, calling `collectGenerator()` /
`collectFiber()` respectively.

---

## Bug 6: choke_point reports objects_store itself as a finding (LOW)

**Status:** Open.

### Symptom

`choke_point` finding reports `ObjectsStoreMemoryLocation` as a "small object retaining
a large subtree", which is trivially true — all objects live in objects_store.

Example from Case 9:
```
choke_point: ObjectsStoreMemoryLocation (16.00 KB shallow) holds 1.45 MB via 2001 children
  — included_files->included_files->included_files->...->objects_store
```

The real choke point should be `PDOConnectionWrapper->statementCache` (which holds 2000
PDOStatements), not the objects_store container itself.

This appears across multiple cases (5, 9, 14, 15) whenever objects_store is the dominant
memory consumer.

### Additional Issue: `included_files` path noise

The entry path for choke_point findings shows `included_files->included_files->...`
chains (internal navigation nodes) rather than meaningful application-level paths.
Compare with `bottleneck_path` which correctly shows `objects_store->1->statementCache[0]`.

### Suggested Fix

1. **Filter out `objects_store`** from choke_point candidates. Unlike `class_table` or
   `function_table` which PHP developers can relate to (static properties, autoloading),
   `objects_store` is a PHP runtime internal that most developers don't know exists.
   Reporting it as a choke_point is not actionable — it just says "objects exist".
2. **Consider edge semantics:** objects_store holds references to all live objects as
   an index, not as an ownership relation. choke_point assumes "cutting this reference
   frees the subtree", but cutting from objects_store = destroying the object, which
   should be done from the application-level owner. Edges from objects_store (and
   similar runtime-internal roots) could be marked as "weak" or "structural" to
   distinguish them from application-level ownership edges.
3. **Improve path display** by collapsing or hiding internal navigation nodes
   (`included_files`, `IncludedFilesContext`) in human-readable output.

### Remaining Issue: objects_store bucket ID shown as bare number

After the fix, `objects_store` is excluded from choke_point and collapsed in paths.
But this leaves the objects_store bucket ID (a bare number) as the path root:

```
Before: objects_store->1->statementCache[0]
After:  1->statementCache[0]        ← "1" means nothing to the user
Want:   PDOConnectionWrapper->statementCache[0]
```

Rather than just resolving to the class name (which raises "which instance?"), the
ideal fix is to **prefer an alternative path** that reaches the same object through
application-level variables. For Case 9:

```
Want:   global_variables[conn]->statementCache[0]
Not:    PDOConnectionWrapper->statementCache[0]   ← which instance?
Not:    1->statementCache[0]                       ← what is 1?
```

The same object is typically reachable via `global_variables`, `call_frames` local
variables, or `class_table->...->static_properties`. These paths are meaningful to
the developer. `objects_store` is a runtime index that duplicates all of them.

**Approach:** When the bottleneck/choke path goes through `objects_store`, check if
the target object has an alternative parent via `all_parents` that is NOT in
objects_store. If so, rebuild the path from that parent. This could be done in
PathFormatter or in the pass that generates the finding.

**Important:** Do NOT suppress `objects_store` paths entirely. If no alternative
path exists (object only reachable from objects_store), showing the objects_store
path is better than showing nothing.

**Deeper issue:** In streaming mode, objects_store is traversed first (shallow),
so virtually all objects get their tree edge from objects_store. Edges from
global_variables, call_frames, etc. arrive later and become non-tree edges.
This means `is_tree` no longer reflects application-level ownership — it reflects
**traversal order**, which is an implementation detail of the streaming optimization.

`findAlternativeTreeParent` searching for `is_tree = 1` is fundamentally wrong in
this context — it will almost never find an app-level alternative because those
are all `is_tree = 0`.

**Possible approaches:**

1. **Quick fix — search non-tree edges too:** Make `findAlternativeTreeParent`
   accept `is_tree = 0` edges. Gets the right answer for Case 9 but doesn't
   fix the underlying is_tree semantics.

2. **Mark objects_store edges as `strength='index'`:** During objects_store shallow
   phase, emit edges with a distinct strength (e.g., `index`) instead of `strong`.
   The report side can then exclude `index` edges from tree-parent consideration
   and prefer `strong` edges from global_variables/call_frames. This is a small
   change to the collector and doesn't require reordering traversal.

3. **Don't assign is_tree during shallow phase:** Objects_store emits nodes and
   sentinel addresses but does NOT mark edges as tree edges. When global_variables
   and call_frames later reach the same objects, those edges get is_tree=1.
   Objects only reachable from objects_store get is_tree=1 as a fallback in a
   final pass. Risk: sentinel-based dedup depends on objects being "emitted", and
   the current design ties emitting to tree-edge assignment.

4. **Reorder traversal** to visit global_variables/call_frames first. Would make
   is_tree reflect app-level ownership naturally, but conflicts with the streaming
   optimization that needs sentinel maps from objects_store before visiting
   global_variables (to avoid deep recursion).

Approach 2 adds implementation-specific semantics to the DB schema, making it harder
to understand for anyone querying the DB directly. `strength` should describe the
reference relationship (strong/weak/structural), not traversal order.

**Reconsidered again: Approach 3 has a deeper problem.** Even if objects_store edges
are emitted as `is_tree = 0`, the subtree below each object (properties, arrays,
strings) is **already emitted** during the shallow phase. When the "real" DFS from
global_variables later hits a sentinel, it stops — it doesn't re-traverse the subtree.
So only the top-level `$conn` edge can be promoted to `is_tree = 1`; everything below
(`statementCache[0]`, etc.) keeps its objects_store-era `is_tree` assignment.

Fixing the entire subtree would require re-walking and UPDATE-ing all edges,
essentially doing the DFS twice. This conflicts with the streaming design goal.

**Final recommendation: Approach 1 (report-layer fix).** Accept that `is_tree` in
streaming mode reflects traversal order (objects_store first), and handle path
preference entirely in the report layer:
- `findAlternativeTreeParent` should search ALL edges (not just `is_tree = 1`)
- Walk up from the alternative parent using any available edges
- Prefer parents that are NOT under objects_store
- Fall back to objects_store path if no alternative exists

This is confined to ChokePointPass / PathFormatter, doesn't touch the collector or
DB schema, and correctly handles the "prep phase vs real DFS" distinction at the
layer where it matters (user-facing output).

### Longer-term consideration: is_tree as a report-layer concern

In streaming mode, `is_tree` no longer carries its intended semantics (DFS spanning
tree). The DB has all edges; a correct spanning tree can be **reconstructed** at
report time from the full graph, applying a policy like "prefer global_variables /
call_frames over objects_store". This means:

- **Collector:** emits all edges, `is_tree` is best-effort (or omitted entirely)
- **Report (GraphSubstrate):** rebuilds the spanning tree from the full edge set,
  with a DFS that deprioritizes objects_store. `is_tree` becomes a derived property,
  not a stored one.

Alternatively, a **middle processing phase** between collector and report could
rebuild is_tree in the DB:

```
collector → DB (all edges, is_tree = best-effort)
    ↓
middle phase: TreeRebuilder (runs in finalizeStreaming)
  - Load all edges from DB
  - Run DFS with objects_store deprioritized
  - UPDATE is_tree for all edges
    ↓
report (unchanged — trusts is_tree as before)
```

Advantages over report-layer fix:
- **Single point of correction** — fixes is_tree once, all passes benefit
  (choke_point, retained size, SCC, bottleneck_path, etc.)
- **No changes to report passes** — they keep trusting is_tree
- **No changes to collector** — keeps streaming as-is
- Runs once at finalization, cost is one DFS over the edge set in the DB

This is likely the best balance of correctness, maintainability, and scope of change.

**Performance — this is NOT optional:** The whole reason shallow reading exists is to
handle large processes where memory is tight. `rebuildSpanningTree` loads all edges
into PHP arrays, which for large cases (6.2M edges → ~94 MB) could push the profiler
itself into OOM — defeating the purpose of shallow reading.

**Required optimization:** Use FfiCsr's CSR format for the spanning tree DFS. CSR
stores the same graph in a fraction of the memory (C arrays vs PHP arrays). Since
GraphSubstrate already builds CSR from edges for SCC and retained-size computation,
the rebuild should either:

1. **Integrate into GraphSubstrate construction:** Run the priority-aware DFS during
   `loadFromDb()` / `FfiCsrGraphSubstrate::loadFromDb()`, before building the
   `children` / `strong_children` arrays. The DB's is_tree is then correct from
   the start — no separate UPDATE pass needed.
2. **Share the edge load:** Pass the loaded adjacency to GraphSubstrate instead of
   re-reading from DB. Use FFI C arrays when available.

Approach 1 is cleaner — single load, single DFS, correct is_tree from the start.

**Status:** `rebuildSpanningTree` and `loadEdgesFfi` both use cursor + FFI CSR.
`fetchAll` OOMs resolved. Remaining: `scc_adjacency` PHP array in
`FfiCsrGraphSubstrate::buildSccAdjacency()` (line 575) still OOMs at 512M for
Case 15 (6.2M edges). With unlimited memory, everything works correctly — paths
show app-level routes, cycles detected, "Break this_ptr" suggested.

**Last remaining OOM:** `$this->scc_adjacency[$cp][] = $cc` builds a PHP array
for SCC computation. Could be replaced with FFI CSR or by running Tarjan on
the existing `strongAllEdges` CSR.

**Status update:** `scc_adjacency` eliminated (inlined canonical resolution). But
`CycleClusterPass::loadLinkNames()` (line 606) does `fetchAll` on all tree edges,
which OOMs at 512M for Case 15. This is one of many `fetchAll` calls across the
report passes. Full list of potentially dangerous `fetchAll` locations:

```
# context_edges queries (most dangerous for large edge counts):
CycleClusterPass.php:606    loadLinkNames — all tree edges
CycleClusterPass.php:625    loadNodeTypes
CycleClusterPass.php:645    loadParentMap
ChokePointPass.php:112,123  objects_store node lookup
GraphSubstrate.php:360,378  loadEdges (non-FFI path)

# context_node_locations queries (dangerous for large object counts):
NonTreeEdgePass.php:77,216,404,446
TopArraysPass.php:185,241,293,319,330
PropertyScalingPass.php:319,356,392
```

For bounded-memory operation on Case 15-scale data (6M+ edges), these need either:
- Cursor-based iteration where possible (done for most)
- **Deferred loading**: load only data relevant to the current pass's results.
  Example: `CycleClusterPass::loadNodeTypes()` loads 2.1M node types but only
  needs the ~36K nodes in SCCs. Run SCC first (needs only node IDs), then load
  types for SCC nodes only via `WHERE node_id IN (...)`.
- LIMIT/pagination for report passes that only need top-N results
- Lazy lookup for infrequent access patterns

**Recommended pattern (2-stage):** For passes that compute a result set then
enrich it with metadata:
1. Compute the result using node IDs only (SCC, choke_point ranking, etc.)
2. Load metadata (node types, class names, link names) only for result nodes

This avoids loading 2M+ rows when only a few thousand are needed. The current
`loadNodeTypes()` OOM (line 628) is a concrete example — SCC has 36K nodes but
the method loads all 2.1M node types into a PHP map.

**Status:** Deferred metadata loading applied to CycleClusterPass, ChokePointPass,
PropertyScalingPass, TopArraysPass, PerPropertyMemoryPass. Remaining 4 passes with
the same "load all tree edges link_name" pattern:

1. **GcPendingPass**
2. **StructuralDedupPass**
3. **OwnershipPatternPass** ← OOM confirmed at 512M
4. **TopStringsPass**

All 4 can use the same prepared statement approach (on-demand lookup by node_id).

**Status:** All link_name fetchAll passes converted. Next OOM:
`BlameAllocationPass::analyze()` (line 71) builds `$incoming_count` array for all
2.1M nodes via `iterateAllParents()`. This is different from the fetchAll pattern —
the data comes from CSR substrate, not DB. The issue is BlameAllocationPass building
PHP arrays covering all nodes.

**Fix for BlameAllocationPass:** Add `getIncomingCount(int $nodeId): int` to
FfiCsrGraphSubstrate (trivial: `revOffsets[idx+1] - revOffsets[idx]`). Then
BlameAllocationPass queries per-node instead of building a full map. Same approach
for `iterateNodeSizes` — substrate already has `getNodeSize(nodeId)`, just needs to
be used in-loop instead of pre-building a map.

**LIFO bug:** Fixed — roots are reversed before push, visited is deferred to pop time,
push order is `[$adj_os, $adj]` so non-objects_store edges are preferred.

**Verified working:** Cases 5, 9, 14 produce correct app-level paths. Case 15 (6.2M
edges) OOMs at 512MB due to `fetchAll` in `rebuildSpanningTree` and at 2GB due to
`strong_all_children` PHP array in `FfiCsrGraphSubstrate`. These are pre-existing
issues (default `memory_limit=-1` masked them), not regressions from the FFI CSR change.
report in a single process, the TreeRebuilder's adjacency list can be passed directly
to GraphSubstrate, skipping its `loadEdges()` SELECT. DB I/O reduced by one full scan.
For the separate `memory:report` path (reading from a pre-existing DB), GraphSubstrate
loads edges from DB as before.

Evidence from Case 9:
```
Tree parent:     ObjectsStoreContext(221) --[1]--> PDOConnectionWrapper(223)  is_tree=1
Alt parent:      ArrayElementContext(34037) --[value]--> 223                  is_tree=0  ← missed!
Alt path to root: GlobalVariablesContext -> [array_elements] -> [conn] -> 223
Desired display: global_variables[conn]->statementCache[0]
```

### Location

- `src/Inspector/Output/MemoryOutput/Report/Pass/` — whichever pass generates choke_point findings
- `src/Inspector/Output/MemoryOutput/Report/Substrate/PathFormatter.php` — path collapsing
  and node labeling logic

---

## Bug 11: empty_object false positive for stdClass and dynamic-property classes (MEDIUM)

**Status:** Open.

### Symptom

`stdClass` (and any class using dynamic properties via `__set()`) is reported as
`empty_object: N instances, no properties` even when instances have properties.

### Root Cause

`StructuralDedupPass::analyzeWithGraph()` (line 147-158) only checks
`object_properties` children for property names. stdClass stores all properties in
`dynamic_properties` (a separate child link), which is not examined. So `$props`
is always empty for stdClass → `$is_empty = true` → false `empty_object` finding.

```php
// Current: only checks object_properties
foreach ($this->substrate->getChildren($node_id) as $child) {
    if ($this->lookupLinkName($link_stmt, $child) !== 'object_properties') {
        continue;
    }
    // ... collects property names
}
// dynamic_properties is never checked
```

### Status

Fix applied: `StructuralDedupPass` now checks `dynamic_properties` children. However,
**dynamic_properties is not emitted in streaming mode** — the `defer_unseen_objects`
early-return (line 1833) skips dynamic property collection entirely. This is the same
root cause as Bug 5 (Closure/Generator/Fiber skip). stdClass objects in the DB have
no `dynamic_properties` child node, so the check always finds zero children.

Needs: either add `dynamic_properties` to the deferred collection list (like
`deferred_closure_addresses`), or collect dynamic properties even in defer mode
since they are simple arrays that don't cause deep recursion. Case 14 heap percentage exceeds 100% (123.3%) — overlapping locations (MEDIUM)

**Status:** Open. Root cause identified.

### Root Cause

`ZendOpArrayHeaderMemoryLocation` for Closures points to a **sub-region inside**
`ZendObjectMemoryLocation`. The `zend_closure` struct embeds `zend_function func`
at offset +56, so the op_array address falls within the Closure object's address range:

```
Closure ZendObject:  addr 973440, size 344 (bytes 973440–973784)
OpArray inside it:   addr 973496, size 256 (bytes 973496–973752) ← OVERLAPS
```

All 27,600 OpArrayHeaders are inside their parent Closure object (27,600/27,600
confirmed). Address-based dedup doesn't catch this because the addresses are
different — it only deduplicates identical addresses, not containment.

Double-counted: 27,600 × 256 = 6.7 MB. Actual excess over `memory_get_usage`: 5.1 MB.
The 1.6 MB difference is from ZendMM bin alignment (344 → 384 byte bin).

### Fix

`RegionAnalyzer::filterOverlappingLocations()` already handles overlapping locations
in non-streaming mode — sort by address, skip locations contained within the preceding
one. Apply the same overlap filtering when computing region sums from the DB:

In `queryRegionSums`, after deduplicating by address, sort by `(region, address)` and
walk through sequentially, skipping any location whose address range falls within the
previous location's range. This is the DB equivalent of `filterOverlappingLocations`
and handles any embedded struct pattern generically (not just Closure op_arrays).

---

## Bug 7: Path display doesn't match PHP syntax (HIGH)

**Status:** Open.

### Symptom

Paths in bottleneck_path / choke_point don't resemble PHP code that developers write:

```
Current:  <main>:58::[widget]->$key->emitter->listeners[render]
Want:     $widgets[0]->emitter->listeners['render']
```

### Issues

1. **`$key` leaks internal structure:** ArrayElementContext has `key` and `value` child
   links. The path walks through `value` to reach the Widget but displays `$key` as an
   intermediate step. Users don't know what ArrayElementContext is — this should be
   invisible, with the key's value used as the array index (e.g., `[0]`).

2. **Variable name mismatch:** `[widget]` appears instead of `$widgets`. The variable
   name may be truncated or coming from a different source than the actual PHP variable.

3. **`global_variables` collapsed leaves bare `[widget]`:** After PathFormatter collapses
   `global_variables`, the remaining `[widget]` has no `$` prefix and no context about
   what it is.

4. **Missing array index:** `$widgets` should show the specific index that leads to
   the heaviest path, e.g., `$widgets[0]` or `$widgets[1018]`.

### Suggested Fix

Path formatting should translate internal context tree structure to PHP syntax:
- `global_variables[varname]` → `$varname`
- `ArrayElementContext -> key: K, value: V` → `[K]` (collapse key/value indirection)
- `ObjectPropertiesContext -> propname` → `->propname`
- `CallFrameContext` → `functionName():lineno`

NodeLabeler currently only resolves CallFrameContext. Extend it to handle:
- Object nodes → class name
- Array element nodes → collapse key/value into `[index]`
- Global variable nodes → `$` prefix

### Location

- `src/Inspector/Output/MemoryOutput/Report/Substrate/NodeLabeler.php`
- `src/Inspector/Output/MemoryOutput/Report/Substrate/PathFormatter.php`

### Additional Bug: `included_files` x19 chain is a loop caused by NULL parent

The path `included_files -> included_files -> ... -> class_table` does NOT exist
in the DB. The `included_files` edge exists exactly once (root → node 0).

**Root cause:** ChokePointPass line 158-191 walks tree parents upward. When it
reaches a root node (parent_node_id IS NULL), `(int)$pr[0]` casts NULL to 0.
Node 0 is the `included_files` IncludedFilesContext. Its tree parent query returns
the root edge (parent IS NULL) again → NULL → 0 → loop for 20 iterations.

**Fix:** Break the loop when `parent_node_id` is NULL (root reached):
```php
if ($pr[0] === null) {
    // Reached root — record the root link_name and stop
    array_unshift($up_parts, (string)$pr[1]);
    break;
}
```

### Status

`$key` indirection removed (commit `416b1bd5`). Remaining issues:

```
Current:  <main>:40::[conn]->$statementCache[0]
Want:     $conn->statementCache[0]

Current:  <main>:58::[widget]->$emitter->listeners[render]
Want:     $widgets[N]->emitter->listeners['render']
```

1. **`[conn]` needs `$` prefix:** This is under `global_variables`, so it's the PHP
   variable `$conn`. Should display as `$conn`.
2. **`$statementCache` should not have `$`:** This is an object property access,
   not a variable. Should be `->statementCache` (arrow notation).
3. **`[widget]` vs `$widgets`:** The variable name may be truncated or the link_name
   in the edge uses a different form. Needs investigation.
4. **Missing array index after variable:** `$widgets` should be `$widgets[N]` with
   the specific index from the heaviest path.

**Priority: HIGH** — The analysis engine is now accurate (Bugs 1-6 fixed, cycle
detection works, app-level paths found) but the output is hard to read. A PHP
developer seeing `[conn]->$statementCache` won't immediately understand it means
`$conn->statementCache`. This is the main barrier to practical usability.

**Additional issue: top-level path collapsing is too aggressive.** PathFormatter
collapses `class_table`, `function_table`, `global_constants` etc., losing the
context of which root the path came from:

```
Current:  application->static_properties->bootstrappers[199]
Want:     Application::$bootstrappers[199]
```

`application` (all lowercase) looks like a variable name. The user needs to know
this is `class_table->Application->static_properties->bootstrappers` — i.e., a
static property of the Application class.

**Revised collapsing policy:** Only collapse `included_files` (always present,
pure noise). Keep everything else — `class_table`, `function_table`,
`global_constants`, `global_variables`, `objects_store`, `modules`, etc.

PHP allows identical names across classes, functions, and constants
(`class app`, `function app`, `const app` can coexist), so the prefix
is the only way to disambiguate. Collapsing loses information that
users need.

---

## Bug 9: dedup_candidate reports key/value as near-duplicate noise (MEDIUM)

**Status:** Open.

### Symptom

dedup_candidate reports `$hoge[key]` with N copies and `$hoge[value]` with N-1 or
N+1 copies, both showing nearly identical content. This is noisy — they're describing
the same array elements from two angles.

### Root Cause

ArrayElementContext stores `key` and `value` as separate child nodes. The dedup pass
collects strings by their parent property path, so `SomeClass::$prop[key]` and
`SomeClass::$prop[value]` are counted independently. For a homogeneous array like
`['a' => 'x', 'b' => 'x', ...]`, this produces two findings that say essentially
the same thing.

### Fix

Merge key/value findings for the same array path, or only report the value side
(the key side is rarely actionable — you can't "deduplicate" array keys).

---

## Bug 10: dedup_candidate flags same-class objects by size alone (MEDIUM)

**Status:** Open.

### Symptom

dedup_candidate reports things like:
```
Widget: 2,000 copies x 88 B
```

But all instances of the same class (without dynamic properties) are always the same
size — this is a PHP language guarantee, not a deduplication opportunity. The finding
is noise unless the **values** are actually identical.

### Root Cause

The dedup pass groups by `(property_path, size)` and counts occurrences. For objects,
size is determined by the class definition, so N instances of the same class always
match. The pass doesn't check whether the actual content/values are identical.

### Fix

For object-typed dedup candidates, require **value identity** (or at least structural
identity of properties), not just size equality. The existing "X% have identical
content" metric already distinguishes this — if it's 0% identical, the finding should
be suppressed or downgraded significantly.

For string/scalar dedup candidates, size + content matching (current behavior) is
correct and useful.

---

## Architectural Proposal: Remove objects_store pre-read and non-streaming mode

**Status:** Proposal. Would resolve Bugs 2, 4, 5, 6, 7 (partially), 11 at the root.

### Problem

The objects_store shallow pre-read was introduced for streaming mode to avoid deep
recursion in object graphs. But it caused a cascade of bugs:

| Bug | Caused by |
|-----|-----------|
| 2 | Deferred properties disconnect object → SCC can't find cycles |
| 4 | Closure this_ptr skipped in defer |
| 5 | Generator/Fiber also skipped |
| 6 | is_tree reflects objects_store-first order → spanning tree rebuild needed |
| 7 | Path display needs objects_store-aware alternative path search |
| 11 | dynamic_properties skipped in defer → stdClass always "empty" |

All stem from one decision: traverse objects_store first, shallowly.

### Proposed Change: Restore original DFS order in streaming mode

The original non-streaming mode used:

```
call_frames → global_variables → function_table → class_table → global_constants
→ global_callbacks → modules → included_files → interned_strings → objects_store (last)
```

Do the same in streaming mode. Use **address → node_id table** for dedup:

1. DFS from call_frames — emit objects as encountered, register address in table
2. Same address reached again → reference edge only (no re-expansion)
3. Continue with global_variables, function_table, class_table, etc.
4. objects_store **last** — only emit objects not yet reached from app-level paths
5. Use iterative DFS (explicit stack) to avoid stack overflow on deep graphs

This eliminates:

- `defer_unseen_objects` and all deferred lists (closure, generator, fiber, dynamic_properties)
- `flushPoolsIfStreaming()` and pool flush / WeakMap identity issues
- `rebuildSpanningTree` (is_tree is correct from the start — app paths get tree edges)
- `findAlternativeTreeParent` (objects_store edges are already non-tree)
- Sentinel mechanism (address table replaces it)
- ContextPools / convertToSentinels complexity

### Also: Remove non-streaming mode

Non-streaming mode is no longer used — even `-f report` goes through temp sqlite.
Two code paths caused Bug 1 (Findings differ) and double the maintenance surface.

- Streaming with `:memory:` sqlite gives equivalent performance for small cases
- One code path = fewer bugs, fewer tests needed
- All current test cases already exercise the streaming path

### Risk Assessment

| Risk | Mitigation |
|------|-----------|
| Deep object graphs → stack overflow | Iterative DFS with explicit stack (no recursion limit) |
| Memory for address table | `int → int` map, ~16 bytes per object. 1M objects = 16 MB |
| Performance change | objects_store traversal becomes minimal (most already visited). Less deferred resolution work → likely net positive |

### Scope

This is a significant refactor of `MemoryLocationsCollector`, but it **removes more
code than it adds**. The current fix-by-fix approach (Bugs 2→4→5→6→11) is adding
workaround complexity to compensate for the pre-read design. A clean rewrite of the
streaming traversal would simplify the codebase.

### Status: Iterative DFS implemented, regressions found

Iterative job queue collector implemented (`claude/fix-memory-analysis-2WiRL`).
10/15 cases work well, but 3 regressions remain:

**Case 11 (13.1% coverage, was 97.8%) — FIXED:**
EmitClassTableJob now expands static_properties. Coverage restored to 97.8%.
`sessionstore->static_properties->sessions` correctly detected.

**Case 14 (58.3%, was 94.3%) — ROOT CAUSE IDENTIFIED:**
Overlap filtering (Bug 8 fix) removes ALL 27,600 ZendOpArrayHeaderMemoryLocation
(6.7 MB) from the sum, but `memory_get_usage()` counts them as part of `zend_closure`.

The issue: `ZendObjectMemoryLocation` for Closure = 344 B (`sizeof(zend_object)`),
but the actual `zend_closure` struct is ~600 B (includes embedded `zend_function`).
The op_array header (256 B) is recorded as a separate location at offset +56.
Overlap filtering correctly identifies the containment but removes the op_array
from the sum, leaving only 344 B per Closure while `memory_get_usage` counts ~600 B.

**Proper fix:** Report Closure's `ZendObjectMemoryLocation` with `sizeof(zend_closure)`
instead of `sizeof(zend_object)`. Then the op_array is already included in the object
size, the separate `ZendOpArrayHeaderMemoryLocation` would be purely informational
(or skipped), and overlap filtering doesn't affect the sum.

**Current blocker:** The fix in `ZendObjectMemoryLocation::fromZendObject()` calls
`$zend_type_reader->sizeOf('zend_closure')`, but the header file (`v84.h`) defines
`zend_closure` as an **empty struct** with no members:
```c
typedef struct _zend_closure {
} zend_closure;
```
This makes `sizeOf('zend_closure')` return 0 and `getOffsetAndSizeOfMember('zend_closure', 'std')`
fail. The code falls through to the `else` branch using `getMemorySize()` = 344
(sizeof(zend_object)). The header definitions need the full `zend_closure` struct
including at minimum `zend_object std` and `zend_function func` members.

**Quick fix:** For percentage calculation only, use raw dedup sum (address dedup but
no overlap filtering). The >100% from Bug 8 was caused by same-address duplicates
(multiple context paths), not by address-range overlaps. Address-range overlap
filtering is correct for retained-size calculation but over-corrects the percentage.

**Confirmed:** `sizeof(zend_closure)` = 344 is correct (std=56 + func=256 + this_ptr=16
+ called_scope=8 + orig_internal_handler=8 = 344). The header definition is NOT empty.
`getMemorySize()` also returns 344 (coincidentally matching). Overlap filtering
correctly removes the embedded op_array (offset +56, 256 B) because it falls within
the Closure's 344 B range. But `memory_get_usage()` counts the full 344 B per Closure
including the embedded func region.

The issue is purely in the percentage formula: overlap-filtered sum should NOT be
used as the numerator. Use raw address-dedup sum (21 MB / 24 MB = 86.5%) instead of
overlap-filtered sum (14 MB / 24 MB = 58.8%).

Confirmed: `memory_get_usage()` counts each `emalloc` allocation once. A Closure is
one `emalloc(sizeof(zend_closure))` = 344 B. ZendMM has no concept of "overlap" —
that's purely reli-prof recording the same memory as two locations (ZendObject 344B
and embedded OpArrayHeader 256B). Overlap filtering correctly removes the child for
retained-size purposes, but for percentage calculation the parent's 344 B already
covers the full allocation, so the filtered sum IS the right number for the Closure
portion.

The remaining gap (14 MB filtered vs 24 MB memory_get_usage = 10 MB) is NOT from
overlap. It's **ZendMM bin alignment overhead**: each allocation is rounded up to
the next bin size (e.g., 344 → 384, 56 → 64). reli-prof records struct sizes,
`memory_get_usage` records bin sizes. 27,600 closures × 40B overhead = 1.1 MB,
plus alignment on all other objects = ~3.3 MB total. This is a fundamental
measurement difference, not a bug.

Raw dedup sum (21 MB) over-counts by including the embedded OpArrayHeader (6.7 MB).
Overlap-filtered sum (14 MB) is the correct logical size. The 58.8% gap is mainly
from ZendMM bin alignment overhead (~3.3 MB) which `possible_allocation_overhead_total`
is supposed to account for — but it's 0 in the current implementation.

**Root cause:** `MemoryLocations` is still created in lightweight mode (address-only,
no size info), so `RegionAnalyzer` can't compute bin alignment overhead. The
lightweight mode was introduced for streaming to save memory, but with the iterative
DFS rewrite (no more pool/sentinel/defer), the memory pressure that motivated
lightweight is gone. Using full `MemoryLocations` would restore:

- `RegionAnalyzer::filterOverlappingLocations` working correctly
- `possible_allocation_overhead_total` computed (bin alignment)
- Percentage calculation accounting for bin overhead
- No need for the `queryRegionSums` DB-side workaround

**Fix:** Remove `createLightweight()` usage in the iterative collector and use full
`MemoryLocations`. This is a small change and aligns with the goal of simplifying
the streaming path.

**Case 15 — FIXED (EFAULT) but OOM@512M remains:**
EFAULT crash fixed by try-catch in job loop + per-element error resilience.
With unlimited memory: 26 findings, 83.1% coverage, cycle_cluster detected,
bottleneck `$emitter->listeners[update][0]`. At 512M: OOM in
FfiCsrGraphSubstrate::computeSccFfi line 637 (`canonical_original_indices`).

**Previous root cause (resolved):**
`EmitArrayJob.php:72` throws `MemoryReaderException (errno=14 EFAULT)` when
dereferencing an array pointer with an invalid address. The exception propagates
to the main job loop (`MemoryLocationsCollector.php:463`) unhandled, aborting
the entire collection. DB ends up empty (0 nodes, 0 edges).

The old recursive collector had try-catch around individual collect methods so a
single bad pointer would skip that object and continue. The iterative job queue
loop has no error handling — one bad pointer kills the whole analysis.

**Fix:** Wrap `$job->execute($ctx, $queue)` in try-catch to skip failed jobs:
```php
while (!$queue->isEmpty()) {
    $job = $queue->pop();
    try {
        $job->execute($ctx, $queue);
    } catch (\Throwable) {
        // Skip this job, continue with remaining queue
    }
}
```

**Case 15 — FIXED (EFAULT) but OOM@512M in FfiCsr remains.**

**Case 14 — ROOT CAUSE: `static_variables` not collected in iterative DFS.**
`CollectorHelpers::collectZendFunction()` line 211-214 has an empty block:
```php
if (!is_null($func->op_array->static_variables)) {
    // Empty — no collection code
}
```
This loses 23,001 arrays (Closure captured variables via `use ($name, $i)`).
0.12.x vs iterative comparison confirms exactly 23,001 fewer ZendArray,
ZendArrayTable, and ZendArrayTableOverhead locations, totaling ~8.6 MB.

**Fix:** Push `EmitArrayJob` for `$func->op_array->static_variables` and add
it to the function context as `static_variables` child link. (Done)

### Inline overhead status

Bin overhead now computed at emit time. But **all cases exceed 100%** (Case 11: 134.5%,
Case 14: 122.9%, Case 2: 128.1%, Case 5: 131.7%).

The overhead is being added on top of struct sizes, but `memory_get_usage()` already
counts bin-aligned sizes. So:

```
memory_get_usage = Σ(bin_size) = Σ(struct_size + overhead)
heap_usage       = Σ(struct_size) + Σ(overhead)   ← should equal memory_get_usage
```

If heap_usage > memory_get_usage, then either:
1. Overhead is computed for locations that were removed by overlap filtering
   (OpArrayHeaders inside Closures get removed, but their overhead was already added)
2. Overhead is computed for locations outside the heap (wrong region)
3. The overhead calculation itself is wrong (bin_size lookup issue)

Need to check: is overhead being added for overlap-filtered locations? The overlap
filter removes the child location from the sum but if its overhead was already
accumulated separately, that's a double-count.

---

## Design Proposal: Inline region + overhead at emit time, eliminate MemoryLocations accumulation

**Status:** Proposed. Addresses MemoryLocations memory concern + simplifies architecture.

### Problem

Full `MemoryLocations` (non-lightweight) accumulates all MemoryLocation objects for
the process lifetime. Node count scales with target process size — problematic for
the large processes that motivated the iterative DFS rewrite in the first place.

### Proposed Design

Compute region classification and bin alignment overhead **at emit time** in
PdoContextTreeSink, instead of post-hoc in RegionAnalyzer/backfillRegions.

```
collect → MemoryLocation created
  → emit to PdoContextTreeSink:
      1. region = RegionBoundaries.classifyRegion(location)
      2. bin_overhead = chunk.getOverhead(location)
      → INSERT into context_node_locations with region + bin_overhead
  → MemoryLocation object becomes eligible for GC immediately
```

### Why This Works

- **RegionBoundaries can be built before the job loop:** chunk/huge/vm_stack/compiler_arena
  memory locations are determined by reading ZendMM heap structures at the start of
  `collectAll`, before any jobs execute. No chicken-and-egg problem.

- **MemoryLocations stays lightweight:** Only needs address→bool for dedup checking.
  No MemoryLocation objects accumulated.

- **backfillRegions becomes unnecessary:** Region is written inline at INSERT time.

- **RegionAnalyzer becomes unnecessary:** Its three jobs are handled elsewhere:
  1. filterOverlappingLocations → `queryRegionSums` with address-sort containment check (already exists)
  2. bin alignment overhead → computed at emit time, stored in DB
  3. region classification → computed at emit time, stored in DB

- **Overlap filtering for percentage:** Done in `queryRegionSums` at report time
  (sort by address, skip contained ranges). Emit writes everything, aggregation filters.

### Implementation

1. **Add `bin_overhead` column** to `context_node_locations`
2. **Pass RegionBoundaries + chunk_memory_locations to PdoContextTreeSink** at construction
   (after chunk enumeration, before job loop)
3. **In `emitNode`:** call `classifyRegion(location)` and `chunk.getOverhead(location)`
   for each location, write to DB
4. **In `queryRegionSums`:** `SUM(size + bin_overhead)` with address-sort overlap filter
5. **Remove:** RegionAnalyzer, backfillRegions, full MemoryLocations, correctedToArray

### What Changes vs Current

| Component | Current | Proposed |
|-----------|---------|----------|
| MemoryLocations | Full (all objects) | Lightweight (address→bool) |
| Region classification | backfillRegions (post-hoc UPDATE) | Inline at INSERT |
| Bin overhead | RegionAnalyzer (needs full MemoryLocations) | Inline at INSERT |
| Overlap filtering | RegionAnalyzer.filterOverlappingLocations | queryRegionSums (DB-side) |
| RegionAnalyzer | Required | Eliminated |
| PdoContextTreeSink | Receives RegionBoundaries optionally | Always receives RegionBoundaries |
