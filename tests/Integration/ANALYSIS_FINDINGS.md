# reli-prof Memory Analysis: Real-World Issue Investigation

## Investigated Issues

### 1. PrinsFrank/pdfparser#301 — CrossReference PREV Chain OOM

**Issue**: 2.5MB PDF causes OOM even with 1GB memory limit.

**reli-prof Result** (99.5% heap analyzed):
- `CrossReferenceEntryInUseObject`: **100,203 instances**, 7,045 KB (95.3% of all object memory)
- `CrossReferenceSection`: 501 instances
- `ZendStringMemoryLocation`: 27.66 MB (66.3% of heap) — backed enum values

**Verdict**: reli-prof immediately identified the root cause class and count.
**Difficulty for reli**: Easy — object-heavy, class name tells the story.

---

### 2. Webklex/php-imap#531 — Circular Reference Memory Leak

**Issue**: Message ↔ Attachment circular references prevent GC. `unset()` frees 0 bytes.

**reli-prof Result** (99.95% heap analyzed):
- `ZendStringMemoryLocation`: **151.62 MB** (86.8% of 174 MB heap)
- `ZendObjectMemoryLocation`: only 0.94 MB (0.5%)
- Top objects by count: `Attribute`(5,427), `Header`(1,005), `Part`(804), `Attachment`(603), `Message`(201)
- Context tree shows `Message → attachments → Attachment[0] → oMessage` path
- But `oMessage` shows as `type=?`, `node=?` — circular reference not explicitly flagged

**Verdict**: reli-prof CAN see the circular reference path in the context tree.
However:
1. The real memory hog is **strings** (raw email bodies stored redundantly in Message, Part, Attachment), not objects
2. You can't easily ask "which 20 strings are using the most memory?"
3. The circular reference is visible but not flagged — you have to manually trace the tree

**Difficulty for reli**: Medium — string-heavy, needs reverse lookup to diagnose efficiently.

---

### 3. smalot/pdfparser#735 — Font Table Memory Exhaustion

**Issue**: `mb_convert_encoding()` in Font.php causes OOM due to huge bfrange CMap tables.

**reli-prof Result** (99.75% heap analyzed):
- `ZendArrayTableMemoryLocation`: **22.60 MB** (86.0% of 26 MB heap)
- Only 235 arrays, but they are enormous hash tables
- `ZendStringMemoryLocation`: 1.86 MB (67,871 strings — the `$uchrCache` + `$table` entries)
- Objects: negligible (0.03 MB)

**Verdict**: reli-prof correctly shows arrays dominate memory.
However:
1. "235 arrays using 22.6 MB" — but **which** 235 arrays? No class/variable attribution
2. You can't distinguish `Font::$table` from `Font::$uchrCache` from other arrays
3. No "top 10 largest individual arrays" ranking

**Difficulty for reli**: Hard — array-heavy, class attribution missing for non-object allocations.

---

## reli-prof Improvement Proposals

### Correction: SQLite Output Already Has the Data

After testing with `--output-format=sqlite3`, it turned out the existing SQLite output
combined with the built-in views (`v_node_paths`, `v_arrays`) **already supports all
the queries we initially thought were missing**:

```sql
-- Top arrays with full context path (smalot/pdfparser case)
SELECT a.total_size, np.path
FROM v_arrays a JOIN v_node_paths np ON np.node_id = a.node_id
ORDER BY a.total_size DESC LIMIT 10;

-- Result:
-- 2.62 MB  class_table -> smalot\pdfparser\font -> static_properties -> uchrCache
-- 1.05 MB  ... -> fonts -> F1 -> ... -> table
-- 1.05 MB  ... -> fonts -> F2 -> ... -> table

-- Top strings with context path (php-imap case)
SELECT cnl.size, substr(cnl.string_value, 1, 60), np.path
FROM context_node_locations cnl JOIN v_node_paths np ON np.node_id = cnl.node_id
WHERE cnl.location_type = 'ZendStringMemoryLocation'
ORDER BY cnl.size DESC LIMIT 10;

-- Result:
-- 210 KB  "From: sender@..."  ... -> structure -> object_properties -> raw
-- 210 KB  "From: sender@..."  ... -> structure -> object_properties -> raw

-- Circular references (php-imap case)
SELECT e.link_name, parent_np.path, child_np.path
FROM context_edges e
JOIN v_node_paths parent_np ON parent_np.node_id = e.parent_node_id
JOIN v_node_paths child_np ON child_np.node_id = e.child_node_id
WHERE e.is_tree = 0 AND e.link_name = 'oMessage';

-- Result:
-- oMessage  Message[0] -> attachments -> items[0] -> oMessage  →  Message[0]
```

---

### 4. simplepie/simplepie#874 — GC-Related Memory Leak

**Issue**: SimplePie feeds parsed sequentially leak memory. `__destruct()` only
cleans references when `gc_enabled()` returns false (i.e., never under default config).

**Reproduction**: Parse 10 feeds with 500 items each, unset after each parse.
Memory grows from 4MB to 32MB and stays at 32MB even after `gc_collect_cycles()`.

