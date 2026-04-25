# T2.3 investigation findings — bare `raw:` label in `dedup_candidate`

This file reports the result of the investigation requested in
`docs/internals/memory-report-implementation-handoff.md` on branch
`claude/improve-memory-report-NKJ41` (commit 9e7127c). It identifies
the root cause and the minimal fix; it does not ship any code change.

The investigation was carried out on a clean branch
(`claude/investigate-memory-t2-3-SDYVA`) so the findings file is
self-contained and the proposed patch can be applied separately.

## TL;DR

The bug is **not** in the dedup pass or the substrate resolver. It is
in the binary writer
`Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\BinaryContextTreeSink::emitNode`.

A single signedness mismatch makes the on-disk `node_classes`
section come out **all NULL** for every node, so when the
FFI-CSR substrate is loaded from a `.rmem` file it answers
`getNodeClass($node_id) === null` for every object node — including
`CastedCData` and `FFI\CData` — even though the LocationRow data
in the same `.rmem` file has the class names. Every dedup candidate
on the binary report path then renders without a source class.

In hypothesis terms (per the original T2.3 doc), this is **(B)**:
*"`getNodeClass(owner)` returns null because `class_name` isn't
recorded for that object's location"* — except the recording does
happen in `LocationRow.class_id`, the loss is at the per-node
accumulator that feeds the on-disk `node_classes` section.

The SQL report path is unaffected (it reads `class_name` straight
from `context_node_locations`).

## Reproduction

PHP 8.4.19 (host), `dockerd` not required for this scenario; the
target runs as a separate host PHP process so `--pid host` PID
namespacing isn't needed. The default `--php-regex` accepts
`/tmp/php8.4` as a binary path, so a copy of `/usr/bin/php8.4`
under that name is the cleanest target binary (the host's own
`/usr/bin/php8.4` triggers an unrelated module-fingerprint warning
when reli looks at the same binary it itself loads).

```bash
cp /usr/bin/php8.4 /tmp/php8.4

cat > /tmp/ffi-cdata-target.php <<'EOF'
<?php
final class CastedCData {
    public function __construct(
        public object $raw,
        public object $casted,
    ) {}
}
$N = 5500;
$registry = [];
for ($i = 0; $i < $N; $i++) {
    $registry[] = FFI::new('int');
}
$wrappers = [];
for ($i = 0; $i < $N; $i++) {
    $wrappers[] = new CastedCData($registry[$i], FFI::new('int'));
}
file_put_contents('/tmp/target-ready', (string) getmypid());
sleep(3600);
EOF

setsid nohup /tmp/php8.4 /tmp/ffi-cdata-target.php \
    > /tmp/target-stdout 2>&1 < /dev/null &
disown
sleep 4
TARGET_PID=$(cat /tmp/target-ready)

php reli inspector:memory:dump \
    --pid="$TARGET_PID" \
    --output=/tmp/ffi-dump.dat \
    --include-binary

# Two output formats — one each path.
php reli inspector:memory:analyze /tmp/ffi-dump.dat \
    --output-format=binary --output=/tmp/ffi-report.rmem
php reli inspector:memory:analyze /tmp/ffi-dump.dat \
    --output-format=sqlite3 --output=/tmp/ffi-report.db

# SQL report path — bug does NOT trigger.
php reli inspector:memory:report /tmp/ffi-report.db -f report \
    | grep dedup_candidate
# → dedup_candidate: CastedCData::$raw (FFI\CData): 5,500 copies x 92 B ...

# Binary report path — bug triggers.
php reli inspector:memory:report /tmp/ffi-report.rmem -f report \
    | grep dedup_candidate
# → dedup_candidate: raw: 5,500 copies x 92 B avg retained = 494.14 KB
```

The `.rmem` line matches the user-observed shape: bare `raw:`,
target class missing from the label even though it appears in
`Examples: FFI\CData (88B)` two lines down.

The shape matters for repro:

  * `casted` points at a unique `FFI::new('int')` per wrapper so
    that bucket is populated by tree edges (no dedup_candidate
    fires for `casted`).
  * `raw` points at the pre-seeded registry entry. The registry
    array is built first, so the registry's `array_elements` →
    `FFI\CData` edge becomes the tree edge; the wrapper's
    `ObjectPropertiesContext` → `FFI\CData` edge for `raw` becomes
    non-tree → enters the dedup bucket with `cnt = 5500`.

## The four diagnostic queries from the original T2.3 doc

Run against the SQLite `.db` produced from the same dump. All four
queries return the **expected, healthy shape**:

