#!/bin/bash
# Exercise the new --memory-limit-error-{file,line,max-depth} flags.
# These let inspector:memory walk the VM stack from a known
# (file:line) where memory_limit was exceeded.
#
# We don't actually OOM the target; we point reli at a stable line
# (the sleep loop) and verify that the flagged frame info
# round-trips through SQLite output without crashing.

set -uo pipefail
ROOT=/tmp/reli-pr773
WORK=/tmp/sqlite-validation
OUT=$WORK/out/oom_feature
mkdir -p "$OUT"

TARGET=/tmp/sqlite-validation/target_oom.php
TARGET_LINE=$(grep -n "while (true) { sleep(60); }" "$TARGET" | cut -d: -f1 | head -1)
TARGET_FILE=$(realpath "$TARGET")
echo "target file=$TARGET_FILE line=$TARGET_LINE"

start_target() {
    rm -f "$WORK/target.pid"
    php "$TARGET" </dev/null > "$OUT/target.out" 2> "$OUT/target.err" &
    for _ in $(seq 1 60); do [[ -s "$WORK/target.pid" ]] && break; sleep 0.1; done
    cat "$WORK/target.pid"
}
stop() { kill -TERM "$1" 2>/dev/null; wait "$1" 2>/dev/null || true; }

# Baseline: capture without the OOM hint (every other test we've done)
pid=$(start_target)
echo "target pid=$pid"

php "$ROOT/reli" inspector:memory -p "$pid" -f sqlite3 -o "$OUT/baseline.sqlite3" \
    > "$OUT/baseline.log" 2>&1
echo "baseline rc=$? size=$(stat -c %s "$OUT/baseline.sqlite3" 2>/dev/null) integ=$(sqlite3 "$OUT/baseline.sqlite3" 'PRAGMA integrity_check;' 2>&1 | head -1)"

# With OOM hint at the parked line
php "$ROOT/reli" inspector:memory -p "$pid" -f sqlite3 -o "$OUT/with_hint.sqlite3" \
    --memory-limit-error-file="$TARGET_FILE" \
    --memory-limit-error-line="$TARGET_LINE" \
    > "$OUT/with_hint.log" 2>&1
echo "with_hint rc=$? size=$(stat -c %s "$OUT/with_hint.sqlite3" 2>/dev/null) integ=$(sqlite3 "$OUT/with_hint.sqlite3" 'PRAGMA integrity_check;' 2>&1 | head -1)"

# With explicit max-depth (default 512, drop to 8 to exercise the
# parameter)
php "$ROOT/reli" inspector:memory -p "$pid" -f sqlite3 -o "$OUT/depth8.sqlite3" \
    --memory-limit-error-file="$TARGET_FILE" \
    --memory-limit-error-line="$TARGET_LINE" \
    --memory-limit-error-max-depth=8 \
    > "$OUT/depth8.log" 2>&1
echo "max-depth=8 rc=$? size=$(stat -c %s "$OUT/depth8.sqlite3" 2>/dev/null) integ=$(sqlite3 "$OUT/depth8.sqlite3" 'PRAGMA integrity_check;' 2>&1 | head -1)"

# Wrong file/line: should still produce valid output, possibly with an
# empty error context.
php "$ROOT/reli" inspector:memory -p "$pid" -f sqlite3 -o "$OUT/wrong_file.sqlite3" \
    --memory-limit-error-file=/nonexistent/path.php \
    --memory-limit-error-line=999 \
    > "$OUT/wrong_file.log" 2>&1
echo "wrong-file rc=$? size=$(stat -c %s "$OUT/wrong_file.sqlite3" 2>/dev/null) integ=$(sqlite3 "$OUT/wrong_file.sqlite3" 'PRAGMA integrity_check;' 2>&1 | head -1)"

