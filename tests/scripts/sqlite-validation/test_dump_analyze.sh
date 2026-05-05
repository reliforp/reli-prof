#!/bin/bash
# Test inspector:memory:dump → inspector:memory:analyze parity vs
# direct inspector:memory against the same target.
#
# The dump path goes through MemoryDumpReader (145 lines changed in
# this PR) instead of LiveProcessReader, so any drift between the
# two ingest fronts shows up as a row-count or content-hash
# mismatch on the SQLite output of the same target snapshot.

set -uo pipefail
ROOT=/tmp/reli-pr773
WORK=/tmp/sqlite-validation
OUT=$WORK/out/dump_analyze
mkdir -p "$OUT"

TARGET=/tmp/sqlite-validation/target_circular_refs.php

start_target() {
    rm -f "$WORK/target.pid"
    php "$TARGET" 4 20 15 </dev/null > "$OUT/target.out" 2> "$OUT/target.err" &
    for _ in $(seq 1 60); do [[ -s "$WORK/target.pid" ]] && break; sleep 0.1; done
    cat "$WORK/target.pid"
}
stop() { kill -TERM "$1" 2>/dev/null; wait "$1" 2>/dev/null || true; }

pid=$(start_target)
echo "target pid=$pid"

# Live capture (the established baseline)
php "$ROOT/reli" inspector:memory -p "$pid" -f sqlite3 -o "$OUT/live.sqlite3" \
    > "$OUT/live.log" 2>&1
echo "live      rc=$? size=$(stat -c %s "$OUT/live.sqlite3" 2>/dev/null) integ=$(sqlite3 "$OUT/live.sqlite3" 'PRAGMA integrity_check;' 2>&1 | head -1)"

# Plain dump → analyze
php "$ROOT/reli" inspector:memory:dump -p "$pid" -o "$OUT/plain.dump" \
    > "$OUT/plain_dump.log" 2>&1
echo "plain dump rc=$? size=$(stat -c %s "$OUT/plain.dump" 2>/dev/null)"

php "$ROOT/reli" inspector:memory:analyze "$OUT/plain.dump" -f sqlite3 -o "$OUT/plain.sqlite3" \
    > "$OUT/plain_analyze.log" 2>&1
echo "plain analyze rc=$? size=$(stat -c %s "$OUT/plain.sqlite3" 2>/dev/null) integ=$(sqlite3 "$OUT/plain.sqlite3" 'PRAGMA integrity_check;' 2>&1 | head -1)"

# Self-contained dump (--include-binary): bundles the binary segments,
# can be analyzed without --dependency-root
php "$ROOT/reli" inspector:memory:dump -p "$pid" -o "$OUT/full.dump" --include-binary \
    > "$OUT/full_dump.log" 2>&1
echo "include-binary dump rc=$? size=$(stat -c %s "$OUT/full.dump" 2>/dev/null)"

php "$ROOT/reli" inspector:memory:analyze "$OUT/full.dump" -f sqlite3 -o "$OUT/full.sqlite3" \
    > "$OUT/full_analyze.log" 2>&1
echo "include-binary analyze rc=$? size=$(stat -c %s "$OUT/full.sqlite3" 2>/dev/null) integ=$(sqlite3 "$OUT/full.sqlite3" 'PRAGMA integrity_check;' 2>&1 | head -1)"

# Lean dump (--exclude-heap): skips the [heap] region; the resulting
# analyze should produce a smaller / different graph but still be
# integrity-clean and consistent.
php "$ROOT/reli" inspector:memory:dump -p "$pid" -o "$OUT/lean.dump" --exclude-heap \
    > "$OUT/lean_dump.log" 2>&1
echo "exclude-heap dump rc=$? size=$(stat -c %s "$OUT/lean.dump" 2>/dev/null)"

php "$ROOT/reli" inspector:memory:analyze "$OUT/lean.dump" -f sqlite3 -o "$OUT/lean.sqlite3" \
    > "$OUT/lean_analyze.log" 2>&1
echo "exclude-heap analyze rc=$? size=$(stat -c %s "$OUT/lean.sqlite3" 2>/dev/null) integ=$(sqlite3 "$OUT/lean.sqlite3" 'PRAGMA integrity_check;' 2>&1 | head -1)"

# Now also verify the analyze path through every output format
echo
echo "== analyze through each output format =="
for fmt_ext in "json json" "rmem rmem"; do
    fmt=${fmt_ext% *}; ext=${fmt_ext#* }
    out=$OUT/plain.$ext
    rm -f "$out"
    php "$ROOT/reli" inspector:memory:analyze "$OUT/plain.dump" -f $fmt -o "$out" \
        > "$OUT/plain_analyze_$fmt.log" 2>&1
    rc=$?
    sz=$(stat -c %s "$out" 2>/dev/null || echo MISSING)
    printf "  format=%-8s rc=%s size=%s\n" "$fmt" "$rc" "$sz"
done

# report-json (single payload)
rm -f "$OUT/plain.report.json"
php "$ROOT/reli" inspector:memory:analyze "$OUT/plain.dump" -f report-json -o "$OUT/plain.report.json" \
    > "$OUT/plain_analyze_report-json.log" 2>&1
echo "  format=report-json rc=$? size=$(stat -c %s "$OUT/plain.report.json" 2>/dev/null || echo MISSING)"

stop "$pid"

echo
echo "== row count parity: live vs plain.dump→analyze vs full.dump→analyze =="
for tbl in summary runs context_nodes context_edges context_node_locations context_node_attributes class_objects_summary location_types_summary; do
    l=$(sqlite3 "$OUT/live.sqlite3"  "SELECT COUNT(*) FROM $tbl;" 2>/dev/null)
    p=$(sqlite3 "$OUT/plain.sqlite3" "SELECT COUNT(*) FROM $tbl;" 2>/dev/null)
    f=$(sqlite3 "$OUT/full.sqlite3"  "SELECT COUNT(*) FROM $tbl;" 2>/dev/null)
    eq="OK"
    [[ "$l" == "$p" && "$p" == "$f" ]] || eq="≠"
    printf "  %-32s live=%-7s plain=%-7s full=%-7s  %s\n" "$tbl" "$l" "$p" "$f" "$eq"
done

echo
echo "== content-hash parity =="
for tbl in summary runs context_nodes context_edges context_node_locations context_node_attributes; do
    l=$(sqlite3 "$OUT/live.sqlite3"  "SELECT * FROM $tbl ORDER BY 1,2,3,4;" 2>/dev/null | sha256sum | cut -c1-12)
    p=$(sqlite3 "$OUT/plain.sqlite3" "SELECT * FROM $tbl ORDER BY 1,2,3,4;" 2>/dev/null | sha256sum | cut -c1-12)
    f=$(sqlite3 "$OUT/full.sqlite3"  "SELECT * FROM $tbl ORDER BY 1,2,3,4;" 2>/dev/null | sha256sum | cut -c1-12)
    eq="OK"
    [[ "$l" == "$p" && "$p" == "$f" ]] || eq="≠"
    printf "  %-32s live=%s plain=%s full=%s  %s\n" "$tbl" "$l" "$p" "$f" "$eq"
done

echo "dump_analyze done"
