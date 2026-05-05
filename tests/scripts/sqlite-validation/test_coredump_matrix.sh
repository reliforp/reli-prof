#!/bin/bash
# Verify inspector:coredump produces consistent output across all 8
# experimental flag combinations + cross-check against a live
# capture of the same target shape (different process, but heap
# shape should match within target-side drift).

set -uo pipefail
PR=/tmp/reli-pr773
WORK=/tmp/sqlite-validation
OUT=$WORK/out/coredump_matrix
mkdir -p "$OUT"

# Spawn fresh target, gcore it, then test
ulimit -c unlimited 2>/dev/null || true
rm -f "$WORK/target.pid" "$OUT/core.dump" "$OUT/v_*.sqlite3"

(ulimit -c unlimited 2>/dev/null; php "$WORK/target_circular_refs.php" 4 20 15) </dev/null > "$OUT/target.out" 2>&1 &
LAUNCH=$!
for _ in $(seq 1 60); do [[ -s "$WORK/target.pid" ]] && break; sleep 0.1; done
PID=$(cat "$WORK/target.pid")
echo "target pid=$PID"

# Capture rmem from live first (for parity)
php "$PR/reli" inspector:memory -p "$PID" -f rmem -o "$OUT/live.rmem" >/dev/null 2>&1
echo "live rmem: $(stat -c %s "$OUT/live.rmem") bytes"

# gcore
gcore -o "$OUT/core" "$PID" 2>/dev/null | tail -2
ls -la "$OUT/core.$PID" 2>&1
mv "$OUT/core.$PID" "$OUT/target.core"

kill -KILL "$LAUNCH" 2>/dev/null; wait "$LAUNCH" 2>/dev/null; true

# Run the 8-flag matrix on the coredump
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

echo
echo "== 8-flag matrix on coredump → sqlite =="
for v in "${ORDER[@]}"; do
    out=$OUT/v_$v.sqlite3
    rm -f "$out"
    eval "${FLAGS[$v]} php \"\$PR/reli\" inspector:coredump \"\$OUT/target.core\" -p \"\$PID\" \
        -f sqlite3 -o \"\$out\" > \"\$OUT/v_${v}.log\" 2>&1"
    rc=$?
    sz=$(stat -c %s "$out" 2>/dev/null)
    integ=$(sqlite3 "$out" 'PRAGMA integrity_check;' 2>&1 | head -1)
    err_n=$(sqlite3 "$out" 'PRAGMA integrity_check;' 2>&1 | wc -l)
    printf "  %-8s rc=%-2s size=%-9s integ='%s' err=%s\n" "$v" "$rc" "$sz" "$integ" "$err_n"
done

echo
echo "== row count parity across the 8 variants =="
master=$OUT/v_default.sqlite3
TABLES=$(sqlite3 "$master" "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name;")
for t in $TABLES; do
    base=$(sqlite3 "$master" "SELECT COUNT(*) FROM $t;" 2>/dev/null)
    printf "  %-32s base=%-7s" "$t" "$base"
    for v in "${ORDER[@]}"; do
        out=$OUT/v_$v.sqlite3
        c=$(sqlite3 "$out" "SELECT COUNT(*) FROM $t;" 2>/dev/null)
        if [[ "$c" == "$base" ]]; then printf " %s=ok" "$v"
        else printf " %s≠%s" "$v" "$c"; fi
    done
    echo
done

echo
echo "== content-hash parity across the 8 variants =="
for t in $TABLES; do
    base=$(sqlite3 "$master" "SELECT * FROM $t ORDER BY 1,2,3,4;" 2>/dev/null | sha256sum | cut -c1-12)
    eq="OK"
    for v in "${ORDER[@]}"; do
        out=$OUT/v_$v.sqlite3
        h=$(sqlite3 "$out" "SELECT * FROM $t ORDER BY 1,2,3,4;" 2>/dev/null | sha256sum | cut -c1-12)
        [[ "$h" == "$base" ]] || eq="≠"
    done
    printf "  %-32s base=%s parity=%s\n" "$t" "$base" "$eq"
done

# Now compare coredump output to live rmem from the SAME target
echo
echo "== coredump-default vs live-rmem-then-export-sqlite of the SAME target =="
php "$PR/reli" inspector:memory:export-sqlite "$OUT/live.rmem" "$OUT/live.sqlite3" >/dev/null 2>&1
echo "  live.sqlite3 size=$(stat -c %s "$OUT/live.sqlite3")"
echo "  coredump default.sqlite3 size=$(stat -c %s "$OUT/v_default.sqlite3")"

for t in summary runs context_nodes context_edges context_node_locations context_node_attributes; do
    cl=$(sqlite3 "$OUT/live.sqlite3" "SELECT COUNT(*) FROM $t;" 2>/dev/null)
    cc=$(sqlite3 "$OUT/v_default.sqlite3" "SELECT COUNT(*) FROM $t;" 2>/dev/null)
    # Address-stripped invariant hash
    inv_cols=$(case $t in
        context_nodes) echo "type" ;;
        context_edges) echo "link_name,is_tree,strength" ;;
        context_node_locations) echo "size,location_type,class_name,string_value" ;;
        context_node_attributes) echo '"key","value"' ;;
        *) echo "*" ;;
    esac)
    hl=$(sqlite3 "$OUT/live.sqlite3"      "SELECT $inv_cols FROM $t ORDER BY 1,2,3,4;" 2>/dev/null | sha256sum | cut -c1-12)
    hc=$(sqlite3 "$OUT/v_default.sqlite3" "SELECT $inv_cols FROM $t ORDER BY 1,2,3,4;" 2>/dev/null | sha256sum | cut -c1-12)
    eq="OK"
    [[ "$hl" == "$hc" ]] || eq="≠"
    printf "  %-32s live=%-7s core=%-7s inv_hash live=%s core=%s  %s\n" "$t" "$cl" "$cc" "$hl" "$hc" "$eq"
done

echo
echo "coredump_matrix done"
