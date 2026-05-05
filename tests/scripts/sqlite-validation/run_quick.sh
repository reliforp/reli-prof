#!/bin/bash
# Like run.sh but only captures rmem, then runs export-sqlite twice (default
# vs experimental). Fast enough that we can sweep multiple targets and sizes.
#
# Usage: run_quick.sh <label> <target.php> [args...]

set -uo pipefail
label="${1:?label}"
target="${2:?target.php}"
shift 2

ROOT=/tmp/reli-pr773
WORK=/tmp/sqlite-validation
OUT="$WORK/out/$label"
mkdir -p "$OUT"

start_target() {
    rm -f "$WORK/target.pid"
    php "$target" "$@" </dev/null > "$OUT/target.out" 2> "$OUT/target.err" &
    for _ in $(seq 1 60); do
        [[ -s "$WORK/target.pid" ]] && break
        sleep 0.1
    done
    [[ -s "$WORK/target.pid" ]] || { echo "target failed"; exit 1; }
    cat "$WORK/target.pid"
}
stop_target() { kill -TERM "$1" 2>/dev/null; wait "$1" 2>/dev/null; }

pid=$(start_target)
echo "[$label] target pid=$pid"

php "$ROOT/reli" inspector:memory -p "$pid" \
    -f rmem -o "$OUT/capture.rmem" > "$OUT/rmem.log" 2>&1
echo "[$label] rmem: $(stat -c %s "$OUT/capture.rmem" 2>/dev/null || echo MISSING) bytes"

stop_target "$pid"

# Default ingest path
php "$ROOT/reli" inspector:memory:export-sqlite \
    "$OUT/capture.rmem" "$OUT/default.sqlite3" \
    > "$OUT/default.log" 2>&1
def_size=$(stat -c %s "$OUT/default.sqlite3" 2>/dev/null || echo MISSING)
def_integ=$(sqlite3 "$OUT/default.sqlite3" 'PRAGMA integrity_check;' 2>&1 | head -1)
def_err_n=$(sqlite3 "$OUT/default.sqlite3" 'PRAGMA integrity_check;' 2>&1 | wc -l)
echo "[$label] default       size=$def_size integ='$def_integ' err_lines=$def_err_n"

# Experimental (shared mmap + parallel index + format-direct index)
RELI_SHARED_MMAP_INGEST=1 RELI_PARALLEL_INDEX=1 RELI_FORMAT_DIRECT_INDEX=1 \
    php "$ROOT/reli" inspector:memory:export-sqlite \
    "$OUT/capture.rmem" "$OUT/shared_mmap.sqlite3" \
    > "$OUT/shared_mmap.log" 2>&1
sm_size=$(stat -c %s "$OUT/shared_mmap.sqlite3" 2>/dev/null || echo MISSING)
sm_integ=$(sqlite3 "$OUT/shared_mmap.sqlite3" 'PRAGMA integrity_check;' 2>&1 | head -1)
sm_err_n=$(sqlite3 "$OUT/shared_mmap.sqlite3" 'PRAGMA integrity_check;' 2>&1 | wc -l)
echo "[$label] shared_mmap   size=$sm_size integ='$sm_integ' err_lines=$sm_err_n"

# Row-count diff for tables built by ParallelIndexBuilder
for tbl in context_edges context_node_locations context_node_attributes; do
    d=$(sqlite3 "$OUT/default.sqlite3" "SELECT COUNT(*) FROM $tbl;" 2>/dev/null)
    s=$(sqlite3 "$OUT/shared_mmap.sqlite3" "SELECT COUNT(*) FROM $tbl;" 2>/dev/null)
    delta=$(( ${s:-0} - ${d:-0} ))
    echo "[$label] $tbl: default=$d shared_mmap=$s delta=$delta"
done

echo "[$label] done"
