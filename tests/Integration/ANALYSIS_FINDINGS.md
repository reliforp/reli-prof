# reli-prof Memory Analysis: Real-World Issue Investigation

## Prior Art

reli-prof has been used for real-world memory diagnostics since v0.11:
- [psalm#10522](https://github.com/vimeo/psalm/issues/10522#issuecomment-1881729504):
  `unserialize()` dynamic property table overhead (255K strings = 159MB)
- [PhpSpreadsheet#3814](https://github.com/PHPOffice/PhpSpreadsheet/issues/3814#issuecomment-1862367771):
  `toArray()` allocating 1M×1K null array (16GB needed, fixable to 32MB via CoW)
- [smalot/pdfparser#631](https://github.com/smalot/pdfparser/issues/631#issuecomment-1847772214):
  `Font::$uchrCache` static cache = 42MB (1M items)

Those analyses used JSON output with manual tree traversal. The investigation
below uses the newer SQLite output with SQL queries, which is significantly
faster for diagnosis.

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

---

### 6. dompdf — HTML-to-PDF Memory Amplification (610x)

**Issue**: dompdf uses ~610x more memory than input HTML size.
631KB HTML → 384.5MB memory. Even after `unset()` + `gc_collect_cycles()`, 382MB remains.

**reli-prof Result** (99.19% of 240MB heap analyzed):

| Type | Count | Memory |
|---|---|---|
| ZendArrayTableMemoryLocation | 447,262 | 66.93 MB |
| ZendArrayTableOverheadMemoryLocation | 447,105 | 55.51 MB |
| ZendObjectMemoryLocation | **179,071** | 48.64 MB |
| ZendArrayMemoryLocation | 447,182 | 23.88 MB |
| ZendReferenceMemoryLocation | 337,406 | 10.30 MB |

**Top classes by memory:**

| Class | Count | Memory |
|---|---|---|
| `Dompdf\Frame` | 50,721 | 14,661 KB |
| `Dompdf\FrameDecorator\Text` | 23,501 | 12,301 KB |
| `Dompdf\FrameDecorator\TableCell` | 20,400 | 10,996 KB |
| `Dompdf\LineBox` | 26,530 | 6,425 KB |
| `Dompdf\FrameDecorator\TableRow` | 5,100 | 2,430 KB |
| `DOMElement` | 27,300 | 1,066 KB |

**Diagnosis**: dompdf creates a `Frame` for every DOM node, then wraps each in a
`FrameDecorator` (Text/TableCell/Block/etc), and creates a `LineBox` for each text
line. 100 tables × 50 rows × 4 cells = 20,000 TableCells alone. Each Frame holds
multiple arrays (style, position, children), explaining the 447K array allocations.

**Note**: The v_node_paths recursive CTE failed (SQLite file reached 1GB) because
the object graph is too deep/wide (179K objects). This reveals a **reli-prof
scalability issue**: the SQLite output's recursive path view doesn't scale beyond
~100K objects.

**New improvement idea**: Add `LIMIT` or depth control to `v_node_paths`, or provide
a non-recursive alternative for large processes.

---

### 7. CuyZ/Valinor#680 — AsConverter OOM (NOT REPRODUCED)

The `#[AsConverter]` attribute isn't in the stable release (v1.17). The issue is
specific to a dev-next feature. Without the attribute, 10K mappings use only 4MB.

---

### 8. Symfony StreamedJsonResponse#60257 (SKIPPED)

Transient memory spike during `json_encode()` — not a persistent allocation that
reli-prof can meaningfully capture. The issue is about output buffering design, not
object accumulation.

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

---

### 9. PHPOffice/PhpSpreadsheet — Cell Object Overhead (514 bytes/cell)

**Scenario**: Create a 10,000×20 spreadsheet (200,000 cells).
Result: 100MB for a 1.88MB XLSX file (53x amplification).

**reli-prof Result** (99.87% of 101MB analyzed):

| Class | Count | Memory |
|---|---|---|
| `Cell\Cell` | **200,020** | 29,690 KB |
| `Cell\IgnoredErrors` | **200,020** | 23,440 KB |

**Key Finding**: Every Cell automatically creates an `IgnoredErrors` companion object
(120 bytes each), adding 23MB of overhead that's almost never used. The Cell itself
is 152 bytes. Together: 272 bytes/cell in object overhead alone.

**Type breakdown**: Objects 51.9MB + Arrays 17.4MB + Strings 15.4MB = 84.7MB out of 101MB.

**Recommendation**: `IgnoredErrors` should be lazy-initialized (null until first use).
This alone would save 23% of total memory (23MB out of 100MB).

---

### 10. FileEye/pel#29 — EXIF Memory Leak (NOT REPRODUCED)

On pel v0.10, processing 30 JPEG files (6.4MB each) shows stable 2MB memory
with peak 8MB. The stream-based fix appears to have resolved the original issue.

---

### 11. phpstan/phpstan#8082 — Trait Self-Reference Infinite Recursion

**Issue**: `trait TraitUsesSelf { use TraitUsesSelf; }` causes PHPStan to loop forever.
Still reproducible on PHPStan 2.1.44.

**reli-prof Result** (93.84% of 84MB analyzed, captured mid-recursion):

| Class | Count | Memory |
|---|---|---|
| `BetterReflection\Reflection\Adapter\ReflectionClass` | **213,009** | 14,977 KB |

PHPStan generates **213K ReflectionClass adapter objects** trying to resolve the circular
trait reference. The next biggest class (`Closure`) has only 972 instances.

This is the quintessential reli-prof use case: a live process spinning at 100% CPU,
and reli-prof attaches from outside to instantly show what's accumulating.

**Also tested**: PHPStan#8835 (`self::X` in `@implements`) — fixed in v2.1, no longer OOMs.

---

### 12. phpstan/phpstan#8835 — self:: in @implements (FIXED in v2.1)

No longer reproduces. PHPStan 2.1.44 handles `@implements I<self::X>` correctly.

---

### 13. league/commonmark — AST Node Overhead (544K objects for 2.4MB Markdown)

**Scenario**: Parse 2,440KB Markdown (2,000 sections with GFM) → 93MB memory (38x).

**reli-prof Result** (99.89% of 98MB analyzed):

| Class | Count | Memory |
|---|---|---|
| `Dflydev\DotAccessData\Data` | **246,007** | 13,454 KB |
| `League\CommonMark\Node\Inline\Text` | 116,002 | 19,032 KB |
| `League\CommonMark\Extension\Table\TableCell` | 36,000 | 7,594 KB |
| `League\CommonMark\Node\Block\Paragraph` | 18,001 | 3,516 KB |

**Key Finding**: `Dflydev\DotAccessData\Data` — a configuration/metadata accessor —
has 246K instances (most in the entire process). Each AST node appears to create one.
This is similar to PhpSpreadsheet's IgnoredErrors: a companion object that's always
created but rarely needed. Lazy initialization would save ~13MB (14% of total).

**Also notable**: 544,187 total objects. The reli-prof JSON output OOMed at 2GB trying
to build the context tree. SQLite output worked fine (streams to disk).

---

### 16. mpdf/mpdf#1046 — Font Cache Leak (GC resolves on PHP 8.4)

**Issue**: Each Mpdf instance duplicates font cache; memory grows ~1.6MB per PDF.

**Result**: After 20 PDFs: `memory_get_usage(true)` = 34MB but
`memory_get_usage(false)` = **10.97 MB**. GC collected 27,400 cycles.
The leak is resolved by GC, but ZendMM doesn't return chunks to OS.

reli-prof confirmed: only 11MB heap usage (96% analyzed), dominated by
`ZendOpArrayBody` (6.72 MB — mpdf's own compiled code).

**Lesson**: `memory_get_usage(true)` can be misleading. reli-prof's heap analysis
shows the actual usage, not the OS-level allocation.

---

### 17. serialize/unserialize Memory Amplification

**Scenario**: 87K stdClass tree, serialized = 16.65MB. Unserialize adds only ~10MB.

reli-prof found two separate stdClass populations (87K + 5K) — the original and
unserialized copies. The "4x amplification" from wikimedia/less.php#104 may be
specific to deeply nested objects with many internal references, or was a PHP <8.4
behavior.

---

### 14. nikic/PHP-Parser — Token Objects Outnumber AST Nodes 2:1

**Scenario**: Parse 95KB PHP code (30 classes × 10 methods) → 16MB (14K AST nodes).

**reli-prof Result** (99.2% of 14.3MB analyzed):

| Class | Count | Memory |
|---|---|---|
| `PhpParser\Token` | **32,493** | 3,300 KB |
| `PhpParser\Node\Expr\Variable` | 3,600 | 253 KB |
| `PhpParser\Node\Identifier` | 1,380 | 97 KB |

**Key Finding**: Token objects (32K) outnumber AST nodes (14K) by 2.3×. PHP-Parser v5
keeps tokens attached to nodes for position/comment information. Objects (4.54 MB)
and arrays (4.51 MB) are roughly equal — each Node has an `attributes` array.

**Scalability note**: At 200 classes × 20 methods (185K AST nodes, 162MB), reli-prof's
context tree builder OOMs even at 4GB. Another strong motivator for P6 (summary-only).

---

### 15. Twig — Compiled Bytecode Dominates (op_array pattern)

**Scenario**: Compile and render 5,000 unique Twig templates → 108.5MB (22KB/template).

**reli-prof Result** (SQLite):

| Type | Count | Memory |
|---|---|---|
| `ZendOpArrayBodyMemoryLocation` | **30,860** | **45.42 MB** |
| `ZendArrayTableMemoryLocation` | 45,431 | 13.73 MB |
| `ZendStringMemoryLocation` | 144,788 | 9.02 MB |
| `ZendOpArrayHeaderMemoryLocation` | 30,860 | 7.53 MB |

**New pattern**: The dominant memory consumer isn't objects, arrays, or strings —
it's **compiled PHP bytecode** (op_array body + header = 53MB, 50% of total).
Each template compiles to a PHP class with ~6 methods, creating 30K op_arrays.

Only 25K objects total (ConstantExpression 10K + Source 10K). The rest is code.

**Insight for reli-prof**: The `location_types_summary` correctly identifies this,
but the `class_objects_summary` only shows live objects. A "compiled classes summary"
(grouping op_arrays by their owning class) would make this pattern more visible.

---

## Self-Diagnosing OOM via register_shutdown_function

As documented in `docs/memory-profiler.md`, reli-prof can analyze the crashing
process FROM WITHIN a shutdown function. We verified this works:

```php
register_shutdown_function(function () {
    $error = error_get_last();
    if (strpos($error['message'], 'Allowed memory size of') !== 0) return;
    ini_set('memory_limit', '-1'); // raise OUR limit for JSON parsing
    $pid = getmypid();
    system("php -d memory_limit=2G reli inspector:memory -p {$pid} "
        . "--no-stop-process --memory-limit-error-file='{$error['file']}' "
        . "--memory-limit-error-line='{$error['line']}' > oom_analysis.json");
});
```

**Result** (8MB limit, 9,991 Record objects):
```
=== OOM DETECTED ===
  Heap usage: 7.71 MB
  9,991 × Record                1014.7 KB
  30,208 × ZendStringMemory     3.35 MB
```

**Caveat**: For large targets (dompdf with 179K objects), the reli-prof
context tree builder itself OOMs even at 2GB. The `--memory-limit-error-file`
option helps focus the analysis, but the core issue is that reference tree
expansion scales with the number of objects in the target.

**Improvement idea (P6)**: A lightweight "summary-only" analysis mode that
skips the full context tree and only computes type/class summaries. This would
make self-diagnosis viable even for large processes.

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

### 21. PHPUnit 10 — Clean (no accumulation)

3,000 tests: 20MB, mostly bytecode (6MB for 8,190 op_arrays).
Only ~50 objects after test run. PHPUnit 10's Event System doesn't
accumulate test results in memory. Good design.

---

### 22. webonyx/graphql-php — Closure-Heavy but Compact

200 types × 20 fields = 4,000 fields: only 10MB.
4,217 Closures (resolvers) dominate objects at 1,417 KB.
GraphQL-PHP is memory-efficient.

---

### 25. PHP_CodeSniffer — Clean (streaming design)

phpcs on reli-prof's own src/: 26MB, mostly bytecode (2.44MB) + strings (2.19MB).
Each Sniff class has exactly 1 instance. Files are tokenized and processed
individually then released. Good streaming design.

---

### 26. Guzzle PSR-7 — Stream Buffers Outside reli Tracking

**Scenario**: 100 PSR-7 Response objects with 500KB bodies each → 58MB.

**reli-prof Result**:
- `memory_get_usage`: 52.8 MB
- `zend_mm_heap_usage`: **4.5 MB** (reli reads this from ZendMM)
- `analyzed`: **8.6%** (of 4.5MB only)
- 100 `Stream` objects (14.8 KB) + 100 `Response` objects (13.3 KB)

The ~48MB gap between `memory_get_usage` and `zend_mm_heap_usage` is
`php://temp` stream buffers. These go through ZendMM's emalloc but
are accounted differently from the tracked heap structures.

**Important for reli-prof**: This is a case where `memory_get_usage`
reports high memory but reli sees almost nothing. The unaccounted
memory pass (Pass 1b) would flag this: "52.8 MB used but only 4.5 MB
in tracked heap — 91% of memory is in non-tracked allocations."

Users encountering this pattern should look for:
- PSR-7 stream bodies held in memory (use `php://temp/maxmemory:0` or streaming)
- file_get_contents() results held in variables
- Extension-level buffers (curl multi handles, etc.)

---

### 27. PHPOffice/PhpWord — Style Companion Pattern (52% overhead)

50 sections × 100 paragraphs → 44MB.

| Class | Count | Memory | Ratio |
|---|---|---|---|
| `Style\Paragraph` | **21,750** | 14,104 KB | **1:1 with every text element** |
| `Style\Font` | **16,750** | 9,029 KB | **1:1 with every Text** |
| `Element\Text` | 16,750 | 5,365 KB | The actual content |

Same companion pattern as PhpSpreadsheet IgnoredErrors: every Text creates
a dedicated Style\Paragraph (664B) + Style\Font (552B) = 1,216 bytes of style
overhead per text element. Style accounts for 52% of total memory.

Lazy init or shared default styles would cut 23.1 MB.

---

### 28. league/csv — Clean (user-side accumulation)

50K rows × 20 cols → 116MB. Library has ~0 objects (Reader 1 + Writer 1).
Memory is 100% user data: 1M strings (45MB) + 50K arrays (62MB).
The per-row cost (2,391B) is inherent to holding all data in PHP arrays.

---

### 29. Composer — Bytecode-Dominated (phar overhead)

93-package `update --dry-run`: 42MB. Bytecode (5MB) is largest type.
639 CompletePackage (504KB), 2,906 Link (341KB), 2,663 Constraint (312KB).
Efficient design — object overhead is only 1.39MB.

---

### 30. DOMDocument / SimpleXML — C Extension Memory (invisible to reli)

50K XML elements (8.5MB): DOMDocument and SimpleXML store data in C extension
memory, not PHP heap. reli sees almost nothing (Delta -8.5MB because XML string
was freed). Same pattern as Guzzle's php://temp streams.

---

### 31. json_decode — Array Overhead Amplification (4.4x)

100K items (12MB JSON) → json_decode produces 54MB of PHP arrays.
1.2M strings (45.8MB) + 300K arrays (92MB) = 138MB total.
Per-item: 566 bytes. Arrays dominate at 67%.

This is not a bug — it's the inherent cost of PHP's array representation.
Each associative array has: ZendArray header (56B) + HashTable bucket array
(variable) + overhead (alignment). For small arrays like `{id, name, email,
tags, meta}`, the overhead ratio is high.

---

### 32. Laravel — Bytecode-Dominated Boot, Efficient DI

Empty Laravel app (fresh install, 20 requests):
- opcache off: 22 MB (52% bytecode = 11.13 MB, 11,098 op_arrays)
- opcache on: 10 MB (bytecode moves to SHM)
- No leak after 20 requests

Only 150 Closures + ~50 singleton service objects. DI container is efficient.
Laravel's memory cost is dominated by framework code loading, not object overhead.

---

### 33. Eloquent ORM — 1,678 bytes per Model (array overhead)

10K User::all() → +16MB (model あたり 1,678 bytes).
Object: 586B. Arrays (~700B for $attributes/$original/$casts/etc).
Strings: ~390B (column values).

Each Model holds 6-7 internal arrays, most empty. Empty PHP arrays cost
56 bytes each → ~400B of array header overhead per model. For 100K models
this becomes 4MB of pure empty-array overhead.

No companion pattern, but the array overhead echoes the Symfony Forms
OptionsResolver issue — many internal arrays per instance that could be
lazily initialized or shared.

---

### 34. Symfony Skeleton Boot — 6 MB (lighter than Laravel)

Symfony skeleton boot: 6MB vs Laravel 8MB. Symfony's compiled DI container
means fewer runtime objects. (Request handling failed due to missing env vars
in the test setup, but boot-only comparison is valid.)

---

### 35. PHP Data Structure Comparison — Array vs SplFixedArray vs Objects

500K elements:
| Structure | Per-element | Total |
|---|---|---|
| Regular array (int) | 17 bytes | 8 MB |
| SplFixedArray (int) | 16 bytes | 7.6 MB |
| Array of Tiny objects | **80 bytes** | **38 MB** |

SplFixedArray offers no advantage on PHP 8.4 (packed arrays are already efficient).
Object wrapping costs 4.7x more per element. reli confirms: 500K Tiny objects =
26.7MB (ZendObject) + 4MB (ObjectsStore) + 7.6MB (container array).

---

### 36. PHP Fibers — VM Stack Cost (17 KB/fiber)

5,000 suspended Fibers → 90 MB. Per fiber: 18,455 bytes (17,197 from VM stack).

**After 0.12.x Fiber support:** reli now creates `FiberContext` nodes with
`call_frames` for each suspended Fiber. analyzed improved from 7% → 16%.
The remaining gap is the Fiber VM stack memory itself (not yet counted in
heap usage, though the call stack structure is now fully visible).

---

### 37. PHP Generators — 22x Cheaper Than Fibers

10,000 suspended generators → 10 MB. Per generator: 839 bytes.

**After 0.12.x Generator support:** reli now creates `GeneratorContext` nodes
with `call_frames` + `key` for each suspended Generator.
analyzed improved from 49% → **129%** (full coverage, exceeding memory_get_usage
due to ZendMM internal accounting differences).

---

### 38. WeakMap — Works Correctly, Internal Table Partially Tracked

10K entries → 8 MB (629 bytes/entry). After releasing 5K keys:
- WeakMap correctly drops to 5,000 entries
- reli sees 5,000 Key + 5,000 Value objects ✅
- analyzed: 69.8% — WeakMap's internal hash table is the 30% gap
- WeakMap object itself: 40 bytes

reli correctly walks through WeakMap entries and shows only live (non-GCed)
key-value pairs. The internal `zend_weakmap` hash table is not yet tracked
as a named allocation.

---

### 39. Closure Memory Cost — 419 bytes minimum, op_array per instance

| Type | Per closure | Key insight |
|---|---|---|
| Empty `fn(){}` | 419 bytes | Object (328B) + op_array header (256B) + body (112B) |
| Capturing int | 377 bytes | Slightly cheaper (shared op_array?) |
| Capturing 1KB string | 1,258 bytes | + string copy |
| Arrow function | 797 bytes | 2x vs `use()` — implicit capture overhead |

**Critical finding:** Each Closure gets its own `op_array` (256B header + 112B body
= 368B). Even identical closures don't share op_arrays. For Symfony Forms with
3,619 closures: 3,619 × 368 = 1.3 MB just in op_array duplication.

ArrayTableOverhead (22 MB for 100K closures) is the other major cost — each
closure's captured-variables storage has PHP array overhead even for 0-1 captures.

### 40. Reflection API — Zero PHP Heap Cost

282 classes reflected: 0 bytes delta. ReflectionClass wraps C-level class_entry
directly. No PHP object graph created for method/property metadata.

### 41. Regex preg_match_all — Array + String Amplification

30K matches from 176 KB subject → +18 MB. Each match creates arrays (capture
groups) + string copies. 30K arrays (6.4 MB) + 150K strings (4.2 MB).

---

### 25. symfony/symfony#57328 — OptionsResolver Closure/Clone Overhead

**Issue**: Nested Symfony Forms consume hundreds of MB. Maintainer said "nothing
we could change." Reporter found `upload_max_size_message` Closure saves 20%.

**reli-prof Result** (200 sub-forms × 8 fields = 1,600 fields → 28MB, 17.5 KB/field):

| Type | Count | Memory |
|---|---|---|
| ZendArrayTable | 26,193 | 11.00 MB |
| ZendArrayTableOverhead | 26,001 | 6.28 MB |
| ZendObject | 20,152 | 4.59 MB |

| Class | Count | Per-field |
|---|---|---|
| **Closure** | **3,619** | **2.26/field** |
| FormBuilder | 3,611 | 2.26/field |
| OptionsResolver | 1,806 | 1.13/field |
| EventDispatcher | 1,810 | 1.13/field |
| Form | 1,801 | 1.13/field |

**What holds the 3,619 Closures:**
- `value` (OptionsResolver defaults): 2,214
- `emptyData` option: **1,402** ← `fn() => ''` per field, could be a string

**Diagnosis**: Arrays dominate (17.3MB = 63%). Each field creates ~16 arrays
(OptionsResolver internals: `$defaults`, `$required`, `$defined`, `$normalizers`,
`$allowedValues`, `$allowedTypes`, `$lazy`, etc.) plus the Closure overhead.

**Concrete optimization targets**:
1. `empty_data` default: replace `fn() => ''` with `''` — saves 1,402 Closures
2. OptionsResolver: share immutable option sets across same-type fields instead
   of cloning per field
3. Lazy OptionsResolver initialization: don't resolve until form is submitted

At 17.5 KB/field, 10K fields = 175MB, 30K fields = 525MB — matching the
reporter's "hundreds of MB" observation.

---

### 23. PHP-DI Container — Efficient (460 bytes/service)

1,000 services with autowiring: 8MB total. Each service definition:
AutowireDefinition (164B) + Helper (120B) + MethodInjection (72B) + References.
Resolved service instances are held weakly — only a few visible in reli.

---

### 24. Doctrine DBAL QueryBuilder — 3,146 bytes per QB

10K QueryBuilders: 32MB. Per QB: 4 objects (QB + Join + From + CompositeExpression
= 656 bytes) + 8 arrays (~2,490 bytes). Arrays dominate (20MB of 30MB).

**Nextcloud #59018 context**: 3,146 bytes/QB × 1.6M QBs = 5GB. A cron job
that creates QBs in a loop without releasing them would hit 5GB after ~1.6M iterations.

---

### 18. Symfony Serializer — Transient Normalization Arrays

**Scenario**: 10K Orders (50K items, 10K addresses) → serialize to JSON.
Objects: 22MB. After serialize: 55MB. JSON: 5.28MB.

**reli-prof** found 70K objects (7.87MB) + 143K strings (9.96MB) = ~22MB.
The extra 33MB (normalization intermediate arrays) was already freed before
reli-prof attached. Peak 58MB → final 55MB.

**Limitation**: reli-prof captures a snapshot, not a timeline. Transient
allocations during `serialize()` → `normalize()` → `json_encode()` aren't
visible post-call. Would need `inspector:memory` triggered at peak, or
repeated sampling via `inspector:trace` style.

---

### 19. Intervention/image v3 — Clean (no leak)

50 images processed (create → resize → encode): stable at 2MB.
Only 5 objects alive. Intervention v3 properly releases GD resources.

---

### 20. Monolog BufferHandler — 1.87 KB per Log Entry

**Scenario**: 100K log entries with context data via BufferHandler → 184MB.

**reli-prof Result**:

| Type | Count | Memory |
|---|---|---|
| ZendArrayTableOverhead | 293,429 | 51.09 MB |
| ZendArrayTable | 293,466 | 41.34 MB |
| ZendString | 334,975 | 20.22 MB |
| ZendObject | 200,015 | 19.84 MB |

| Class | Count | Memory |
|---|---|---|
| `Monolog\LogRecord` | 100,000 | 14,844 KB |
| `Monolog\JsonSerializableDateTimeImmutable` | 100,000 | 5,469 KB |

Each log entry creates: 1 LogRecord + 1 DateTimeImmutable + ~3 arrays
(`$context`, `$extra`, internal). **Arrays dominate (92MB of 184MB = 50%)**.

This is a design pattern issue, not a bug: BufferHandler intentionally accumulates.
But reli-prof quantifies the cost precisely — 1,867 bytes per entry.

---

### P5: Scalable Path Queries (Medium Impact, discovered via dompdf)

**Problem**: `v_node_paths` uses a recursive CTE that generates a row for every
reachable node. For dompdf (179K objects, 447K arrays), the SQLite file reached
1GB and the CTE failed with "database or disk is full".

**Proposal**: Provide a non-recursive "walk N levels up" query instead of
pre-computing all paths. Example from PHP-Parser analysis:

```sql
-- Walk up 3 levels from the biggest array — no recursion needed
SELECT e1.link_name, e2.link_name, e3.link_name, e4.link_name
FROM target_node t
JOIN context_edges e1 ON e1.child_node_id = t.node_id
LEFT JOIN context_edges e2 ON e2.child_node_id = e1.parent_node_id
...
-- Result: "parser → object_properties → tokens → array_elements"
```

This correctly identified the 6.5MB token array in PHP-Parser without any recursion.

---

### P6: Reduce Collection-Phase Memory (High Impact, discovered via PHP-Parser)

**Problem**: reli-prof's `MemoryLocationsCollector` creates `ReferenceContext` +
`MemoryLocation` objects for every zval in the target. For PHP-Parser (185K AST
nodes), this requires **6GB** for reli-prof itself (37× the target's 162MB).

**Root causes**:
1. `ArrayElementContext` is created per array element (420K tokens → 420K contexts)
2. `ZendStringMemoryLocation::$value` stores the full string content
3. Each `ReferenceContext` holds a `$referencing_contexts` PHP array (~120-150 bytes)

**Proposals (information-preserving)**:

| Idea | Savings | Difficulty |
|---|---|---|
| **Lazy string value**: only store strings >N bytes on demand | ~30-50% for string-heavy targets | Low |
| **Streaming context tree to SQLite during collection**: instead of building the full tree in memory, emit edges/nodes to SQLite as they're discovered | ~60-80% | Medium-High |
| **Flat array instead of objects for ArrayElementContext**: replace 1 object + array per element with a single flat array `[address => [key, value_type, value_address]]` | ~40% for array-heavy targets | Medium |
| **Interned context reuse**: for repeated patterns (e.g., 200K `IgnoredErrors` objects with identical structure), share the context template | Varies | High |

The streaming approach is the most impactful: instead of accumulating the entire
context tree in reli's PHP heap and then writing it out, write `context_edges` and
`context_node_locations` rows to SQLite as the collector walks the target's memory.
This would make reli's memory usage O(stack depth) instead of O(target size).

---

## Revised Priority Matrix (post-investigation)

Based on 25 issues investigated + architectural analysis of reli-prof internals
+ database design review:

### Tier 1: Immediate wins (index + view, hours of work)

| Item | Impact | Evidence |
|---|---|---|
| Add `(run_id, parent_node_id, link_name, is_tree)` index | All queries using link_name filter | Used in every SQLite analysis |
| Add `(run_id, location_type)` index | Type-filtered queries | Every `WHERE location_type = ...` |
| Add `v_node_ancestors` (3-hop non-recursive) | Replace `v_node_paths` for 90% of cases | PHP-Parser: 3 hops found 6.5MB token array |
| CLI summary output (P1) | Every investigation started with this | 25/25 investigations needed class/type summary first |

### Tier 2: Retained size + sharedness (days of work)

| Item | Impact | Evidence |
|---|---|---|
| Tree-based retained size (subtree sum) | 8/10 diagnosed cases had single dominator | PrinsFrank, smalot, PhpSpreadsheet, Monolog, etc. |
| Sharedness indicator (non_tree_incoming count) | 2/10 cases had shared references | php-imap, SimplePie |
| Accumulation point detection | "Small owner + huge retained" pattern | PhpSpreadsheet IgnoredErrors, CommonMark DotAccessData |

### Tier 3: Structural analysis (weeks of work)

| Item | Impact | Evidence |
|---|---|---|
| Structure dedup detection | Same-shape objects that could be shared | Symfony Forms: 1,806 identical OptionsResolvers |
| Incoming frontier for subgraphs | "Cut these N edges to free M bytes" | php-imap: 3 paths to same string content |
| Dominator tree (Lengauer-Tarjan) | Exact retained size | Needed for complex graphs |

### Tier 4: Temporal analysis (research, months)

| Item | Impact | Evidence |
|---|---|---|
| Multi-snapshot diff | Leak detection over time | Monolog: linear growth visible |
| Selective access tracing (Zend object handlers) | "Is this retained object actually used?" | Theoretical but architecturally feasible |
| Last-use / reuse distance | Prioritize cold retained objects | Would distinguish cache vs leak |

### Tier 5: Collection-phase optimization (P6, ongoing)

| Item | Savings | Evidence |
|---|---|---|
| `$referencing_contexts` null init | -150-200MB | Agent analysis: 144B per empty array × millions |
| Streaming context to SQLite during collection | -60-80% of reli memory | PHP-Parser: 6GB reli for 162MB target |
| Lazy string value loading | -30-50% for string-heavy | php-imap: 151MB of strings copied into reli |

---

## Appendix: reli-prof Tracking Gaps (Known Invisible Memory)

Summary of memory regions that reli-prof does not currently track,
discovered across 41 investigated targets.

| Gap | Typical Size | Discovered In | Improvable? |
|---|---|---|---|
| **Fiber VM stacks** | 17 KB/fiber | Fiber test (7% analyzed) | ◎ Walk `zend_fiber.stack` |
| **php://temp stream buffers** | ~500 KB/stream | Guzzle (8.6% analyzed) | △ Resource table scan |
| **Generator execute_data** | ~400 B/generator | Generator test (49%) | ◎ Walk Generator object internals |
| **WeakMap internal hash table** | ~30% of WeakMap | WeakMap test (70%) | ◎ Walk `zend_weakmap` struct |
| **DOMDocument / SimpleXML nodes** | ~100% of DOM | DOM test (near 0%) | △ Walk libxml node tree via C structs |
| **Extension non-object emalloc** | Variable | Psalm prior art (75%) | △ Per-extension parser needed |
| **Closure op_array per instance** | 368 B/closure | Closure test | ✅ Already tracked as ZendOpArray |
| **dynamic_properties HashTable** | ~56 B/object | Monolog (85.7%) | ✅ Already tracked as dynamic_properties |
| **ZendMM alignment overhead** | ~3-5% of heap | All datasets | ✅ Reported as possible_allocation_overhead |

Legend:
- ◎ = Architecturally feasible, specific struct to walk
- △ = Requires extension-specific or C-level knowledge
- ✅ = Already tracked (listed for completeness)
