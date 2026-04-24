# Memory Report Implementation Handoff

**Audience**: the next (implementation) session. Delete this file once
the fixes land — it's transient directive content, not reference docs.

**Companion reading**: `docs/internals/memory-report-ux-improvements.md`
in the same commit history. That's the *why* document with 25 real
reports of evidence; this file is the *what to build, in what order,
with what gotchas* document.

## What this branch is

`claude/improve-memory-report-NKJ41` contains **no code changes** yet —
only the `docs/internals/memory-report-ux-improvements.md` investigation.
The investigation ran `inspector:memory:report` against 25 real-world
workloads (WordPress bootstrap, PhpSpreadsheet, Doctrine-like UoW,
Laravel queue envelopes, Closure captures, Eloquent hydration, PDO
fetchAll, etc.) and identified concrete bugs + presentation issues
each backed by a specific captured report.

Each claim carries a code line reference (e.g.,
`DrillDownPass.php:110`) and a "primary evidence report" name
(e.g., `rw2_json-decode-huge.report.txt`).

## Priority ordering

From the `N16` table at the bottom of the UX doc, ranked by
"how many of the 25 reports does this fix help" × "how wrong does
it look to a reader":

**Tier 1 — fix once, helps most reports, small surface.**
- **B1** (class/method names lower-cased in path labels) — 19+/25
- **B3** (preview newline escape in Top Strings) — 2/25 but table
  layout breaks visibly when it hits
- **N1** (`empty_object` should filter internal classes) — 9/25

**Tier 2 — correctness fixes, biggest "numbers look wrong".**
- **B8/B9** (bottleneck_path leaf path + root size mismatch) — 25/25
- **B2/B6/N10** (`retained × count` inflation; dedup heterogeneous
  bucket) — 4/25 but produces "722 MB impact on 11 MB heap" nonsense

**Tier 3 — narrative improvements for noisy reports.**
- **S12** (cluster findings by target across detector kinds)
- **N2** (positive-signal findings labelled as warnings)
- **N4** (collapse uniform sibling rows in Top Arrays / Top Strings)

Ship T1 first, T2 second, T3 third. T1 alone probably makes ~20/25
reports noticeably cleaner, with almost no risk.

## Artifact reuse for verification

All 25 dump/db pairs are saved at:

    /tmp/memreport-out/
    ├── rw_{phpunit,symfony-console,twig,parsedown,logger-stack,laravel-collections,psr-7-stack}.{rmem,db,report.txt}
    ├── rw2_{json-decode-huge,csv-mega,xml-dom-huge,error-context-capture,eloquent-hydration,guzzle-buffered,spreadsheet-xlsx}.{rmem,db,report.txt}
    ├── rw3_{doctrine-uow,static-cache,graphql-shape,reflection-heavy,messenger-envelopes,closure-leak}.{rmem,db,report.txt}
    └── rw4_{wordpress-bootstrap,generator-leak,graph-recursion,enum-collections,pdo-result-hoarding}.{rmem,db,report.txt}

**After a fix, the full regeneration is**:

    ./reli inspector:memory:report <path>.db --output-format=report \
      --output=<path>.report.new.txt --memory-limit=4G

No dump, no analyze, no docker — the .db is the cached post-analyze
state, and `inspector:memory:report` re-reads it cheaply (seconds).

So a verification loop for any report-level fix is:

1. Rerun `inspector:memory:report` against all 25 .db files in
   parallel.
2. `diff -u /tmp/memreport-out/rw*.report.txt
            /tmp/memreport-out/rw*.report.new.txt`
3. Check the diff matches the expected changes for the shipped tier.

For B1 (collector-side) the .rmem dumps would need to be
re-analyzed into fresh .db files (`inspector:memory:analyze`),
because the labels live in the substrate. That path needs docker
and takes minutes per scenario.

Schema of this handoff:
- **§Tier 1 implementation notes** — B1, B3, N1
- **§Tier 2 implementation notes** — B8/B9, B2/B6/N10
- **§Tier 3 implementation notes** — S12, N2, N4
- **§Verification checklist** — what to grep for after each fix
- **§Things to avoid** — walk-backs collected in the investigation
  session that the implementation session should not re-make

---

## Tier 1 implementation notes

### B1 — class/method names lower-cased in path labels

**File**: `src/Lib/PhpProcessReader/PhpMemoryReader/Collector/Job/EmitClassTableJob.php:101–111`

**What's wrong today**: the collector uses `$bucket->key` (the Zend
class_table HashTable key — case-folded for case-insensitive
dispatch) as the node label. Evidence: every `class_table->...`
path in the corpus shows lowercased class names
(`class_table->symfony\component\dependencyinjection\containerbuilder->methods->createservice->...`,
`class_table->composer\autoload\composerstaticinit32e1def09fefbf05b3038ecf2fa0a6e2->...`).

Same issue applies to the methods table (`methods->createService`
shows as `methods->createservice`) starting around line 309 of the
same file.

**Two fix shapes, pick one (recommendation: (i))**:

