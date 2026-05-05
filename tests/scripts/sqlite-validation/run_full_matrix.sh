#!/bin/bash
# Capture rmem from a target, replay through every flag combination,
# and emit a per-table content-hash matrix so any cross-flag drift
# (not just integrity errors) shows up.
#
# Usage: run_full_matrix.sh <label> <target.php> [args...]

set -uo pipefail
label="${1:?label}"; target="${2:?target.php}"; shift 2

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
    [[ -s "$WORK/target.pid" ]] || { echo "[$label] target failed"; exit 1; }
    cat "$WORK/target.pid"
}
stop_target() { kill -TERM "$1" 2>/dev/null; wait "$1" 2>/dev/null; }

if [[ ! -f $OUT/capture.rmem ]]; then
    pid=$(start_target)
    echo "[$label] target pid=$pid"
    php "$ROOT/reli" inspector:memory -p "$pid" \
        -f rmem -o "$OUT/capture.rmem" > "$OUT/rmem.log" 2>&1
    stop_target "$pid"
fi
echo "[$label] rmem $(stat -c %s "$OUT/capture.rmem" 2>/dev/null || echo MISSING) bytes"

declare -A FLAGS=(
    [default]=""
    [sm]="RELI_SHARED_MMAP_INGEST=1"
    [pi]="RELI_PARALLEL_INDEX=1"
    [fd]="RELI_FORMAT_DIRECT_INDEX=1"
    [sm_pi]="RELI_SHARED_MMAP_INGEST=1 RELI_PARALLEL_INDEX=1"
    [sm_fd]="RELI_SHARED_MMAP_INGEST=1 RELI_FORMAT_DIRECT_INDEX=1"
    [pi_fd]="RELI_PARALLEL_INDEX=1 RELI_FORMAT_DIRECT_INDEX=1"
    [all]="RELI_SHARED_MMAP_INGEST=1 RELI_PARALLEL_INDEX=1 RELI_FORMAT_DIRECT_INDEX=1"
)
ORDER=(default sm pi fd sm_pi sm_fd pi_fd all)

# Build each variant
for v in "${ORDER[@]}"; do
    out=$OUT/v_$v.sqlite3
    [[ -f $out ]] && continue
    env_str="${FLAGS[$v]}"
    eval "$env_str php \"\$ROOT/reli\" inspector:memory:export-sqlite \
        \"\$OUT/capture.rmem\" \"\$out\" > \"\$OUT/v_${v}.log\" 2>&1"
done

# Integrity report
echo "  -- integrity & size --"
for v in "${ORDER[@]}"; do
    out=$OUT/v_$v.sqlite3
    [[ -f $out ]] || { printf "    %-8s MISSING\n" "$v"; continue; }
    sz=$(stat -c %s "$out")
    err=$(sqlite3 "$out" 'PRAGMA integrity_check;' 2>&1 | wc -l)
    first=$(sqlite3 "$out" 'PRAGMA integrity_check;' 2>&1 | head -1)
    printf "    %-8s size=%12s err_lines=%2s first='%s'\n" "$v" "$sz" "$err" "$first"
done

# Discover tables once
master=$OUT/v_default.sqlite3
[[ -f $master ]] || exit 0
TABLES=$(sqlite3 "$master" "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name;")

echo "  -- per-table row count matrix --"
printf "    %-32s" "table"
for v in "${ORDER[@]}"; do printf " %10s" "$v"; done; echo
for t in $TABLES; do
    printf "    %-32s" "$t"
    base=$(sqlite3 "$master" "SELECT COUNT(*) FROM $t;" 2>/dev/null)
    for v in "${ORDER[@]}"; do
        out=$OUT/v_$v.sqlite3
        [[ -f $out ]] || { printf " %10s" "-"; continue; }
        c=$(sqlite3 "$out" "SELECT COUNT(*) FROM $t;" 2>/dev/null)
        if [[ "$c" == "$base" ]]; then printf " %10s" "$c"
        else printf " %10s" "$c!"; fi
    done
    echo
done

echo "  -- per-table content-hash matrix (sorted full row dump) --"
printf "    %-32s" "table"
for v in "${ORDER[@]}"; do printf " %10s" "$v"; done; echo
for t in $TABLES; do
    printf "    %-32s" "$t"
    base_h=$(sqlite3 "$master" "SELECT * FROM $t ORDER BY 1,2,3,4,5;" 2>/dev/null | sha256sum | cut -c1-10)
    for v in "${ORDER[@]}"; do
        out=$OUT/v_$v.sqlite3
        [[ -f $out ]] || { printf " %10s" "-"; continue; }
        h=$(sqlite3 "$out" "SELECT * FROM $t ORDER BY 1,2,3,4,5;" 2>/dev/null | sha256sum | cut -c1-10)
        if [[ "$h" == "$base_h" ]]; then printf " %10s" "OK"
        else printf " %10s" "${h}≠"; fi
    done
    echo
done

echo "[$label] done"
