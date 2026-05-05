#!/bin/bash
# Test that two live inspector:memory captures into the same SQLite
# file produce a coherent multi-run DB.

set -uo pipefail
ROOT=/tmp/reli-pr773
WORK=/tmp/sqlite-validation
OUT="$WORK/out/multirun_live"
mkdir -p "$OUT"

start() {
    local target=$1; shift
    local pidfile=$1; shift
    rm -f "$pidfile"
    RELI_TARGET_PID_FILE="$pidfile" \
        php "$target" "$@" </dev/null > "$OUT/${pidfile##*/}.out" 2> "$OUT/${pidfile##*/}.err" &
    for _ in $(seq 1 60); do [[ -s "$pidfile" ]] && break; sleep 0.1; done
    cat "$pidfile"
}
stop() { kill -TERM "$1" 2>/dev/null; wait "$1" 2>/dev/null || true; }

run_combo() {
    local label=$1; shift
    local out=$OUT/multi_$label.sqlite3
    rm -f "$out"

    pid1=$(start /tmp/sqlite-validation/target_circular_refs.php "$OUT/pid1" 4 20 15)
    echo "[$label] target1 pid=$pid1"
    env "$@" php "$ROOT/reli" inspector:memory -p "$pid1" -f sqlite3 -o "$out" \
        > "$OUT/${label}_1.log" 2>&1
    rc1=$?
    stop "$pid1"
    if [[ $rc1 -ne 0 ]]; then echo "[$label] first capture failed rc=$rc1"; sed -n '1,5p' "$OUT/${label}_1.log"; return; fi

    pid2=$(start /tmp/sqlite-validation/target_splobjectstorage.php "$OUT/pid2" 1500)
    echo "[$label] target2 pid=$pid2"
    env "$@" php "$ROOT/reli" inspector:memory -p "$pid2" -f sqlite3 -o "$out" \
        > "$OUT/${label}_2.log" 2>&1
    rc2=$?
    stop "$pid2"
    if [[ $rc2 -ne 0 ]]; then echo "[$label] second capture failed rc=$rc2"; sed -n '1,8p' "$OUT/${label}_2.log"; return; fi

    err=$(sqlite3 "$out" 'PRAGMA integrity_check;' 2>&1 | wc -l)
    first=$(sqlite3 "$out" 'PRAGMA integrity_check;' 2>&1 | head -1)
    runs=$(sqlite3 "$out" 'SELECT COUNT(*) FROM runs;' 2>/dev/null)
    r1_ce=$(sqlite3 "$out" 'SELECT COUNT(*) FROM context_edges WHERE run_id=1;' 2>/dev/null)
    r2_ce=$(sqlite3 "$out" 'SELECT COUNT(*) FROM context_edges WHERE run_id=2;' 2>/dev/null)
    total_ce=$(sqlite3 "$out" 'SELECT COUNT(*) FROM context_edges;' 2>/dev/null)
    distinct_run_ids=$(sqlite3 "$out" 'SELECT GROUP_CONCAT(DISTINCT run_id) FROM context_edges;' 2>/dev/null)
    printf "[%s] integ='%s' err=%s runs=%s edges_run1=%s edges_run2=%s total=%s distinct_run_ids=%s\n" \
        "$label" "$first" "$err" "$runs" "$r1_ce" "$r2_ce" "$total_ce" "$distinct_run_ids"
}

run_combo default
run_combo sm     RELI_SHARED_MMAP_INGEST=1
run_combo pi     RELI_PARALLEL_INDEX=1
run_combo fd     RELI_FORMAT_DIRECT_INDEX=1
run_combo all    RELI_SHARED_MMAP_INGEST=1 RELI_PARALLEL_INDEX=1 RELI_FORMAT_DIRECT_INDEX=1

echo "multirun_live done"
