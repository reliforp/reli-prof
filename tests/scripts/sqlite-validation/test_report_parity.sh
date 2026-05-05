#!/bin/bash
# inspector:memory:report parity 0.12.0 vs PR tip.
# Both consume a SQLite memory analysis DB and produce a text or
# JSON report. The PR description says report goes ~21% faster from
# generating directly off rmem instead of querying a hydrated SQLite.
# Verify the report's structural / numeric output is the same so
# downstream consumers don't break.

set -uo pipefail
PR=/tmp/reli-pr773
OLD=/tmp/reli-012
WORK=/tmp/sqlite-validation
OUT=$WORK/out/report_parity
mkdir -p "$OUT"

# Use the rmem capture from circular_refs (already on disk from earlier
# rounds). report can read either rmem or sqlite, so test both inputs.
RMEM=$WORK/out/circular_refs/capture.rmem
[[ -f "$RMEM" ]] || { echo "missing $RMEM — re-run round-2 matrix first"; exit 1; }

# Build a sqlite from the same rmem so we can compare report from
# rmem vs report from sqlite, both on PR.
PR_SQLITE=$OUT/from_rmem.sqlite3
rm -f "$PR_SQLITE"
php "$PR/reli" inspector:memory:export-sqlite "$RMEM" "$PR_SQLITE" >/dev/null 2>&1
echo "PR sqlite from rmem: $(stat -c %s "$PR_SQLITE") bytes"

# 0.12.0 doesn't have export-sqlite, so do live-style ingest from a
# fresh capture against the same target.
TARGET=/tmp/sqlite-validation/target_circular_refs.php
start_target() {
    rm -f "$WORK/target.pid"
    php "$TARGET" 4 20 15 </dev/null > "$OUT/target.out" 2> "$OUT/target.err" &
    for _ in $(seq 1 60); do [[ -s "$WORK/target.pid" ]] && break; sleep 0.1; done
    cat "$WORK/target.pid"
}
stop() { kill -TERM "$1" 2>/dev/null; wait "$1" 2>/dev/null || true; }

pid=$(start_target)
echo "target pid=$pid (for 0.12.0 ingest)"
OLD_SQLITE=$OUT/v012.sqlite3
rm -f "$OLD_SQLITE"
php "$OLD/reli" inspector:memory -p "$pid" -f sqlite3 -o "$OLD_SQLITE" >/dev/null 2>&1
echo "0.12.0 sqlite: $(stat -c %s "$OLD_SQLITE") bytes"
stop "$pid"

# Generate text reports
echo
echo "=== text report (PR from rmem) ==="
php "$PR/reli" inspector:memory:report "$RMEM" -f report -o "$OUT/pr_from_rmem.txt" 2>&1 | tail -3
echo "  size=$(stat -c %s "$OUT/pr_from_rmem.txt" 2>/dev/null || echo MISSING)"

echo "=== text report (PR from sqlite) ==="
php "$PR/reli" inspector:memory:report "$PR_SQLITE" -f report -o "$OUT/pr_from_sqlite.txt" 2>&1 | tail -3
echo "  size=$(stat -c %s "$OUT/pr_from_sqlite.txt" 2>/dev/null || echo MISSING)"

echo "=== text report (0.12.0 from sqlite) ==="
php "$OLD/reli" inspector:memory:report "$OLD_SQLITE" -f report -o "$OUT/v012.txt" 2>&1 | tail -3
echo "  size=$(stat -c %s "$OUT/v012.txt" 2>/dev/null || echo MISSING)"

# JSON reports
echo
echo "=== JSON report (PR from rmem) ==="
php "$PR/reli" inspector:memory:report "$RMEM" -f report-json -o "$OUT/pr_from_rmem.json" 2>&1 | tail -3
echo "  size=$(stat -c %s "$OUT/pr_from_rmem.json" 2>/dev/null || echo MISSING)"

echo "=== JSON report (PR from sqlite) ==="
php "$PR/reli" inspector:memory:report "$PR_SQLITE" -f report-json -o "$OUT/pr_from_sqlite.json" 2>&1 | tail -3
echo "  size=$(stat -c %s "$OUT/pr_from_sqlite.json" 2>/dev/null || echo MISSING)"

echo "=== JSON report (0.12.0 from sqlite) ==="
php "$OLD/reli" inspector:memory:report "$OLD_SQLITE" -f report-json -o "$OUT/v012.json" 2>&1 | tail -3
echo "  size=$(stat -c %s "$OUT/v012.json" 2>/dev/null || echo MISSING)"

echo
echo "=== text report: PR rmem vs PR sqlite (should be ~identical) ==="
diff "$OUT/pr_from_rmem.txt" "$OUT/pr_from_sqlite.txt" | head -40 | sed 's/^/  /' || true

echo
echo "=== JSON report top-level structure: PR vs 0.12.0 ==="
python3 /tmp/sqlite-validation/json_compare.py "$OUT/v012.json" "$OUT/pr_from_sqlite.json" 2>&1 | grep -E "^==|≠|only in|differ" | head -40

echo "report_parity done"