```sql
-- Q1: how many 'raw' edges?
SELECT COUNT(*) FROM context_edges WHERE link_name = 'raw';
--   → 6003

-- Q1b: of which non-tree (the dedup-eligible kind)?
SELECT is_tree, COUNT(*) FROM context_edges
WHERE link_name='raw' GROUP BY is_tree;
--   → is_tree=0: 5500   is_tree=1: 503

-- Q2: parent context node types of non-tree raw edges
SELECT cn.type, COUNT(*) FROM context_edges e
JOIN context_nodes cn ON cn.node_id = e.parent_node_id
WHERE e.link_name='raw' AND e.is_tree=0 AND e.strength='strong'
GROUP BY cn.type;
--   → ObjectPropertiesContext: 5500   (no other types)

-- Q3: parent's tree-link-name (resolveDirectSourceClassFromSubstrate's first guard)
SELECT pe.link_name, COUNT(*) FROM context_edges e
JOIN context_edges pe
  ON pe.child_node_id = e.parent_node_id AND pe.is_tree = 1
WHERE e.link_name='raw' AND e.is_tree=0 AND e.strength='strong'
GROUP BY pe.link_name;
--   → object_properties: 5500   (the resolver's first guard would pass)

-- Q4: grand-parent's class_name (the owner — the CastedCData object)
SELECT cnl.class_name, COUNT(*) FROM context_edges raw_e
JOIN context_edges obj_props_e
  ON obj_props_e.child_node_id = raw_e.parent_node_id
 AND obj_props_e.is_tree = 1
 AND obj_props_e.link_name = 'object_properties'
LEFT JOIN context_node_locations cnl
  ON cnl.node_id = obj_props_e.parent_node_id
WHERE raw_e.link_name='raw' AND raw_e.is_tree=0 AND raw_e.strength='strong'
GROUP BY cnl.class_name;
--   → CastedCData: 5500   (the resolver's getNodeClass(owner) would return 'CastedCData')
```

Conclusion from these: the SQLite-side data is fully consistent.
The SQL report path's `resolveDedupOwnerInfoFromSubstrate` /
`resolveDirectSourceClassFromSubstrate` chain DOES run cleanly on
the corresponding substrate, and it produces
`CastedCData::$raw (FFI\CData)` exactly as expected. This rules
out hypotheses (A), (B), and (C) **for the SQL path**.

The bug only manifests on the **binary** path. Substrate
inspection on the same `.rmem` shows where the data is lost:

```
$reader = BinaryReader::open('/tmp/ffi-report.rmem');
$substrate = GraphSubstrate::createFromBinary($reader, null, false, false);

$substrate->getTreeLinkName(22358);           // 'object_properties'   ← OK
$substrate->getTreeParentNodeId(22358);       // 22356                 ← OK
$substrate->getNodeClass(22356);              // NULL                  ← BUG (CastedCData)
$substrate->getNodeClass(348);                // NULL                  ← BUG (FFI\CData)
```

`getTreeLinkName` and `getTreeParentNodeId` walk correctly. The
substrate just doesn't know any object's class.

## Where the class is lost

`Reader::getSectionData('node_classes')` is fully populated in the
`.rmem` file (84,754 slots × 4 bytes = 339,016 B), but every slot
holds `0xFFFFFFFF` (NULL_STRING_ID). For comparison, the LocationRow
data in the same file has 16,500 `ZendObjectMemoryLocation` rows
with `class_id` set (11,000 `FFI\CData` + 5,500 `CastedCData`).

So `LocationRow.class_id` is written correctly; the per-node
accumulator that feeds `node_classes` is what fails to update.

The accumulator is `BinaryContextTreeSink::$perNodeClasses`, an
FFI `int32_t[]` array (line 99). The update guard at lines 287-293:

```php
if (
    $class_id !== Format::NULL_STRING_ID
    && (int)$this->perNodeClasses[$node_id] === (int)Format::NULL_STRING_ID
) {
    $this->perNodeClasses[$node_id] = $class_id;
}
```

`Format::NULL_STRING_ID` is `0xFFFFFFFF` = `4294967295` in PHP.
But `perNodeClasses` is **signed** `int32_t[]`. Initialised slots
read back as `-1`, never `4294967295`. So the right-hand side of
`===` never matches and the slot never gets updated.

Verified directly:

```php
$arr = FFIHelper::new("int32_t[4]");
for ($i = 0; $i < 4; $i++) $arr[$i] = Format::NULL_STRING_ID;
var_dump((int)$arr[0]);                                  // int(-1)
var_dump((int)$arr[0] === (int)Format::NULL_STRING_ID);  // bool(false)
```