**(i) Emit-time fix.** Resolve `$class_entry->name` before
`emitNode` and use its string as the label. The canonical name is
already collected a few lines down as a `name` child:

        $class_name_context = CollectorHelpers::collectZendStringPointer(
            $class_entry->name,
            ...
        );

Just do that dereference earlier and pass the resolved string to
`emitNode`. Guard against dereference failure: fall back to the
lowercased `$bucket->key` so a malformed class table doesn't abort
the walk.

Methods table: same pattern — the canonical method name is
available from `$zend_function->common.function_name`.

**(ii) Display-time fix (less invasive on the collector):**
extend `NodeLabeler` (src/Inspector/Output/MemoryOutput/Report/Substrate/NodeLabeler.php)
to look up the `name` child of class-definition nodes and prefer
that. Downside: `name` is stored as a child node, not as a
`context_node_attributes` row, so `NodeLabeler::ensureLoaded`'s
SQL query would need joining through tree edges. Adds runtime cost
on the display path.

(i) is cleaner. Probably ~15–30 lines including the methods table.

**N11 extension**: same case-folding issue applies to
`function_table`. See rw4_wordpress-bootstrap — `remove_accents`
renders correctly only because WP chose all-lowercase names; any
camelCase user function would be munged. If the class-table fix
generalises to look up canonical names, apply the equivalent
treatment to function_table in the same pass.

### B3 — preview newline escape in Top Strings

**File**: `src/Inspector/Output/MemoryOutput/Report/Formatter/TextReportFormatter.php:251–257`

**What's wrong today**: Top Strings' preview is
`substr($preview, 0, 37) . '...'` with no whitespace escape. Strings
with embedded newlines (Carbon doc comments, Parsedown-rendered
HTML, PDO result rows with multi-line values) split the table row in
two. See `rw_logger-stack.report.txt` (Carbon doc_comments, 7 rows
each wrap) and `rw_s4_big_string.report.txt:52–53` (`$huge_html`).

**Fix**: one-line whitespace escape before truncation:

        $preview = strtr($preview, [
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
            "\0" => '\\0',
        ]);

Apply around line 251, before the `strlen > 40` check. Consider
also escaping in the `$display_path` branch above it just below
line 252–254 for consistency (paths are less likely to carry
newlines, but the formatter shouldn't assume).

### N1 — `empty_object` should filter internal classes

**File**: likely `src/Inspector/Output/MemoryOutput/Report/Pass/`
— grep for `kind: 'empty_object'`. (The investigation session didn't
pinpoint the exact file.)

**What's wrong today**: `empty_object` flags every class with zero
user-declared properties as "may be replaceable". It misfires on
every PHP internal class because internal classes by definition have
no user-declared properties while still carrying significant C-side
state.

Corpus hits (9/25 reports): rw_phpunit (ReflectionClass),
rw_symfony-console (Closure), rw_twig (ReflectionMethod),
rw3_closure-leak (Closure — the *actual leak root*),
rw3_messenger-envelopes (Closure — the retry-stamp retainer),
rw3_graphql-shape (Closure resolvers),
rw4_generator-leak (Generator), rw4_wordpress-bootstrap (Closure
hook callbacks), rw4_messenger-envelopes (BusNameStamp — user
class without declared properties; this one should legitimately
remain).

**Right fix**: check the Zend class-entry `type` (user = 0x01 vs
internal = 0x02) and skip internals. If the substrate doesn't
already expose this, a short-term denylist will do:

        Closure, Generator, Fiber,
        WeakMap, WeakReference,
        SplObjectStorage, SplFixedArray, SplDoublyLinkedList,
          SplQueue, SplStack, SplHeap, SplPriorityQueue,
        DateTime, DateTimeImmutable, DateInterval, DateTimeZone,
          DatePeriod,
        DOMDocument, DOMElement, DOMNode, DOMText, DOMAttr,
          DOMNodeList,
        SimpleXMLElement, XMLReader, XMLWriter,
        PDO, PDOStatement,
        mysqli, mysqli_stmt, mysqli_result,
        ReflectionClass, ReflectionMethod, ReflectionProperty,
          ReflectionFunction, ReflectionParameter,
          ReflectionExtension, ReflectionAttribute,
          ReflectionEnum, ReflectionEnumBackedCase,
          ReflectionEnumUnitCase, ReflectionUnionType,
          ReflectionIntersectionType, ReflectionNamedType,
        ArrayObject, ArrayIterator, CachingIterator,
          RecursiveDirectoryIterator, DirectoryIterator,
          FilesystemIterator, GlobIterator,

The class-entry type check is the correct long-term implementation;
the denylist is defensible as a stepping stone.

**After the filter, residual empty_object findings should be
genuinely user-defined classes with no declared properties** —
cases where the advice "consider value type / enum / flag-only
class" actually applies. From the corpus:
  - `Twig\Node\NameDeprecation` (1,440 instances — candidate for enum)
  - `BusNameStamp` (50,000 instances — marker stamp, could be enum)
