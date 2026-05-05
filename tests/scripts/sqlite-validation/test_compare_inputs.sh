#!/bin/bash
# inspector:memory:compare accepts both .rmem and .sqlite3 as
# baseline OR target. Verify all 4 combinations work and produce
# a coherent diff.
#
# Use round-2's circular_refs and weakmap_amphp captures as
# baseline / target. Each was captured from a different target,
# so the diff should be substantial and non-trivial.

set -uo pipefail
PR=/tmp/reli-pr773
WORK=/tmp/sqlite-validation
OUT=$WORK/out/compare_inputs
mkdir -p "$OUT"

RMEM_A=$WORK/out/circular_refs/capture.rmem
RMEM_B=$WORK/out/weakmap_amphp/capture.rmem

# Build sqlite from each rmem
SQLITE_A=$OUT/circular_refs.sqlite3
SQLITE_B=$OUT/weakmap.sqlite3
[[ -f $SQLITE_A ]] || php "$PR/reli" inspector:memory:export-sqlite "$RMEM_A" "$SQLITE_A" >/dev/null 2>&1
[[ -f $SQLITE_B ]] || php "$PR/reli" inspector:memory:export-sqlite "$RMEM_B" "$SQLITE_B" >/dev/null 2>&1
echo "rmem_A=$(stat -c %s "$RMEM_A") rmem_B=$(stat -c %s "$RMEM_B")"
echo "sqlite_A=$(stat -c %s "$SQLITE_A") sqlite_B=$(stat -c %s "$SQLITE_B")"

run_compare() {
    local label=$1; shift
    local out=$OUT/cmp_${label}.txt
    local err=$OUT/cmp_${label}.err
    php "$PR/reli" inspector:memory:compare "$@" > "$out" 2> "$err"
    local rc=$?
    local sz=$(stat -c %s "$out" 2>/dev/null)
    local first=$(head -1 "$err" 2>/dev/null)
    printf "%-22s rc=%-3s out_size=%-7s first_err='%s'\n" "$label" "$rc" "$sz" "$first"
}

echo
echo "== 4 input combinations =="
run_compare rmem_vs_rmem      "$RMEM_A"   "$RMEM_B"
run_compare rmem_vs_sqlite    "$RMEM_A"   "$SQLITE_B"
run_compare sqlite_vs_rmem    "$SQLITE_A" "$RMEM_B"
run_compare sqlite_vs_sqlite  "$SQLITE_A" "$SQLITE_B"

echo
echo "== content of rmem_vs_rmem (top 30 lines) =="
head -30 "$OUT/cmp_rmem_vs_rmem.txt"

echo
echo "== diff vs the 4 reports — should be ~identical but for input names =="
diff "$OUT/cmp_rmem_vs_rmem.txt"   "$OUT/cmp_rmem_vs_sqlite.txt"   | head -10 | sed 's/^/  /' || true
echo "  ---"
diff "$OUT/cmp_rmem_vs_rmem.txt"   "$OUT/cmp_sqlite_vs_rmem.txt"   | head -10 | sed 's/^/  /' || true
echo "  ---"
diff "$OUT/cmp_rmem_vs_rmem.txt"   "$OUT/cmp_sqlite_vs_sqlite.txt" | head -10 | sed 's/^/  /' || true

echo
echo "== short hash of the meat (skip lines mentioning input file paths) =="
for label in rmem_vs_rmem rmem_vs_sqlite sqlite_vs_rmem sqlite_vs_sqlite; do
    h=$(grep -v -E "$RMEM_A|$RMEM_B|$SQLITE_A|$SQLITE_B|$WORK|.rmem|.sqlite3" "$OUT/cmp_${label}.txt" 2>/dev/null | sha256sum | cut -c1-16)
    echo "  $label: $h"
done

echo
echo "== same-file compare across format ==="
# baseline.rmem vs the sqlite built from itself — should be near-zero delta
run_compare same_rmem_vs_self_sqlite  "$RMEM_A" "$SQLITE_A"
echo
echo "  sample of same-file compare output:"
sed -n '1,30p' "$OUT/cmp_same_rmem_vs_self_sqlite.txt"

echo "compare_inputs done"