`perNodeSizes` (also FFI-backed but `int64_t[]`) is unaffected
because int64 has enough range for any uint32 string id and for
the +size accumulation it does (`= (int)... + $location->size`
with no NULL guard).

When `BinaryMemoryOutput::finalizeStreaming` serialises the
accumulator with `FFI::string($perNodeClasses, $nodeSlots * 4)`,
each `int32_t` -1 emits the bytes `FF FF FF FF`. The reader
unpacks them as unsigned 32-bit (`unpack('V', ...)`) → 4294967295
= `Format::NULL_STRING_ID`. Looks like an honest "no class
known", so the FFI-CSR substrate's fast-path loader treats the
section as authoritative and skips the LocationRow class scan
(the locations scan only fills classes when `!$sizesLoaded`,
gated on the same `node_sizes`/`node_classes` section presence).

End-to-end effect on the dedup pass:

  * `target_class` is not in the precomputed binary row
    (`getDedupCandidateStats` doesn't carry it); the pass falls
    back to `$this->substrate->getNodeClass($sample_child_node_id)`,
    which returns null.
  * `resolveDirectSourceClassFromSubstrate` walks correctly to
    the owner node and calls `getNodeClass($owner)`, which also
    returns null.
  * `buildDedupLabel(null, null, 'raw', null)` → bare `raw:`.

The `Examples: FFI\CData (88B)` line still appears because
`accumulateDedupGroup` reads the LocationRow class directly via
`buildNodeMeta`, bypassing the substrate.

## Minimal fix

The smallest viable fix is to compare against the value that
actually round-trips through `int32_t`, not against the unsigned
constant. The cleanest expression is `=== -1`, since that's what
PHP reads back from any `int32_t` slot initialised to
`Format::NULL_STRING_ID`:

```php
// BinaryContextTreeSink::emitNode, ~line 287
if (
    $class_id !== Format::NULL_STRING_ID
    && (int)$this->perNodeClasses[$node_id] === -1
) {
    $this->perNodeClasses[$node_id] = $class_id;
}
```

The same pattern reappears at `ensurePerNodeCapacity` line 495
where the new slots are initialised:

```php
for ($i = 0; $i < $new_cap; $i++) {
    $new_classes[$i] = Format::NULL_STRING_ID;
}
```

That assignment is fine — storing `4294967295` in `int32_t`
truncates to `-1`, which is the same byte pattern. No change
needed there. But it is the source of the asymmetry that bit
the comparison; a brief comment at that line ("stored as -1 in
int32_t; readers see 0xFFFFFFFF") would help future readers.

If a more invasive fix is preferred: switch the array type to
`uint32_t` (FFI accepts it; reads back as the original 0..2^32-1
range) and the original comparison against `Format::NULL_STRING_ID`
would just work.

Either way, the fix is one or two lines. No JSON schema impact.

## Why the display fallback the original T2.3 doc proposed is still
useful

Even after the binary writer is fixed, `buildDedupLabel` still
collapses to bare `link_name` whenever both `source_class` and
`target_class` are null. That's a fragile failure mode for a
human-facing report. The proposed
`?::$link_name -> {target_class}` fallback in the original T2.3
doc is independent of this fix and should still ship — it
guards against the next case where one or both ends become
unresolvable for a different reason (dynamic class loading,
proxies, future formats). It just won't fire for `raw` /
`casted` once the writer is fixed.

## Recommended next steps

1. Apply the one-line writer fix on a small dedicated branch
   (call it `claude/binary-node-classes-signedness` or similar)
   and add a regression test: emit a `.rmem` from a process that
   has at least one `ZendObjectMemoryLocation` with `class_name`,
   open the resulting `Reader`, assert that the `node_classes`
   section contains at least one non-NULL entry. The bug would
   have failed this test trivially.

2. After the writer fix lands, ship the original T2.3 display
   fallback as a separate small patch.

3. Audit other `int32_t[]` FFI accumulators for the same
   `=== Format::NULL_STRING_ID` comparison pattern.
   `BinaryContextTreeSink` is the obvious one but other writers
   in `src/Inspector/Output/MemoryOutput/BinaryFormat/` and
   `src/Lib/PhpProcessReader/PhpMemoryReader/ContextAnalyzer/`
   may share the idiom. (Quick `grep -rn 'int32_t.*FFIHelper' src/`
   plus `grep -rn 'NULL_STRING_ID' src/` should find all sites.)

4. Substrate consumers other than `DedupCandidatePass` that
   call `getNodeClass()` may also have been silently degraded
   on the binary path (ownership pattern detection, dynamic
   property detection, etc.). Worth a sweep after the fix to
   look for "this finding has more detail on the SQLite path
   than the binary path" patterns in the same dump.