**reli-prof Result** (SQLite output, 99.15% analyzed):
- Heap usage: 9.58 MB (of 32MB real allocation — the rest is cached but freed chunks)
- `ZendStringMemoryLocation`: 4.01 MB (9,023 strings)
- `ZendArrayTableMemoryLocation`: 1.41 MB (8,614 arrays)
- 500 `SimplePie\Item` objects still alive (50.78 KB)
- Only 1 `SimplePie\SimplePie` object

**Key queries on SQLite:**
```sql
-- Circular references: Item::$feed → SimplePie (500 back-edges)
SELECT e.link_name, substr(pnp.path,1,100), substr(cnp.path,1,100)
FROM context_edges e
JOIN v_node_paths pnp ON pnp.node_id = e.parent_node_id
JOIN v_node_paths cnp ON cnp.node_id = e.child_node_id
WHERE e.is_tree = 0 AND e.link_name = 'feed';

-- Result: 500 Item objects all hold a `feed` back-reference to the parent SimplePie
-- Item[0]->feed → SimplePie, Item[1]->feed → SimplePie, ...

-- Top arrays: feed->data holds items/ordered_items arrays
SELECT round(a.total_size/1024.0,2) as kb, np.path
FROM v_arrays a JOIN v_node_paths np ON np.node_id = a.node_id
ORDER BY a.total_size DESC LIMIT 5;

-- Result:
-- 7.88 KB  ...feed->data->items (500 elements)
-- 7.88 KB  ...feed->data->ordered_items (500 elements)
```

**Verdict**: reli-prof correctly shows 500 Item objects alive with `feed` back-references.
The `!gc_enabled()` check in `__destruct()` is the root cause — with GC enabled,
`Item::$feed` is never unset, so the parent SimplePie and all its data persist.

---

### 5. envms/fluentpdo#337 — PDO Reference Count Leak (NOT REPRODUCED)

**Issue**: FluentPDO inserts cause PDO connection refcount to grow to 103.

**Result**: On PHP 8.4 with SQLite backend, no memory difference between raw PDO
and FluentPDO inserts (both stable at 2MB). 2,600 cycles collected by GC but
no observable leak. The issue may be fixed in current versions or MySQL-specific.

---

### Revised Proposals (What's Actually Needed)

**Problem**: JSON output is 70-140MB for a 26-46MB process. This makes the output
hard to work with, slow to parse, and impractical for CI/automated analysis.

**Proposal**: Add `--output-format=summary` that emits only:
1. Memory summary (existing)
2. Location type summary (existing)
3. Class object summary (existing)
4. **NEW**: Top N allocations with context paths (see P1)
5. **NEW**: Circular reference report (see P2)

Skip the full context tree. This would reduce output from 140MB to ~10KB.

For the full tree, consider:
- `--output-format=sqlite3` (already exists) which is the right tool for detailed queries

---

### P1: CLI Summary with Top Allocations (High Impact, Low Effort)

**Problem**: JSON output is 70-140MB, SQLite requires manual SQL queries.
Users need a quick CLI-friendly summary with actionable information.

**Proposal**: Add `--output-format=top` (or a subcommand) that emits a compact
text report to stdout:

```
=== Top 10 Arrays by Size ===
  2.62 MB  class_table -> Font -> static_properties -> uchrCache
  1.05 MB  ... -> fonts -> F1 -> table
  ...

=== Top 10 Strings by Size ===
  210 KB   ... -> structure -> raw

=== Circular References ===
  Message -> attachments -> items[0] -> oMessage → Message (×603)
```

**Implementation**: Use SQLite in-memory (or the existing PdoContextTreeSink),
then run the v_node_paths / v_arrays queries internally and format to text.
Minimal new code — just a new output formatter that wraps existing SQL views.

---

### P2: Bundled Diagnostic SQL Queries (Medium Impact, Very Low Effort)

**Problem**: The SQL views exist but users need to know the right queries.

**Proposal**: Ship a `docs/memory-profiler-queries.md` with copy-paste SQL recipes,
or add `inspector:memory:query` subcommand with presets like `--top-arrays`,
`--top-strings`, `--circular-refs`.

---

### P3: JSON Compact Mode (Medium Impact, Low Effort)

**Problem**: Full JSON context tree is 70-140MB, mainly useful for tooling.
Most users only need summary + top allocations.

**Proposal**: Default `--output-format=json` emits only summary/types/classes.
`--output-format=json-full` for the current full tree.

---

### P4: Diff/Snapshot Comparison (Medium Impact, Medium Effort)

**Problem**: For gradual memory leaks (inbox fetch loops), comparing two snapshots
would show growth patterns.

**Proposal**: `inspector:memory:diff --before=snap1.sqlite3 --after=snap2.sqlite3`

---

## Revised Priority Matrix

| Proposal | Impact | Effort | Priority |
|----------|--------|--------|----------|
| P1: CLI summary with top allocations | High | Low | **1st** |
| P2: Bundled diagnostic queries/docs | Medium | Very Low | **2nd** |
| P3: JSON compact mode | Medium | Low | **3rd** |
| P4: Diff/snapshot comparison | Medium | Medium | **4th** |
