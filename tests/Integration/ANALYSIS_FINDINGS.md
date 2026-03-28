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

### P1: Array/String Attribution by Owner (High Impact)

**Problem**: When arrays or strings dominate memory (cases #2 and #3), you only see
aggregate counts. You can't answer "which object's property holds the 22MB array?"

**Proposal**: Add a `--top-allocations=N` mode that ranks individual allocations by size
and shows the reference context path for each:

```
Top 10 memory allocations:
  #1  ZendArray   2.12 MB  Font#4_0->table (Font::$table)
  #2  ZendArray   2.12 MB  Font#6_0->table (Font::$table)
  #3  ZendString  1.05 MB  Message#1->raw_body
  #4  ZendString  1.05 MB  Message#2->raw_body
  ...
```

This would immediately solve cases #2 and #3 where the aggregate summary says
"arrays use 22MB" but doesn't tell you which specific array or who owns it.

**Implementation**: During `MemoryLocationsCollector::collectAll()`, maintain a
max-heap of the N largest allocations encountered. For each, record the current
context path. Emit as a separate output section.

---

### P2: Circular Reference Detection and Reporting (Medium Impact)

**Problem**: The context tree handles circular refs to prevent infinite recursion
(via `#reference_node_id`), but doesn't report them as a diagnostic finding.

**Proposal**: When `ContextAnalyzer` detects a back-reference (existing_node_id != null),
record it as a potential circular reference. In the output, add a section:

```
Circular References Detected:
  Message#95335 → attachments → AttachmentCollection#95718 → items[0] → Attachment#95719 → oMessage → Message#95335
  Total memory reachable from cycle: 876 KB
```

**Implementation**: In `ContextAnalyzer::analyze()`, when emitting a reference to an
already-visited node, check if the reference creates a cycle (current node is a
descendant of the target). If so, record the path. After tree walk, emit cycle report.

---

### P3: Compact Output Mode (High Impact, Usability)

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
- `--output-format=json-compact` that deduplicates locations and uses references
- `--output-format=sqlite3` (already exists) which is better for querying

---

### P4: String Deduplication Report (Medium Impact)

**Problem**: In case #2, strings account for 151MB. Many are duplicated
(same email body stored in Message->raw_body, Part->content, etc.)

**Proposal**: Add a `--string-dedup-report` option that groups strings by value hash
and reports duplicates:

```
Duplicate String Groups (by total memory):
  Hash abc123: 3 copies, 1.05 MB each = 3.15 MB total
    - Message#1->raw_body
    - Part#3->content
    - Attachment#7->content
```

**Implementation**: During string collection, hash string values (first 64 bytes +
length as key). Group by hash. For groups with refcount == 1 but multiple locations,
report as "semantic duplicates" (different zend_string allocations with same content).

---

### P5: Diff/Snapshot Comparison Mode (Medium Impact)

**Problem**: For gradual memory leaks (case #2's inbox fetch loop), you want to
compare "before iteration N" vs "after iteration N" to see what grew.

**Proposal**: Add `inspector:memory:diff` that takes two snapshots and shows:
- New allocations by type/class
- Growth per class
- New circular references

**Implementation**: Save two JSON/SQLite snapshots, compare summaries.
This is largely a post-processing tool, possibly operating on SQLite output.

---

### P6: Non-Object Array Classification (Low-Medium Impact)

**Problem**: In case #3, 235 arrays consume 22.6MB but have no class attribution.
The `ObjectClassAnalyzer` only works for objects.

**Proposal**: For arrays that are reachable as object properties, attribute them
to the owning class + property name. For example:

```
Array Memory by Owner:
  Smalot\PdfParser\Font::$table          20 arrays,  21.20 MB
  Smalot\PdfParser\Font::$uchrCache       1 array,    1.40 MB
  (unattributed)                        214 arrays,   0.00 MB
```

**Implementation**: When traversing object properties in MemoryLocationsCollector,
if the property value is an array, tag the array location with the class+property.
Add a new `ArrayOwnerAnalyzer` similar to `ObjectClassAnalyzer`.

---

## Priority Matrix

| Proposal | Impact | Effort | Priority |
|----------|--------|--------|----------|
| P1: Top allocations with context | High | Medium | **1st** |
| P3: Compact output mode | High | Low | **2nd** |
| P6: Array classification by owner | Medium | Medium | **3rd** |
| P2: Circular reference reporting | Medium | Medium | **4th** |
| P4: String dedup report | Medium | Low | **5th** |
| P5: Diff/snapshot mode | Medium | High | **6th** |