# Wrong line in correct file
php "$ROOT/reli" inspector:memory -p "$pid" -f sqlite3 -o "$OUT/wrong_line.sqlite3" \
    --memory-limit-error-file="$TARGET_FILE" \
    --memory-limit-error-line=99999 \
    > "$OUT/wrong_line.log" 2>&1
echo "wrong-line rc=$? size=$(stat -c %s "$OUT/wrong_line.sqlite3" 2>/dev/null) integ=$(sqlite3 "$OUT/wrong_line.sqlite3" 'PRAGMA integrity_check;' 2>&1 | head -1)"

# max-depth = 0 (should be rejected per docblock 'is not positive integer')
php "$ROOT/reli" inspector:memory -p "$pid" -f sqlite3 -o "$OUT/depth0.sqlite3" \
    --memory-limit-error-file="$TARGET_FILE" \
    --memory-limit-error-line="$TARGET_LINE" \
    --memory-limit-error-max-depth=0 \
    > "$OUT/depth0.log" 2>&1
echo "max-depth=0 rc=$?"
head -3 "$OUT/depth0.log" 2>/dev/null

# negative max-depth
php "$ROOT/reli" inspector:memory -p "$pid" -f sqlite3 -o "$OUT/depthneg.sqlite3" \
    --memory-limit-error-file="$TARGET_FILE" \
    --memory-limit-error-line="$TARGET_LINE" \
    --memory-limit-error-max-depth=-5 \
    > "$OUT/depthneg.log" 2>&1
echo "max-depth=-5 rc=$?"
head -3 "$OUT/depthneg.log" 2>/dev/null

# non-integer line
php "$ROOT/reli" inspector:memory -p "$pid" -f sqlite3 -o "$OUT/bad_line.sqlite3" \
    --memory-limit-error-file="$TARGET_FILE" \
    --memory-limit-error-line=abc \
    > "$OUT/bad_line.log" 2>&1
echo "bad-line rc=$?"
head -3 "$OUT/bad_line.log" 2>/dev/null

# Just --memory-limit-error-file without --line — per code path
# (createSettings line 117), both must be set together.
php "$ROOT/reli" inspector:memory -p "$pid" -f sqlite3 -o "$OUT/file_only.sqlite3" \
    --memory-limit-error-file="$TARGET_FILE" \
    > "$OUT/file_only.log" 2>&1
echo "file-only-no-line rc=$?"
ls -la "$OUT/file_only.sqlite3" 2>/dev/null
head -3 "$OUT/file_only.log" 2>/dev/null

stop "$pid"

echo
echo "== diffing baseline vs with_hint =="
for tbl in summary runs context_nodes context_edges context_node_locations context_node_attributes; do
    b=$(sqlite3 "$OUT/baseline.sqlite3" "SELECT COUNT(*) FROM $tbl;" 2>/dev/null)
    h=$(sqlite3 "$OUT/with_hint.sqlite3" "SELECT COUNT(*) FROM $tbl;" 2>/dev/null)
    eq="OK"
    [[ "$b" == "$h" ]] || eq="differs"
    printf "  %-32s base=%-7s hint=%-7s  %s\n" "$tbl" "$b" "$h" "$eq"
done

echo
echo "== summary keys unique to with_hint =="
sqlite3 "$OUT/baseline.sqlite3" "SELECT key FROM summary ORDER BY key;" 2>/dev/null | sort -u > "$OUT/keys_base.txt"
sqlite3 "$OUT/with_hint.sqlite3" "SELECT key FROM summary ORDER BY key;" 2>/dev/null | sort -u > "$OUT/keys_hint.txt"
diff "$OUT/keys_base.txt" "$OUT/keys_hint.txt" || true

echo
echo "== look for memory_limit_error context in with_hint =="
sqlite3 "$OUT/with_hint.sqlite3" "SELECT key, substr(value, 1, 200) FROM summary WHERE key LIKE '%error%' OR key LIKE '%limit%';"

echo "oom_feature done"
