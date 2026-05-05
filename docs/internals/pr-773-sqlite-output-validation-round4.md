# PR #773 SQLite output — round-4 validation report

After rounds 1–3 fixed the IntegerIndexMerger boundary bug
(#775), restored multi-run-into-same-DB (#776 + #777), and surfaced
the postgres `context_edges` schema asymmetry (round-3, fix
pending), this round pushes into the remaining code paths the PR
description flagged or the diff stat highlighted: JSON output, the
dump → analyze pipeline, the new `--memory-limit-error-*` feature,
the long-string overflow path, fibers/enums/readonly object
shapes, and `inspector:memory:report` parity vs 0.12.0.

## TL;DR

- **No regressions** found in any of: JSON output structure (vs
  0.12.0), `inspector:memory:dump` → `inspector:memory:analyze`
  pipeline, fibers/enums/readonly-property heap shapes, or the
  long-string ZendString location path. Every `PRAGMA
  integrity_check` returns `ok` on every flag combination on every
  shape; per-table content hashes match across all 8 flag
  combinations on every shape.
- **One real bug** in
  `Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate::iterateNodeSizes`:
  the sqlite-built substrate's iterator is keyed off
  `context_node_locations`, so it silently skips every "structural"
  context node that has no allocation (CallFramesContext,
  CallFrameContext, ArrayHeaderContext, ObjectPropertiesContext,
  ScalarValueContext, …). The rmem-built substrate
  (`FfiCsrGraphSubstrate.iterateNodeSizes`) iterates every node.
  User-visible: `inspector:memory:report` against a SQLite DB
  silently drops the `call_stack` finding (and would drop any other
  pass that locates a structural-only node via
  `iterateNodeSizes`); `inspector:memory:report` against the same
  dump's rmem includes it.
- **Three UX gaps** in the new
  `--memory-limit-error-{file,line,max-depth}` feature:
  - `--memory-limit-error-file` set without
    `--memory-limit-error-line` is silently ignored — output is
    byte-identical to a baseline capture, no warning.
  - Non-existent file path is silently accepted, hint contributes
    nothing, no warning.
  - Line beyond EOF is silently accepted, hint contributes
    nothing, no warning.
  - The successful path *does* work (adds 7 nodes / 13 edges /
    13 attributes per call frame; 3 frames walked at depth 512;
    `memory_limit_call_frames` link_name marker present), but the
    user has no top-level summary key to confirm the hint
    resolved — they have to query `context_edges WHERE link_name
    = 'memory_limit_call_frames'` to know.

## Heap shapes verified clean against the full 8-flag matrix

| Shape                           | rmem      | sqlite   | edges    | content-hash parity | integrity |
|---------------------------------|-----------|----------|----------|---------------------|-----------|
| `target_fibers_enums.php`       |  6.6 MiB  |  16 MiB  |  81,635  | identical 8/8        | ok        |
| `target_long_strings.php`       |  3.4 MiB  | 8.2 MiB  |   ~mid   | identical 8/8        | ok        |

For `target_fibers_enums`, `Fiber::start` / `Fiber::suspend`
captured locals retain through the heap walker correctly.
`#[\AllowDynamicProperties]` instances with mixed scalar / array /
object / enum dynamic properties round-trip; backed enums
(`enum X: string` / `enum Y: int`) walk through the case-name
attributes; readonly-property objects with mutual `parent`
references produce a clean tree.

For `target_long_strings`, strings up to 524 KiB are allocated in
the target. reli's heap walker truncates string_value at 256 B
when storing into `context_node_locations`, so `OverflowNotSupportedException`
in the format-direct merge isn't reachable from user data alone —
the format-direct path stays valid and there's no `Format` cell
larger than the inline payload threshold. (If a future
`location_type` ever decided not to truncate, the SQL fallback
would kick in. For now the path is dormant.)

## inspector:memory:dump → inspector:memory:analyze

Verified via `tests/scripts/sqlite-validation/test_dump_analyze.sh`.

| Variant                              | size       | integrity | content-hash vs live capture |
|--------------------------------------|------------|-----------|-------------------------------|
| live (`inspector:memory -p $PID`)    | 9,814,016  | ok        | (baseline)                    |
| `inspector:memory:dump` (default)    | 9,809,920  | ok        | matches¹                      |
| `--include-binary`                   | 9,809,920  | ok        | matches¹                      |
| `--exclude-heap`                     | 9,809,920  | ok        | matches¹                      |

¹ "matches" with the caveat that target heap drift between captures
adds ±1 row to `context_nodes` / `context_edges` between live and
dump, which is target-side timing, not a code path bug.
**Address-and-node-id-stripped invariant content hashes are bit-identical**
across live and dump for every table — confirming the heap *shape*
is the same, only the addresses moved.

The dump→analyze path reproducibility was also pinned: same dump
file analyzed twice produces byte-identical sqlite (every table
hash matches). Live capture twice in succession against a parked
target also produces byte-identical sqlite.

The dump→analyze path also adds 3 metadata keys to `summary` that
the live path doesn't surface: `memory_get_peak_usage`, `memory_limit`,
`rss`. Likely intentional (the dump file format records these at
dump time); flagged because users who switch from live capture to
dump→analyze for offline review will see a richer summary.

## JSON output structure 0.12.0 vs PR

Verified via `tests/scripts/sqlite-validation/test_json_parity.sh`
and `json_compare.py`.

- Top-level keys identical: `summary`, `context`,
  `class_objects_summary`, `location_types_summary`.
- Every shared array's `#locations` element keeps the same
  8-key schema (`address`, `class_name`, `location_type`,
  `refcount`, `region`, `size`, …).
- PR adds a few **net new** sub-trees that 0.12.0 didn't have:
  `summary[0].bin_shape_counts.bin`,
  `summary[0].bin_walk_periodic_groups`,
  `summary[0].region_map`, `context.regular_list`. These are new
  analysis features, not renames of existing ones.
- **Zero keys present in 0.12.0 are missing from PR** — every
  downstream consumer that worked on 0.12.0 JSON keeps reading
  the PR JSON.

## inspector:memory:report — sqlite vs rmem parity gap (real bug)

Verified via `tests/scripts/sqlite-validation/test_report_parity.sh`.

When generating a report from rmem directly vs from a SQLite DB
*built from the exact same rmem*, the rmem path emits one extra
finding the sqlite path doesn't:

| Source | total findings | `call_stack` findings |
|--------|----------------|------------------------|
| PR rmem-direct  (`inspector:memory:report capture.rmem -f report-json`) | 254 | **1** |
| PR sqlite       (`inspector:memory:report capture.sqlite3 -f report-json`) | 253 | 0 |
| 0.12.0 sqlite   (no `call_stack` pass at all) | 60  | 0 |

All other 253 findings are bit-identical between the two paths; the
only delta is the missing `call_stack`.

### Root cause — `GraphSubstrate::iterateNodeSizes` excludes 0-size structural nodes

`Inspector\Output\MemoryOutput\Report\Pass\CallStackPass::analyzeWithSubstrate`
(`CallStackPass.php:68-78`) finds the call-stack root by:

```php
foreach ($this->substrate->iterateNodeSizes() as $node_id => $_) {
    if ($this->substrate->getNodeType($node_id) === 'CallFramesContext') {
        $callFramesNodeId = $node_id;
        break;
    }
}
```

The two substrate implementations populate `iterateNodeSizes`
differently:

`FfiCsrGraphSubstrate.iterateNodeSizes` (rmem path,
`FfiCsrGraphSubstrate.php:1280`):

```php
public function iterateNodeSizes(): iterable
{
    for ($i = 0; $i < $this->nodeCount; $i++) {
        yield $this->indexToNodeFfi[$i] => $this->ffiNodeSizes[$i];
    }
}
```

→ yields **every node** in the rmem, with size 0 for structural
nodes that have no allocations.

`GraphSubstrate.iterateNodeSizes` (sqlite path,
`GraphSubstrate.php:497`):

```php
public function iterateNodeSizes(): iterable
{
    return $this->node_sizes;
}
```

→ returns the `$node_sizes` array which is **only populated from
`context_node_locations`** (`GraphSubstrate.php:195-210`). Structural
context nodes that have no allocation row are silently absent.

For a representative target, the gap is large: structural-only
node types account for **22 distinct types and ~31K nodes** that
the rmem substrate iterates but the sqlite substrate does not.

```text
=== node types and how many lack location rows (sqlite path can't see) ===
ArrayElementContext              7,633 / 7,633   100%
InternalFunctionDefinitionContext 6,818 / 6,818   100%
ScalarValueContext               5,347 / 5,347   100%
ObjectPropertiesContext          3,615 / 3,615   100%
ClassConstantContext             2,163 / 2,163   100%
ClassDefinitionContext             301 /   301   100%
CallFrameContext                     2 /     2   100%
CallFrameVariableTableContext        2 /     2   100%
UserFunctionDefinitionContext        2 /     2   100%
CallFramesContext                    1 /     1   100%
... and 12 more 100%-missing types
```

### Impact

- **CallStackPass on sqlite path returns `[]` and the
  `call_stack` finding is silently absent from every
  sqlite-driven report.** The user can't tell the report is
  incomplete — just that the section isn't there. SQL fallback
  (`analyzeWithSql`) IS correct and would produce the right
  output (`0|sleep|-1`, `1|<main>|121`), but it's gated behind
  `!$run_phase3` and only runs on tiny graphs that skip Phase 3
  entirely.
- **Other Phase-3 passes consuming `iterateNodeSizes` to discover
  nodes by type** would have the same blind spot. Surveyed:
  - `BlameAllocationPass.php:72` — explicitly filters
    `if ($size === 0) continue;`, so unaffected (only cares about
    real allocations).
  - `TopArraysPass.php:67` — iterates parent nodes and looks for
    array-element children. Real arrays are sized
    (ZendArrayMemoryLocation), so the parent is enumerated; this
    pass appears to be unaffected on this dump (`top_arrays`
    finding count is 0 in both rmem and sqlite reports here, so
    it's not exercised, but the substrate gap doesn't hurt the
    happy path).
  - `RmemModel.php:481, 543` (TUI explorer) — would silently miss
    structural nodes when browsing.

### Suggested fix

Make `GraphSubstrate::iterateNodeSizes` match the
`FfiCsrGraphSubstrate` semantics — yield every known node, defaulting
size 0 for structural ones. The cleanest place is to seed
`$this->node_sizes` from `context_nodes` before adding location
sizes:

```diff
--- a/src/Inspector/Output/MemoryOutput/Report/Substrate/GraphSubstrate.php
+++ b/src/Inspector/Output/MemoryOutput/Report/Substrate/GraphSubstrate.php
@@ load step ...
+        // Seed every known node with size 0 so the substrate's
+        // iterateNodeSizes() yields structural nodes too — matches
+        // FfiCsrGraphSubstrate.iterateNodeSizes which iterates all
+        // CSR slots regardless of allocation. Without this seed,
+        // CallStackPass and any other pass that locates a node by
+        // type via iterateNodeSizes silently misses 0-size
+        // structural nodes (CallFramesContext, ArrayElementContext,
+        // ScalarValueContext, ...).
+        $stmt = $db->query(
+            "SELECT node_id FROM context_nodes WHERE run_id = {$run_id}"
+        );
+        while (($node_id = $stmt->fetchColumn()) !== false) {
+            $substrate->node_sizes[(int)$node_id] = 0;
+        }
+
         foreach (chunked($db->query(... context_node_locations ...)) as $row) {
             $substrate->node_sizes[$row['node_id']] = ($substrate->node_sizes[$row['node_id']] ?? 0) + $row['size'];
             ...
         }
```

A unit test pinning the parity would just assert the sqlite-built
substrate's `iterateNodeSizes` yields exactly the same keys as
the rmem-built substrate's, then a `CallStackPass` integration
test that runs the pass against both substrates and asserts the
findings match.

## --memory-limit-error-{file,line,max-depth} feature

Verified via `tests/scripts/sqlite-validation/test_oom_feature.sh`.

The feature works correctly when given valid input. Pointing reli
at a stable target line:

```bash
inspector:memory -p $PID -f sqlite3 -o out.sqlite3 \
    --memory-limit-error-file=/abs/path/to/script.php \
    --memory-limit-error-line=28
```

adds **7 nodes / 13 edges / 13 attributes** to the captured graph,
walking back up to 3 (or `--max-depth`) frames from the requested
line. Each frame becomes a `CallFrameContext` node with
`function_name`, `lineno`, `filename` attributes. The frames are
chained off a `CallFramesContext` root via the
`memory_limit_call_frames` link, and the locals/`$this` of each
frame are reachable via `local_variables` / `this` edges. The
resulting SQLite is integrity-clean.

Validation rejects bad input properly:

| Input | rc | path |
|-------|----|------|
| `--max-depth=0`        | 1 | InspectorSettingsException ("not positive integer") |
| `--max-depth=-5`       | 1 | InspectorSettingsException                            |
| `--line=abc`           | 2 | InspectorSettingsException                            |

### UX gaps (not bugs, but worth flagging)

Three silent-no-effect cases where the user can't tell their hint
was ignored:

1. **`--memory-limit-error-file` set without
   `--memory-limit-error-line`**:

   ```bash
   inspector:memory -p $PID -f sqlite3 -o out.sqlite3 \
       --memory-limit-error-file=/abs/path/to/script.php
   ```

   Output is byte-identical to a baseline capture without any
   error-hint flags. No warning printed. The gating logic in
   `MemoryProfilerSettingsFromConsoleInput.php:117` requires
   *both* options non-null, otherwise the hint is silently
   inactive. A user that mistyped `--memory-limit-error-line` to
   another option name would assume the hint was applied when in
   fact it wasn't.

2. **Non-existent file path**:

   ```bash
   inspector:memory ... --memory-limit-error-file=/nonexistent/path.php \
                         --memory-limit-error-line=999
   ```

   Hint args are accepted, the VM-stack walk finds no matching
   frame in the target's runtime, no `CallFramesContext` is
   added, no warning printed. Output is baseline-shaped.

3. **Line beyond EOF**:

   ```bash
   inspector:memory ... --memory-limit-error-file=/real/path.php \
                         --memory-limit-error-line=99999
   ```

   Same: hint resolves to nothing, no warning, output is baseline.

### Suggested resolution

Either:

- **Add a `summary` key** like
  `memory_limit_error_resolved=true|false` so users can
  programmatically check whether the hint contributed anything
  to the graph. The marker should be present on every capture
  invoked with `--memory-limit-error-file=`, with `false` if
  resolution failed.

- **Print a stderr warning** when:
  - one of the two paired flags is set without the other
    (`--memory-limit-error-file` without `--memory-limit-error-line`)
  - the target's VM stack contains no frame matching the
    requested file:line

Both are friendlier than the current silent-degrade-to-baseline
behaviour.

## Test artefacts

- `tests/scripts/sqlite-validation/test_dump_analyze.sh` — dump
  → analyze pipeline parity matrix.
- `tests/scripts/sqlite-validation/test_json_parity.sh` +
  `tests/scripts/sqlite-validation/json_compare.py` — JSON output
  structural diff between 0.12.0 and PR.
- `tests/scripts/sqlite-validation/test_oom_feature.sh` —
  `--memory-limit-error-*` validation matrix.
- `tests/scripts/sqlite-validation/test_report_parity.sh` —
  rmem-driven vs sqlite-driven `inspector:memory:report` parity.
- `tests/scripts/sqlite-validation/targets/target_fibers_enums.php`,
  `target_long_strings.php`, `target_oom.php` — round-4
  reproductions.
